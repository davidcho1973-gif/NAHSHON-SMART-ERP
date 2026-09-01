<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\OpsIntakeItem;
use App\Models\Project;
use App\Models\Site;
use App\Models\WbsItem;
use App\Services\Ops\OpsIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 화면 위쪽 현장이 «전체» 일 때 남의 현장이 섞이지 않는다.
 *
 * 예전에는 «전체» 상태로 카톡을 붙여넣으면 <b>전 현장의 공정 코드</b>가 AI 후보
 * 목록으로 들어갔다. AI 가 남의 현장 코드를 골라도 «지어낸 코드» 검사를 통과하고,
 * 그 항목은 현장이 안 붙은 채 저장돼 반영 단계의 현장 확인마저 통과했다 —
 * A현장 소장이 붙여넣은 글이 B현장(다른 회사) 공정표를 고칠 수 있었다.
 */
class OpsIntakeSiteScopeTest extends TestCase
{
    use RefreshDatabase;

    private Site $mine;

    private Site $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        $a = Company::create(['code' => 'A-CO', 'name' => 'A Co', 'status' => 'active']);
        $b = Company::create(['code' => 'B-CO', 'name' => 'B Co', 'status' => 'active']);

        $this->mine = Site::create(['company_id' => $a->id, 'code' => 'MINE', 'name' => '우리 현장', 'status' => 'active']);
        $this->theirs = Site::create(['company_id' => $b->id, 'code' => 'THEIRS', 'name' => '남의 현장', 'status' => 'active']);

        Project::firstOrCreate(['project_code' => 'A-01'], ['name' => 'A', 'construction_type' => 'equipment_setting']);
        Project::firstOrCreate(['project_code' => 'B-01'], ['name' => 'B', 'construction_type' => 'equipment_setting']);

        WbsItem::create([
            'project_code' => 'A-01', 'site_id' => $this->mine->id, 'level' => 'subtask',
            'wbs_code' => 'A-01-W-A100', 'name' => '우리 배관', 'status' => '진행중', 'progress' => 10,
        ]);
        WbsItem::create([
            'project_code' => 'B-01', 'site_id' => $this->theirs->id, 'level' => 'subtask',
            'wbs_code' => 'B-01-W-B200', 'name' => '남의 배관', 'status' => '진행중', 'progress' => 10,
        ]);
    }

    /** @param array<int, array<string, mixed>> $items */
    private function fakeAi(array $items): void
    {
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'gemini-3.5-flash']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode(['items' => $items])]]]]],
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function reading(string $code): array
    {
        return [
            'raw_text' => '배관 60% 했습니다', 'speaker' => '', 'category' => 'progress', 'confidence' => 95,
            'summary' => '배관 60%', 'target_type' => 'wbs', 'target_code' => $code,
            'target_name' => '배관', 'occurred_on' => '', 'proposed' => ['progress' => 60], 'question' => '',
        ];
    }

    public function test_another_sites_activity_is_never_offered_as_a_target(): void
    {
        // AI 가 남의 현장 코드를 골라도, 그 코드는 이 현장의 후보 목록에 없으므로 버려진다.
        $this->fakeAi([$this->reading('B-01-W-B200')]);

        app(OpsIntakeService::class)->ingest('배관 60% 했습니다', $this->mine);

        $item = OpsIntakeItem::firstOrFail();
        $this->assertNull($item->target_code, '남의 현장 공정에 붙으면 안 된다');
        $this->assertSame('needs_input', $item->status);
    }

    public function test_with_no_site_chosen_nothing_gets_a_target(): void
    {
        // 「전체」 상태에서 현장을 알 수 없는 글이 올라왔다. 후보를 주지 않으므로
        // 어느 현장 공정에도 붙지 않고, 사람에게 한 번 물어본다.
        $this->fakeAi([$this->reading('B-01-W-B200')]);

        app(OpsIntakeService::class)->ingest('배관 60% 했습니다', null);

        $item = OpsIntakeItem::firstOrFail();
        $this->assertNull($item->site_id);
        $this->assertNull($item->target_code);
        $this->assertSame('needs_input', $item->status);
        $this->assertNotEmpty($item->question);
    }

    public function test_with_no_site_chosen_the_text_can_still_name_one(): void
    {
        // "MINE 현장 배관 60%" 처럼 글에 현장이 적혀 있으면 그 현장으로 붙인다 —
        // 영수증·문서함과 같은 판단 규칙 한 벌을 쓴다.
        $this->fakeAi([$this->reading('A-01-W-A100')]);

        app(OpsIntakeService::class)->ingest('MINE 현장 배관 60% 했습니다', null);

        $item = OpsIntakeItem::firstOrFail();
        $this->assertSame($this->mine->id, $item->site_id);
        $this->assertSame('A-01-W-A100', $item->target_code);
        $this->assertSame('pending', $item->status);
    }

    public function test_apply_all_refuses_to_run_across_every_site(): void
    {
        // 「전체 반영」이 전 현장의 대기 항목을 한 번에 반영해 버리면, 되돌리기는
        // 항목 하나씩뿐이라 원상복구가 사실상 불가능하다.
        OpsIntakeItem::create([
            'site_id' => $this->theirs->id, 'source' => 'paste', 'raw_text' => '남의 현장 배관',
            'category' => 'progress', 'confidence' => 95, 'summary' => '남의 배관 60%',
            'target_type' => 'wbs', 'target_code' => 'B-01-W-B200',
            'proposed' => ['progress' => 60], 'status' => 'pending',
        ]);

        $res = app(OpsIntakeService::class)->applyAll(null, null);

        $this->assertFalse($res['success']);
        $this->assertTrue($res['needsSite']);
        $this->assertSame(10, (int) WbsItem::where('wbs_code', 'B-01-W-B200')->value('progress'));
    }
}
