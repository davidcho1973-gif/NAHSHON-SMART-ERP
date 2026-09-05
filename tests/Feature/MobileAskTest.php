<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DocumentQuestion;
use App\Models\Employee;
use App\Models\IntelligentDocument;
use App\Models\Site;
use App\Models\User;
use App\Support\AnthropicChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 앱의 «물어보기» — 관리자가 도면·서류에 대고 묻고, 출처가 붙은 답을 받는다.
 *
 * 대화방의 @AI 와 같은 조회, 같은 권한. 다른 것은 답이 물어본 사람만의 것이고,
 * 근거 문서를 바로 열 수 있고, «문서에 없다» 를 분명히 가른다는 것이다.
 */
class MobileAskTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'ASK-CO', 'name' => 'Ask Co', 'status' => 'active']);
        $this->site = Site::create([
            'company_id' => $this->company->id, 'code' => 'ASK', 'name' => '질문현장',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
    }

    private function manager(string $role = 'site_manager'): User
    {
        $employee = Employee::create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'name' => '소장', 'position' => 'superintendent', 'employment_status' => 'active',
            'employment_type' => Employee::TYPE_STAFF,
        ]);

        return User::factory()->create([
            'employee_id' => $employee->id, 'access_role' => $role, 'account_status' => 'active',
            'access_scope' => 'all_sites',
        ]);
    }

    private function spec(): IntelligentDocument
    {
        return IntelligentDocument::create([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'source' => 'dropzone', 'disk' => 'local', 'file_path' => 'docs/spec.pdf',
            'original_file_name' => '09 6723 Resinous Flooring.pdf', 'stored_file_name' => 'spec.pdf',
            'mime_type' => 'application/pdf', 'extension' => 'pdf', 'file_size' => 1000, 'sha256' => str_repeat('a', 64),
            'title' => '09 6723 수지 바닥재 시방', 'document_type' => 'specification', 'revision' => '2',
            'status' => 'received', 'ai_status' => 'ready',
            'summary' => 'Ucrete 주방 바닥 시방', 'key_facts' => ['양생 대기일 없음 — 제조사 지침에 위임'],
            'search_text' => '09 6723 Resinous Flooring. Ucrete. 양생 대기일 없음. 제조사 지침에 위임한다. green concrete 7~10일 시공 가능.',
        ]);
    }

    /** @param array<string, mixed> $reply */
    private function fakeClaude(array $reply, ?string &$seenPrompt = null): void
    {
        $mock = \Mockery::mock(AnthropicChat::class);
        $mock->shouldReceive('available')->andReturn(true);
        $mock->shouldReceive('model')->andReturn('claude-test');
        $mock->shouldReceive('json')->andReturnUsing(function (array $payload) use ($reply, &$seenPrompt): array {
            $seenPrompt = (string) ($payload['system'] ?? '')."\n".(string) ($payload['messages'][0]['content'] ?? '');

            return $reply;
        });
        $this->app->instance(AnthropicChat::class, $mock);
    }

    public function test_a_manager_asks_and_gets_an_answer_with_an_openable_source(): void
    {
        $doc = $this->spec();
        $user = $this->manager();
        $this->fakeClaude([
            'answer' => 'Ucrete 구역은 시방 09 6723 Rev.2 기준 양생 대기일이 없습니다 · 제조사 지침(green concrete 7~10일) 을 따릅니다.',
            'found' => true,
            'sources' => [$doc->id],
        ], $prompt);

        $res = $this->actingAs($user)->postJson(route('ask.question'), ['question' => '주방 바닥 양생 며칠이야?']);

        $res->assertOk()->assertJson(['success' => true, 'found' => true]);
        $this->assertStringContainsString('09 6723', $res->json('answer'));

        $source = $res->json('sources.0');
        $this->assertSame($doc->id, $source['document_id']);
        $this->assertSame('09 6723 수지 바닥재 시방', $source['title']);
        $this->assertTrue($source['can_open'], '현장소장은 문서함을 볼 수 있으니 출처를 바로 연다');
        $this->assertStringContainsString('/document-hub/documents/'.$doc->id.'/preview', $source['url']);

        // 조회한 사실이 AI 에게 실제로 갔는가 — 문서ID 가 함께 실려야 출처를 돌려줄 수 있다.
        $this->assertStringContainsString('"문서ID": '.$doc->id, $prompt);
        $this->assertStringContainsString('지어내지 마세요', $prompt);

        // 물어본 것이 남는다 — 같은 것을 다시 묻지 않아도 된다.
        $row = DocumentQuestion::query()->sole();
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame($this->site->id, $row->site_id);
        $this->assertTrue($row->found);
    }

    public function test_a_source_the_ai_invents_is_dropped(): void
    {
        // AI 가 조회한 적 없는 문서 번호를 근거라고 말하면 버린다. 없는 문서를 «출처» 로
        // 열어 보라고 하면 그 버튼이 404 이거나, 더 나쁘게는 남의 문서다.
        $doc = $this->spec();
        $user = $this->manager();
        $this->fakeClaude(['answer' => '답', 'found' => true, 'sources' => [$doc->id, 99999]]);

        $res = $this->actingAs($user)->postJson(route('ask.question'), ['question' => '양생 며칠이야?']);

        $this->assertCount(1, $res->json('sources'));
        $this->assertSame($doc->id, $res->json('sources.0.document_id'));
    }

    public function test_when_the_documents_do_not_have_it_the_screen_is_told_so(): void
    {
        $user = $this->manager();
        $this->fakeClaude(['answer' => '등록된 문서에서 확인되지 않습니다. 배기 덕트 시방(23 3113)을 올리면 답할 수 있습니다.', 'found' => false, 'sources' => []]);

        $res = $this->actingAs($user)->postJson(route('ask.question'), ['question' => '배기 덕트 두께 얼마야?']);

        $res->assertOk()->assertJson(['success' => true, 'found' => false, 'sources' => []]);
        $this->assertFalse(DocumentQuestion::query()->sole()->found);
    }

    public function test_a_worker_gets_the_answer_but_cannot_open_the_source(): void
    {
        // 조회 규칙은 대화방과 같다 — 작업자도 시방 사실은 들을 수 있다. 다만 문서함
        // 원본은 관리자만 여는 곳이라 출처는 «열람 권한 없음» 으로 보인다. 숨기지 않는다.
        $doc = $this->spec();
        $worker = $this->manager('worker');
        $this->fakeClaude(['answer' => '양생 대기일 없음', 'found' => true, 'sources' => [$doc->id]]);

        $res = $this->actingAs($worker)->postJson(route('ask.question'), ['question' => '양생 며칠이야?']);

        $res->assertOk();
        $this->assertFalse($res->json('sources.0.can_open'));
        $this->assertNull($res->json('sources.0.url'));
    }

    public function test_money_questions_from_someone_without_finance_rights_are_marked_denied(): void
    {
        $worker = $this->manager('worker');
        $this->fakeClaude(['answer' => '권한이 없어 알려드릴 수 없습니다.', 'found' => false, 'sources' => []], $prompt);

        $res = $this->actingAs($worker)->postJson(route('ask.question'), ['question' => '이번 달 경비 얼마 썼어?']);

        $this->assertNotEmpty($res->json('denied'));
        $this->assertStringContainsString('권한으로는 볼 수 없어', $prompt);
    }

    public function test_recent_questions_are_mine_only(): void
    {
        $me = $this->manager();
        $other = $this->manager();
        DocumentQuestion::create(['user_id' => $other->id, 'question' => '남의 질문', 'answer' => 'x', 'found' => true]);
        DocumentQuestion::create(['user_id' => $me->id, 'question' => '내 질문', 'answer' => 'y', 'found' => true]);
        config(['services.anthropic.api_key' => 'test-key']);

        $res = $this->actingAs($me)->get(route('attendance-app.ask'));

        $res->assertOk()->assertSee('내 질문')->assertDontSee('남의 질문')->assertSee('id="go"', false);
    }

    public function test_the_screen_says_when_the_assistant_is_off(): void
    {
        config(['services.anthropic.api_key' => '']);

        $this->actingAs($this->manager())->get(route('attendance-app.ask'))
            ->assertOk()->assertSee('켜져 있지 않습니다')->assertDontSee('id="go"', false);

        $this->actingAs($this->manager())->postJson(route('ask.question'), ['question' => '뭐야'])
            ->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_the_home_screen_has_the_ask_tile(): void
    {
        $this->actingAs($this->manager())->get(route('attendance-app.index'))
            ->assertOk()->assertSee(route('attendance-app.ask'))->assertSee('qAsk');
    }

    public function test_it_needs_a_login(): void
    {
        $this->get(route('attendance-app.ask'))->assertRedirect(route('login'));
        $this->postJson(route('ask.question'), ['question' => 'x'])->assertStatus(401);
    }
}
