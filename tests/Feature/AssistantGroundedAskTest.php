<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DocumentQuestion;
use App\Models\Employee;
use App\Models\MaterialReceipt;
use App\Models\PayApplication;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\ProjectContract;
use App\Models\Site;
use App\Models\User;
use App\Services\Documents\DocumentAsk;
use App\Support\AiInformationAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Exercise the real Ask -> fact finder -> scoped PostgreSQL read -> provider payload. */
class AssistantGroundedAskTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Company $otherCompany;

    private Site $site;

    private Site $otherSite;

    private Site $foreignSite;

    private array $providerPayloads = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'ai_assistant.enabled' => true,
            'services.anthropic.api_key' => 'synthetic-grounded-ask-test-key',
            'services.anthropic.endpoint' => 'https://api.anthropic.com',
            'services.gemini.api_key' => '',
        ]);
        $this->company = Company::create(['code' => 'ASK-A', 'name' => 'Synthetic Ask A', 'status' => 'active']);
        $this->otherCompany = Company::create(['code' => 'ASK-B', 'name' => 'Synthetic Ask B', 'status' => 'active']);
        $this->site = Site::create(['company_id' => $this->company->id, 'code' => 'ASK-A1', 'name' => 'Selected site', 'status' => 'active']);
        $this->otherSite = Site::create(['company_id' => $this->company->id, 'code' => 'ASK-A2', 'name' => 'Other site', 'status' => 'active']);
        $this->foreignSite = Site::create(['company_id' => $this->otherCompany->id, 'code' => 'ASK-B1', 'name' => 'Foreign company site', 'status' => 'active']);
    }

    public static function datasets(): array
    {
        return [
            'receiving' => ['material_receipts', '재고 입고 내역 알려줘', '입고 대장(재고 잔량 아님)', 'vendor', 'site_manager'],
            'payroll' => ['payslips', '급여 명세 알려줘', '급여 명세', 'snap_trade', 'payroll'],
            'claims' => ['pay_applications', '기성 청구 내역 알려줘', '기성 청구', 'internal_reference', 'payroll'],
        ];
    }

    public static function financeQuestions(): array
    {
        return [
            'payroll' => ['payslips', '급여 명세 알려줘'],
            'claims' => ['pay_applications', '기성 청구 내역 알려줘'],
        ];
    }

    private function actor(string $role = 'super_admin'): User
    {
        $user = User::factory()->create([
            'access_role' => $role, 'account_status' => 'active',
            'access_scope' => $role === 'super_admin' ? 'all_sites' : 'site',
            'allowed_company_id' => $this->company->id, 'allowed_site_id' => $this->site->id,
        ]);
        $user->companies()->attach($this->company->id);

        return $user;
    }

    /** Synthetic stored rows, with no document/account provisioning or external services. */
    private function record(string $dataset, Site $site, string $marker, ?Company $company = null): Model
    {
        $companyId = $company?->id ?? $site->company_id;
        if ($dataset === 'material_receipts') {
            return MaterialReceipt::create([
                'company_id' => $companyId, 'site_id' => $site->id, 'received_on' => '2026-10-01',
                'vendor' => $marker, 'delivery_no' => $marker, 'status' => 'confirmed',
            ]);
        }
        if ($dataset === 'payslips') {
            $employee = Employee::withoutEvents(fn () => Employee::create([
                'company_id' => $companyId, 'site_id' => $site->id,
                'employee_number' => $marker, 'name' => $marker, 'employment_status' => 'active',
            ]));
            $run = PayrollRun::create([
                'code' => $marker, 'period_start' => '2026-09-21', 'period_end' => '2026-10-04',
                'site_scope' => 'ALL', 'status' => 'calculated', 'headcount' => 1,
                'total_gross' => 1234.56, 'total_net' => 987.65,
            ]);
            $slip = Payslip::create([
                'payroll_run_id' => $run->id, 'employee_id' => $employee->id, 'company_id' => $companyId,
                'snap_trade' => $marker, 'snap_pay_type' => 'hourly', 'snap_base_rate' => 25,
                'gross_pay' => 1234.56, 'net_pay' => 987.65, 'currency' => 'USD', 'status' => 'calculated',
            ]);
            $slip->lines()->create(['site_id' => $site->id, 'hour_type' => 'REG', 'hours' => 4, 'rate_applied' => 25, 'amount' => 100]);

            return $slip;
        }
        $contract = ProjectContract::create([
            'company_id' => $companyId, 'site_id' => $site->id,
            'internal_reference' => 'C-'.$marker, 'title' => $marker, 'status' => 'active', 'currency' => 'USD',
        ]);

        return PayApplication::create([
            'project_contract_id' => $contract->id, 'company_id' => $companyId, 'site_id' => $site->id,
            'internal_reference' => $marker, 'application_no' => 1, 'type' => 'progress', 'status' => 'submitted',
            'period_end' => '2026-10-01', 'this_period_amount' => 1234.56, 'amount_due' => 987.65,
        ]);
    }

    private function fakeProvider(string $label, string $field, ?callable $duringResponse = null): void
    {
        Http::fake(['https://api.anthropic.com/v1/messages' => function (Request $request) use ($label, $field, $duringResponse) {
            $payload = $request->data();
            $this->providerPayloads[] = $payload;
            $facts = $this->factsFromPayload($payload);
            $rows = $facts[$label]['목록'] ?? [];
            if ($duringResponse !== null) {
                $duringResponse();
            }
            // The mock can only echo rows that really reached the provider boundary.
            $answer = $rows === [] ? AiInformationAccess::DENIED : '확인된 기록: '.implode(', ', array_column($rows, $field));

            return Http::response([
                'content' => [['type' => 'text', 'text' => json_encode([
                    'answer' => $answer, 'found' => $rows !== [], 'sources' => [],
                ], JSON_UNESCAPED_UNICODE)]],
                'stop_reason' => 'end_turn',
            ]);
        }]);
    }

    private function factsFromPayload(array $payload): array
    {
        $content = $payload['messages'][0]['content'] ?? '';
        $this->assertSame(1, preg_match('/^\[조회한 사실\]\n\n(.*?)\n\n\[질문\]/su', $content, $matches));

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    }

    private function moveOutsideSelection(string $dataset, Model $record): void
    {
        if ($dataset === 'payslips') {
            $record->lines()->update(['site_id' => $this->otherSite->id]);
        } else {
            $record->update(['site_id' => $this->otherSite->id]);
        }
    }

    #[DataProvider('datasets')]
    public function test_authorized_selected_site_records_reach_the_provider_and_foreign_rows_do_not(string $dataset, string $question, string $label, string $field, string $role): void
    {
        $mine = $this->record($dataset, $this->site, 'ASK_VISIBLE');
        $this->record($dataset, $this->otherSite, 'ASK_OTHER_SITE');
        $this->record($dataset, $this->foreignSite, 'ASK_FOREIGN_COMPANY');
        // A forged company/site pairing must not weaken the company boundary.
        $this->record($dataset, $this->site, 'ASK_WRONG_COMPANY', $this->otherCompany);
        if ($dataset === 'payslips') {
            $mixed = $this->record($dataset, $this->site, 'ASK_MIXED_PAYSLIP');
            $mixed->lines()->create(['site_id' => $this->otherSite->id, 'hour_type' => 'REG', 'hours' => 4, 'rate_applied' => 25, 'amount' => 100]);
        }
        if ($dataset === 'pay_applications') {
            $wrongParent = $this->record($dataset, $this->foreignSite, 'ASK_FOREIGN_CONTRACT');
            $wrongParent->update(['company_id' => $this->company->id, 'site_id' => $this->site->id]);
        }
        $this->fakeProvider($label, $field);

        // A system administrator could use every site, but explicit selection must narrow the facts.
        $response = $this->actingAsPurchaseUser($this->actor())->postJson(route('ask.question'), [
            'question' => $question, 'site_id' => $this->site->id,
        ])->assertOk()->assertJson(['success' => true, 'found' => true, 'sources' => []]);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->hasHeader('x-api-key', 'synthetic-grounded-ask-test-key'));
        $this->assertArrayNotHasKey('tools', $this->providerPayloads[0], 'Ask provides scoped facts, not a general model tool caller.');
        $facts = $this->factsFromPayload($this->providerPayloads[0]);
        $this->assertSame($dataset, $facts[$label]['자료']);
        $this->assertSame([$mine->id], array_column($facts[$label]['목록'], 'id'));
        $this->assertSame('ASK_VISIBLE', $facts[$label]['목록'][0][$field]);
        $this->assertSame($this->company->id, $facts[$label]['목록'][0]['company_id']);
        $this->assertStringContainsString('ASK_VISIBLE', $response->json('answer'));
        foreach (['ASK_OTHER_SITE', 'ASK_FOREIGN_COMPANY', 'ASK_WRONG_COMPANY', 'ASK_MIXED_PAYSLIP', 'ASK_FOREIGN_CONTRACT'] as $marker) {
            $this->assertStringNotContainsString($marker, json_encode($this->providerPayloads));
            $this->assertStringNotContainsString($marker, $response->json('answer'));
        }
        if ($dataset !== 'material_receipts') {
            $this->assertSame(987.65, (float) $facts[$label]['목록'][0][$dataset === 'payslips' ? 'net_pay' : 'amount_due']);
        }
        $this->assertSame($this->site->id, DocumentQuestion::query()->sole()->site_id);
    }

    #[DataProvider('datasets')]
    public function test_site_limited_actor_cannot_request_foreign_site_or_company(string $dataset, string $question, string $label, string $field, string $role): void
    {
        $this->record($dataset, $this->otherSite, 'ASK_DENIED_SITE');
        $this->record($dataset, $this->foreignSite, 'ASK_DENIED_COMPANY');
        $this->fakeProvider($label, $field);
        $this->actingAsPurchaseUser($this->actor($role));

        foreach ([$this->otherSite, $this->foreignSite] as $site) {
            $this->postJson(route('ask.question'), ['question' => $question, 'site_id' => $site->id])->assertForbidden();
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('document_questions', 0);
        $this->assertDatabaseCount('ai_assistant_requests', 0);
    }

    #[DataProvider('financeQuestions')]
    public function test_worker_finance_question_is_denied_before_any_provider_request(string $dataset, string $question): void
    {
        $this->record($dataset, $this->site, 'ASK_WORKER_SECRET');
        Http::fake();

        $this->actingAsPurchaseUser($this->actor('worker'))->postJson(route('ask.question'), [
            'question' => $question, 'site_id' => $this->site->id,
        ])->assertOk()->assertJson([
            'success' => true, 'found' => false, 'sources' => [], 'answer' => AiInformationAccess::DENIED,
            'denied' => [AiInformationAccess::DENIED],
        ]);

        Http::assertNothingSent();
        $this->assertStringNotContainsString('ASK_WORKER_SECRET', DocumentQuestion::query()->sole()->answer);
        $this->assertDatabaseCount('ai_assistant_requests', 0);
    }

    public function test_worker_billing_topic_is_blocked_by_dataset_permission_even_without_a_financial_keyword(): void
    {
        $this->record('pay_applications', $this->site, 'ASK_BILLING_SECRET');
        $question = 'billing status';
        $this->assertFalse(AiInformationAccess::financial($question), 'Exercise the dataset guard independently of the keyword guard.');
        $this->fakeProvider('기성 청구', 'internal_reference');

        $response = $this->actingAsPurchaseUser($this->actor('worker'))->postJson(route('ask.question'), [
            'question' => $question, 'site_id' => $this->site->id,
        ])->assertOk()->assertJson(['success' => true, 'found' => false, 'sources' => [], 'denied' => [AiInformationAccess::DENIED]]);

        Http::assertSentCount(1);
        $facts = $this->factsFromPayload($this->providerPayloads[0]);
        $this->assertArrayNotHasKey('기성 청구', $facts);
        $this->assertStringNotContainsString('ASK_BILLING_SECRET', json_encode($this->providerPayloads));
        $this->assertStringNotContainsString('ASK_BILLING_SECRET', $response->json('answer'));
        $this->assertStringContainsString(AiInformationAccess::DENIED, $this->providerPayloads[0]['system']);
    }

    #[DataProvider('datasets')]
    public function test_thirteen_records_are_a_labeled_twelve_row_sample_not_an_aggregate(string $dataset, string $question, string $label, string $field, string $role): void
    {
        $ids = [];
        for ($i = 1; $i <= 13; $i++) {
            $ids[] = $this->record($dataset, $this->site, sprintf('ASK_SAMPLE_%02d', $i))->id;
        }
        $this->fakeProvider($label, $field);

        $response = $this->actingAsPurchaseUser($this->actor($role))->postJson(route('ask.question'), [
            'question' => $question, 'site_id' => $this->site->id,
        ])->assertOk()->assertJson(['success' => true, 'found' => true]);

        Http::assertSentCount(1);
        $facts = $this->factsFromPayload($this->providerPayloads[0]);
        $sample = $facts[$label];
        $this->assertSame(array_slice($ids, 0, 12), array_column($sample['목록'], 'id'));
        $this->assertTrue($sample['일부 자료만 조회']);
        $this->assertSame(12, $sample['조회건수']);
        $this->assertSame(12, $sample['최대조회건수']);
        $this->assertArrayHasKey('조회범위', $sample);
        $this->assertArrayHasKey('정렬', $sample);
        $this->assertStringContainsString('전체 합계를 추정하지 마세요', $sample['주의']);
        $this->assertStringNotContainsString('ASK_SAMPLE_13', json_encode($this->providerPayloads));
        $this->assertStringNotContainsString('ASK_SAMPLE_13', $response->json('answer'));
        $this->assertStringContainsString('조회된 ERP 기록 최대 12건', $response->json('answer'));
        $this->assertStringContainsString('전체 합계가 아닙니다', $response->json('answer'));
        if ($dataset === 'material_receipts') {
            $this->assertStringContainsString('재고 잔량', $response->json('answer'));
        }
    }

    #[DataProvider('datasets')]
    public function test_source_movement_during_provider_response_rejects_saving_the_answer(string $dataset, string $question, string $label, string $field, string $role): void
    {
        $row = $this->record($dataset, $this->site, 'ASK_MOVED_DURING_RESPONSE');
        $this->fakeProvider($label, $field, fn () => $this->moveOutsideSelection($dataset, $row));

        $this->actingAsPurchaseUser($this->actor($role))->postJson(route('ask.question'), [
            'question' => $question, 'site_id' => $this->site->id,
        ])->assertStatus(422)->assertJson([
            'success' => false, 'error' => '근거 ERP 기록의 열람 범위가 변경되었습니다. 다시 질문해 주세요.',
        ]);

        Http::assertSentCount(1);
        $facts = $this->factsFromPayload($this->providerPayloads[0]);
        $this->assertSame([$row->id], array_column($facts[$label]['목록'], 'id'));
        $this->assertDatabaseCount('document_questions', 0);
    }

    #[DataProvider('datasets')]
    public function test_source_movement_after_saving_hides_the_answer_from_recent_history(string $dataset, string $question, string $label, string $field, string $role): void
    {
        $row = $this->record($dataset, $this->site, 'ASK_MOVED_AFTER_RESPONSE');
        $actor = $this->actor($role);
        $this->fakeProvider($label, $field);
        $this->actingAsPurchaseUser($actor)->postJson(route('ask.question'), [
            'question' => $question, 'site_id' => $this->site->id,
        ])->assertOk()->assertJson(['success' => true, 'found' => true]);

        $saved = DocumentQuestion::query()->sole();
        $this->assertEquals([[
            'dataset' => $dataset, 'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'ids' => [$row->id], 'limit' => 12, 'count' => 1, 'has_more' => false,
        ]], $saved->source_erp_records);
        $this->assertCount(1, app(DocumentAsk::class)->recent($actor));

        $this->moveOutsideSelection($dataset, $row);

        $this->assertSame([], app(DocumentAsk::class)->recent($actor));
        $this->assertSame($saved->answer, $saved->fresh()->answer, 'Reauthorization hides historical answers without rewriting the saved record.');
        Http::assertSentCount(1);
    }

    #[DataProvider('datasets')]
    public function test_company_membership_revocation_during_provider_response_rejects_saving(string $dataset, string $question, string $label, string $field, string $role): void
    {
        $this->record($dataset, $this->site, 'ASK_REVOKED_DURING_RESPONSE');
        $actor = $this->actor($role);
        $this->fakeProvider($label, $field, fn () => $actor->companies()->detach($this->company->id));

        $this->actingAsPurchaseUser($actor)->postJson(route('ask.question'), [
            'question' => $question, 'site_id' => $this->site->id,
        ])->assertStatus(422)->assertJson([
            'success' => false, 'error' => '근거 ERP 기록의 열람 범위가 변경되었습니다. 다시 질문해 주세요.',
        ]);

        Http::assertSentCount(1);
        $this->assertDatabaseCount('document_questions', 0);
    }

    #[DataProvider('datasets')]
    public function test_company_membership_revocation_hides_saved_answer_without_another_provider_request(string $dataset, string $question, string $label, string $field, string $role): void
    {
        $this->record($dataset, $this->site, 'ASK_REVOKED_AFTER_RESPONSE');
        $actor = $this->actor($role);
        $this->fakeProvider($label, $field);
        $this->actingAsPurchaseUser($actor)->postJson(route('ask.question'), [
            'question' => $question, 'site_id' => $this->site->id,
        ])->assertOk()->assertJson(['success' => true, 'found' => true]);
        $this->assertCount(1, app(DocumentAsk::class)->recent($actor));

        $actor->companies()->detach($this->company->id);

        $this->assertSame([], app(DocumentAsk::class)->recent($actor));
        $this->assertDatabaseCount('document_questions', 1);
        Http::assertSentCount(1);
    }

    #[DataProvider('datasets')]
    public function test_stale_actor_cannot_reuse_history_after_role_change(string $dataset, string $question, string $label, string $field, string $role): void
    {
        $this->record($dataset, $this->site, 'ASK_STALE_ROLE');
        $actor = $this->actor($role);
        $this->fakeProvider($label, $field);
        $this->actingAsPurchaseUser($actor)->postJson(route('ask.question'), [
            'question' => $question, 'site_id' => $this->site->id,
        ])->assertOk()->assertJson(['success' => true]);
        $this->assertCount(1, app(DocumentAsk::class)->recent($actor));

        User::whereKey($actor->id)->update(['access_role' => $role === 'payroll' ? 'site_manager' : 'worker']);

        $this->assertSame($role, $actor->access_role, 'The service receives the old in-memory actor.');
        $this->assertSame([], app(DocumentAsk::class)->recent($actor));
        Http::assertSentCount(1);
    }

    public function test_deleted_actor_cannot_read_history_or_request_a_provider_call(): void
    {
        $this->record('pay_applications', $this->site, 'ASK_DELETED_ACTOR');
        $actor = $this->actor('payroll');
        $this->fakeProvider('기성 청구', 'internal_reference');
        $this->actingAsPurchaseUser($actor)->postJson(route('ask.question'), [
            'question' => '기성 청구 내역 알려줘', 'site_id' => $this->site->id,
        ])->assertOk()->assertJson(['success' => true]);
        User::whereKey($actor->id)->delete();

        $this->assertSame([], app(DocumentAsk::class)->recent($actor));
        $this->assertFalse(app(DocumentAsk::class)->ask($actor, '기성 청구 내역 알려줘', $this->site)['success']);
        Http::assertSentCount(1);
    }

    public function test_mixed_topics_disclose_the_sample_limit_per_dataset(): void
    {
        foreach (self::datasets() as [$dataset]) {
            for ($i = 1; $i <= 13; $i++) {
                $this->record($dataset, $this->site, 'ASK_MIXED_'.$dataset.'_'.$i);
            }
        }
        $this->fakeProvider('기성 청구', 'internal_reference');
        $response = $this->actingAsPurchaseUser($this->actor())->postJson(route('ask.question'), [
            'question' => '재고 입고, 급여, 기성 청구 알려줘', 'site_id' => $this->site->id,
        ])->assertOk()->assertJson(['success' => true]);

        $facts = $this->factsFromPayload($this->providerPayloads[0]);
        foreach (self::datasets() as [$dataset, $question, $label]) {
            $this->assertCount(12, $facts[$label]['목록']);
            $this->assertTrue($facts[$label]['일부 자료만 조회']);
        }
        $this->assertStringContainsString('자료 종류별로 조회된 ERP 기록 최대 12건', $response->json('answer'));
        $this->assertStringContainsString('재고 잔량이 아닙니다', $response->json('answer'));
        $this->assertCount(3, DocumentQuestion::query()->sole()->source_erp_records);
        Http::assertSentCount(1);
    }
}
