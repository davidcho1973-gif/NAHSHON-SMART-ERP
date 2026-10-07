<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DocumentQuestion;
use App\Models\Employee;
use App\Models\IntelligentDocument;
use App\Models\Site;
use App\Models\User;
use App\Services\Documents\DocumentAsk;
use App\Support\AiAssistantBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class DocumentAskCompanyScopeTest extends TestCase
{
    use RefreshDatabase;

    public static function roles(): array
    {
        return [['hr_manager'], ['payroll'], ['site_manager']];
    }

    private function fixture(string $role): array
    {
        Http::preventStrayRequests();
        config(['ai_assistant.enabled' => true, 'services.anthropic.api_key' => 'synthetic-company-revocation-key', 'services.anthropic.endpoint' => 'https://api.anthropic.com', 'services.gemini.api_key' => '']);
        $a = Company::create(['code' => 'REV-A', 'name' => 'Synthetic Company A', 'status' => 'active']);
        $b = Company::create(['code' => 'REV-B', 'name' => 'Synthetic Company B', 'status' => 'active']);
        $siteA = Site::create(['company_id' => $a->id, 'code' => 'REV-A-S', 'name' => 'Site A', 'status' => 'active']);
        $siteB = Site::create(['company_id' => $b->id, 'code' => 'REV-B-S', 'name' => 'Site B', 'status' => 'active']);
        $employee = Employee::withoutEvents(fn () => Employee::create(['company_id' => $a->id, 'site_id' => $siteA->id, 'employee_number' => 'REV-EMP', 'name' => 'Synthetic employee', 'employment_status' => 'active']));
        $actor = User::factory()->create(['employee_id' => $employee->id, 'account_status' => 'active', 'access_role' => $role, 'access_scope' => 'all_sites', 'allowed_company_id' => $a->id]);
        $actor->companies()->attach([$a->id, $b->id]);
        $doc = IntelligentDocument::create(['uuid' => (string) Str::uuid(), 'company_id' => $b->id, 'site_id' => $siteB->id, 'source' => 'dropzone', 'disk' => 'local', 'file_path' => 'synthetic.pdf', 'original_file_name' => 'synthetic.pdf', 'stored_file_name' => 'synthetic.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'file_size' => 10, 'sha256' => hash('sha256', 'synthetic company revocation'), 'title' => 'SPEC_REVOCATION_B', 'document_type' => 'specification', 'confidentiality' => 'internal', 'status' => 'received', 'ai_status' => 'ready', 'search_text' => 'specification SPEC_REVOCATION_B technical detail']);

        return [$actor, $b, $siteB, $doc];
    }

    private function provider(IntelligentDocument $doc, ?callable $during = null): void
    {
        Http::fake(['https://api.anthropic.com/v1/messages' => function ($request) use ($doc, $during) {
            $this->assertStringContainsString('SPEC_REVOCATION_B', $request['messages'][0]['content']);
            if ($during) {
                $during();
            }

            return Http::response(['content' => [['type' => 'text', 'text' => json_encode(['answer' => 'SPEC_REVOCATION_B detail', 'found' => true, 'sources' => [$doc->id]])]], 'stop_reason' => 'end_turn']);
        }]);
    }

    private function assertRevoked(User $actor, Company $company): void
    {
        $current = $actor->fresh(['employee']);
        $this->assertFalse($current->canAccessCompany($company->id));
        $this->assertNull(app(AiAssistantBudget::class)->companyId($current, $company->id));
    }

    #[DataProvider('roles')]
    public function test_history_rejects_membership_only_company_after_revocation(string $role): void
    {
        [$actor,$b,$site,$doc] = $this->fixture($role);
        $this->provider($doc);
        $result = app(DocumentAsk::class)->ask($actor, '문서 specification detail 알려줘', $site);
        $this->assertTrue($result['success']);
        $saved = DocumentQuestion::query()->sole();
        $this->assertContains($doc->id, $saved->source_document_ids);
        $this->assertSame([], $saved->source_erp_records);
        $actor->companies()->detach($b->id);
        $this->assertRevoked($actor, $b);
        $history = app(DocumentAsk::class)->recent($actor);
        Http::assertSentCount(1);
        $this->assertSame([], $history, 'No direct or employee grant to B remains after B membership removal.');
    }

    #[DataProvider('roles')]
    public function test_membership_removed_during_response_rejects_save_and_return(string $role): void
    {
        [$actor,$b,$site,$doc] = $this->fixture($role);
        $this->provider($doc, fn () => $actor->companies()->detach($b->id));
        $result = app(DocumentAsk::class)->ask($actor, '문서 specification detail 알려줘', $site);
        $this->assertRevoked($actor, $b);
        Http::assertSentCount(1);
        $this->assertFalse($result['success'], 'Membership was revoked before DocumentAsk post-provider authorization.');
        $this->assertDatabaseCount('document_questions', 0);
    }

    #[DataProvider('roles')]
    public function test_initial_question_requires_a_current_company_grant(string $role): void
    {
        [$actor,$company,$site,$doc] = $this->fixture($role);
        $actor->companies()->detach($company->id);
        $this->provider($doc);
        $this->assertRevoked($actor, $company);
        $result = app(DocumentAsk::class)->ask($actor, '문서 specification detail 알려줘', $site);
        $this->assertFalse($result['success']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('document_questions', 0);
        $this->assertDatabaseCount('ai_assistant_requests', 0);
    }

    #[DataProvider('roles')]
    public function test_current_direct_company_assignment_preserves_legacy_document_access(string $role): void
    {
        [$actor,$company,$site,$doc] = $this->fixture($role);
        $actor->update(['allowed_company_id' => $company->id]);
        $actor->companies()->detach($company->id);
        $this->provider($doc);
        $result = app(DocumentAsk::class)->ask($actor, '문서 specification detail 알려줘', $site);
        $this->assertTrue($result['success']);
        $this->assertCount(1, app(DocumentAsk::class)->recent($actor));
        Http::assertSentCount(1);
    }

    #[DataProvider('roles')]
    public function test_current_employee_company_assignment_preserves_legacy_document_access(string $role): void
    {
        [$actor,$company,$site,$doc] = $this->fixture($role);
        Employee::whereKey($actor->employee_id)->update(['company_id' => $company->id, 'site_id' => $site->id]);
        $actor->companies()->detach($company->id);
        $this->provider($doc);
        $result = app(DocumentAsk::class)->ask($actor, '문서 specification detail 알려줘', $site);
        $this->assertTrue($result['success']);
        $this->assertCount(1, app(DocumentAsk::class)->recent($actor));
        Http::assertSentCount(1);
    }

    public static function systemRoles(): array
    {
        return [['admin'], ['super_admin']];
    }

    #[DataProvider('systemRoles')]
    public function test_system_roles_keep_access_without_company_membership(string $role): void
    {
        [$actor,$company,$site,$doc] = $this->fixture($role);
        $actor->companies()->detach($company->id);
        $this->provider($doc);
        $result = app(DocumentAsk::class)->ask($actor, '문서 specification detail 알려줘', $site);
        $this->assertTrue($result['success']);
        $this->assertCount(1, app(DocumentAsk::class)->recent($actor));
        Http::assertSentCount(1);
    }

    public function test_site_company_reassignment_during_response_requires_a_new_question(): void
    {
        [$actor,$company,$site,$doc] = $this->fixture('super_admin');
        $this->provider($doc, fn () => $site->update(['company_id' => $actor->allowed_company_id]));
        $result = app(DocumentAsk::class)->ask($actor, '문서 specification detail 알려줘', $site);
        $this->assertFalse($result['success']);
        $this->assertDatabaseCount('document_questions',0);
        Http::assertSentCount(1);
    }
}
