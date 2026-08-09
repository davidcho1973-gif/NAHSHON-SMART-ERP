<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\SafetyWorkItem;
use App\Models\Site;
use App\Models\User;
use App\Models\WbsItem;
use App\Services\Wbs\WbsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/**
 * 공정표 교체와 행 사이 삽입.
 *
 * 교체는 되돌리기 어렵다 — 기존 트리와 딸린 안전카드가 지워진다. 그런데 엑셀 헤더가
 * 하나만 달라도 액티비티가 0 개로 읽힌다. 그 상태로 교체하면 공정표가 통째로 비어 버린다.
 * 그래서 "잘 되는 경우" 보다 "잘못됐을 때 기존 것이 살아남는가" 를 더 많이 본다.
 */
class WbsScheduleReplaceTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Project $project;

    private const CODE = 'LGES-AZ-2026-002';

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::create([
            'code' => 'AZ-01', 'name' => 'LG PHOENIX',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        $this->project = Project::create([
            'site_id' => $this->site->id,
            'project_code' => self::CODE,
            'name' => 'PHOENIX 2공장',
            'construction_type' => 'electrical',
        ]);
    }

    private function user(string $role, array $extra = []): User
    {
        return User::factory()->create(array_merge([
            'access_role' => $role, 'access_scope' => 'all_sites', 'account_status' => 'active',
        ], $extra));
    }

    /** 기존 공정표 한 그루 — Stage → Task → SubTask 3개. */
    private function seedTree(): WbsItem
    {
        $stage = WbsItem::create([
            'project_id' => $this->project->id, 'project_code' => self::CODE, 'site_id' => $this->site->id,
            'level' => 'stage', 'wbs_code' => self::CODE.'-S-1', 'node_no' => '1',
            'name' => '슬래브 폐합', 'sort_order' => 1,
        ]);
        $task = WbsItem::create([
            'project_id' => $this->project->id, 'project_code' => self::CODE, 'site_id' => $this->site->id,
            'parent_id' => $stage->id, 'level' => 'task', 'wbs_code' => self::CODE.'-T-1', 'node_no' => '1.2',
            'name' => 'ELEC', 'trade' => 'ELEC', 'sort_order' => 2,
        ]);

        $first = null;
        foreach ([['1.2.1', 'A010', '전선관 매입'], ['1.2.2', 'A020', '박스 설치'], ['1.2.3', 'A030', '입선']] as $i => [$no, $act, $name]) {
            $sub = WbsItem::create([
                'project_id' => $this->project->id, 'project_code' => self::CODE, 'site_id' => $this->site->id,
                'parent_id' => $task->id, 'level' => 'subtask',
                'wbs_code' => self::CODE.'-W-'.$act, 'node_no' => $no, 'activity_id' => $act,
                'name' => $name, 'trade' => 'ELEC', 'sort_order' => $i + 1,
                'planned_start' => '2026-08-0'.($i + 1), 'planned_end' => '2026-08-0'.($i + 2),
            ]);
            $first ??= $sub;
        }

        return $first;
    }

    private function xlsx(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sched').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return new UploadedFile($path, 'schedule.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function goodSheet(): UploadedFile
    {
        // 실제 공정표 형식: 헤더 → 액티비티들 → "마일스톤" 구분 행 → 마일스톤들.
        // 마일스톤 행이 위에 오면 그 아래가 전부 마일스톤으로 읽힌다.
        return $this->xlsx([
            ['ID', '작업명', '공기(일)', 'ES일자', 'EF일자', '공종'],
            ['A010', '전선관 매입 (개정)', 3, '2026-08-01', '2026-08-03', 'ELEC'],
            ['A020', '박스 설치 (개정)', 2, '2026-08-04', '2026-08-05', 'ELEC'],
            ['마일스톤'],
            ['', '슬래브 폐합', '', '', '', '2026-08-10'],
        ]);
    }

    // ── 미리보기 ────────────────────────────────────────────────────────

    public function test_the_preview_reads_without_touching_anything(): void
    {
        Storage::fake('local');
        $this->seedTree();
        $before = WbsItem::count();
        $this->actingAs($this->user('admin'));

        $res = $this->post('/wbs-api/schedule/preview', [
            'schedule' => $this->goodSheet(), 'project_code' => self::CODE,
        ])->assertOk();

        $this->assertSame(2, $res->json('read.activities'));
        $this->assertSame($before, WbsItem::count(), '미리보기는 DB 를 건드리면 안 된다');
        $this->assertNotNull($res->json('token'));
    }

    public function test_the_preview_says_what_will_be_deleted(): void
    {
        Storage::fake('local');
        $sub = $this->seedTree();
        SafetyWorkItem::create([
            'wbs_code' => $sub->wbs_code, 'site_id' => $this->site->id,
            'work_code' => 'SW-260805-001', 'title' => '전선관 매입 TBM', 'work_date' => '2026-08-05',
        ]);
        $this->actingAs($this->user('admin'));

        $res = $this->post('/wbs-api/schedule/preview', [
            'schedule' => $this->goodSheet(), 'project_code' => self::CODE,
        ])->assertOk();

        $this->assertSame(5, $res->json('willDelete.wbsItems'));
        $this->assertSame(1, $res->json('willDelete.safetyCards'), '지워질 안전카드 수를 미리 알려야 한다');
    }

    public function test_a_sheet_with_wrong_headers_is_flagged_before_anything_is_deleted(): void
    {
        Storage::fake('local');
        $this->seedTree();
        $this->actingAs($this->user('admin'));

        $bad = $this->xlsx([['품명', '수량', '단가'], ['케이블', 100, 3.5]]);

        $res = $this->post('/wbs-api/schedule/preview', ['schedule' => $bad, 'project_code' => self::CODE]);

        // 읽기 자체가 실패(422)하거나, 읽혔더라도 0 개면 blocked 로 막힌다. 어느 쪽이든 삭제는 없다.
        $this->assertTrue($res->status() === 422 || $res->json('blocked') !== null);
        $this->assertSame(5, WbsItem::count(), '헤더가 틀렸다고 기존 공정표가 사라지면 안 된다');
    }

    // ── 교체 ────────────────────────────────────────────────────────────

    public function test_replacing_swaps_the_tree(): void
    {
        Storage::fake('local');
        $this->seedTree();
        $this->actingAs($this->user('admin'));

        $token = $this->post('/wbs-api/schedule/preview', [
            'schedule' => $this->goodSheet(), 'project_code' => self::CODE,
        ])->json('token');

        $res = $this->postJson('/wbs-api/schedule/replace', [
            'token' => $token, 'project_code' => self::CODE, 'confirm' => true,
        ])->assertOk();

        $this->assertSame(5, $res->json('removed.wbsItems'));
        $this->assertGreaterThan(0, $res->json('imported.subtasks'));
        $this->assertSame(0, WbsItem::where('wbs_code', self::CODE.'-W-A030')->count(), '옛 행은 사라져야 한다');
        $this->assertGreaterThan(0, WbsItem::where('name', 'like', '%개정%')->count(), '새 행이 들어와야 한다');
    }

    public function test_a_stale_token_is_refused(): void
    {
        Storage::fake('local');
        $this->seedTree();
        $this->actingAs($this->user('admin'));

        $this->postJson('/wbs-api/schedule/replace', [
            'token' => 'wbs-schedules/없는파일.xlsx', 'project_code' => self::CODE, 'confirm' => true,
        ])->assertStatus(410);

        $this->assertSame(5, WbsItem::count());
    }

    public function test_a_token_outside_our_folder_is_refused(): void
    {
        // 임의 경로를 받으면 서버의 아무 파일이나 읽게 된다.
        Storage::fake('local');
        $this->seedTree();
        $this->actingAs($this->user('admin'));

        $this->postJson('/wbs-api/schedule/replace', [
            'token' => '../../.env', 'project_code' => self::CODE, 'confirm' => true,
        ])->assertStatus(410);
    }

    public function test_replacing_without_confirmation_is_refused(): void
    {
        Storage::fake('local');
        $this->seedTree();
        $this->actingAs($this->user('admin'));

        $token = $this->post('/wbs-api/schedule/preview', [
            'schedule' => $this->goodSheet(), 'project_code' => self::CODE,
        ])->json('token');

        $this->postJson('/wbs-api/schedule/replace', [
            'token' => $token, 'project_code' => self::CODE,
        ])->assertStatus(422);

        $this->assertSame(5, WbsItem::count());
    }

    // ── 권한 ────────────────────────────────────────────────────────────

    public function test_a_worker_cannot_replace_the_schedule(): void
    {
        Storage::fake('local');
        $this->seedTree();
        $this->actingAs($this->user('worker', ['access_scope' => 'self']));

        $this->post('/wbs-api/schedule/preview', [
            'schedule' => $this->goodSheet(), 'project_code' => self::CODE,
        ])->assertStatus(403);

        $this->assertSame(5, WbsItem::count());
    }

    public function test_a_site_manager_cannot_replace_another_sites_schedule(): void
    {
        Storage::fake('local');
        $this->seedTree();
        $other = Site::create(['code' => 'TX-02', 'name' => 'Texas', 'timezone' => 'America/Chicago', 'status' => 'active']);
        $this->actingAs($this->user('site_manager', ['access_scope' => 'site', 'allowed_site_id' => $other->id]));

        $this->post('/wbs-api/schedule/preview', [
            'schedule' => $this->goodSheet(), 'project_code' => self::CODE,
        ])->assertStatus(403);
    }

    // ── 행 사이 삽입 ─────────────────────────────────────────────────────

    private function svc(): WbsService
    {
        return app(WbsService::class);
    }

    public function test_a_row_is_inserted_right_after_the_anchor(): void
    {
        $this->seedTree();
        $anchor = WbsItem::where('node_no', '1.2.1')->first();

        $res = $this->svc()->insertAfter($anchor->wbs_code, ['name' => '전선관 검사', 'trade' => 'ELEC']);
        $this->assertTrue($res['success']);

        $order = WbsItem::where('parent_id', $anchor->parent_id)
            ->orderBy('sort_order')->pluck('name')->all();

        $this->assertSame(['전선관 매입', '전선관 검사', '박스 설치', '입선'], $order);
    }

    public function test_existing_numbers_are_left_alone(): void
    {
        // 번호를 다시 매기면 도면·서류·안전카드에 적힌 번호와 어긋난다.
        $this->seedTree();
        $anchor = WbsItem::where('node_no', '1.2.1')->first();

        $res = $this->svc()->insertAfter($anchor->wbs_code, ['name' => '전선관 검사']);

        $this->assertSame('1.2.1a', $res['node_no']);
        $this->assertSame('1.2.2', WbsItem::where('name', '박스 설치')->value('node_no'), '뒤 행 번호는 그대로여야 한다');
        $this->assertSame('1.2.3', WbsItem::where('name', '입선')->value('node_no'));
    }

    public function test_inserting_twice_after_the_same_row_keeps_going(): void
    {
        $this->seedTree();
        $anchor = WbsItem::where('node_no', '1.2.1')->first();

        $first = $this->svc()->insertAfter($anchor->wbs_code, ['name' => '검사 1']);
        $second = $this->svc()->insertAfter($anchor->wbs_code, ['name' => '검사 2']);

        $this->assertSame('1.2.1a', $first['node_no']);
        $this->assertSame('1.2.1b', $second['node_no']);

        $order = WbsItem::where('parent_id', $anchor->parent_id)->orderBy('sort_order')->pluck('name')->all();
        $this->assertSame(['전선관 매입', '검사 2', '검사 1', '박스 설치', '입선'], $order,
            '나중에 넣은 것이 기준 행 바로 뒤에 온다');
    }

    public function test_the_inserted_row_inherits_the_anchors_dates(): void
    {
        $this->seedTree();
        $anchor = WbsItem::where('node_no', '1.2.1')->first();

        $this->svc()->insertAfter($anchor->wbs_code, ['name' => '전선관 검사']);

        $inserted = WbsItem::where('name', '전선관 검사')->first();
        $this->assertSame($anchor->planned_end->toDateString(), $inserted->planned_start->toDateString(),
            '사이에 끼운 작업은 보통 기준 작업 다음에 한다');
        $this->assertSame('ELEC', $inserted->trade, '공종을 안 주면 기준 행에서 물려받는다');
    }

    public function test_a_nameless_row_is_refused(): void
    {
        $this->seedTree();
        $anchor = WbsItem::where('node_no', '1.2.1')->first();

        $this->assertFalse($this->svc()->insertAfter($anchor->wbs_code, ['name' => '  '])['success']);
        $this->assertSame(5, WbsItem::count());
    }

    public function test_inserting_after_a_top_level_stage_is_refused(): void
    {
        $this->seedTree();
        $stage = WbsItem::where('level', 'stage')->first();

        $res = $this->svc()->insertAfter($stage->wbs_code, ['name' => '새 단계']);

        $this->assertFalse($res['success']);
        $this->assertStringContainsString('하위 작업', $res['error']);
    }

    public function test_an_unknown_anchor_is_refused(): void
    {
        $this->seedTree();

        $this->assertFalse($this->svc()->insertAfter('없는-코드', ['name' => '작업'])['success']);
    }

    public function test_the_api_exposes_the_insert(): void
    {
        $this->seedTree();
        $anchor = WbsItem::where('node_no', '1.2.1')->first();
        $this->actingAs($this->user('admin'));

        $this->postJson('/smart-company-api/api_insertWbsRow', [
            'args' => [$anchor->wbs_code, ['name' => '전선관 검사']], 'siteId' => 'ALL',
        ])->assertOk()->assertJsonPath('success', true);
    }

    public function test_a_read_only_client_cannot_insert(): void
    {
        $this->seedTree();
        $anchor = WbsItem::where('node_no', '1.2.1')->first();
        $this->actingAs($this->user('client'));

        $this->postJson('/smart-company-api/api_insertWbsRow', [
            'args' => [$anchor->wbs_code, ['name' => '몰래 추가']], 'siteId' => 'ALL',
        ])->assertStatus(403);

        $this->assertSame(5, WbsItem::count());
    }
}
