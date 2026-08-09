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

        // 새 시트에 A010/A020 이 있으므로 A030 만 사라진다. 그 카드도 함께.
        $this->assertSame(1, $res->json('willDelete.wbsItems'), '사라지는 것은 새 시트에 없는 작업뿐이다');
        $this->assertSame(2, $res->json('willDelete.kept'), '액티비티 ID 가 같으면 진행률을 물려받는다');
        $this->assertSame(0, $res->json('willDelete.safetyCards'), 'A010 의 카드는 그대로 붙어 있다');
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

    /**
     * 현장에서 실제로 오는 간트 형식.
     *
     * LG ESS Ph10 공정표(Rev.2)를 그대로 쓰지 않고 구조만 옮겨 왔다 — 저장소가 공개라
     * 고객 현장 주소·일정·공사비가 올라가면 안 된다. 아래가 그 시트에서 확인한 특징이다:
     *
     *   · 헤더가 첫 줄이 아니다 (제목·요약·범례가 위에 있다)
     *   · 헤더 이름이 "공기/발주" · "시작" · "종료"
     *   · 공기 값에 d 가 붙는다 ("17d")
     *   · 조달 행은 같은 칸에 발주 상태를 적는다 ("미발주")
     *   · 구간 제목 행으로 공정을 나눈다 ("천장배관 / MEP Overhead")
     *   · ★ 는 주공정 표시
     *   · 나중에 끼운 행은 ID 가 "추가"
     *   · 시트 맨 아래에 개정 이력 주석이 붙는다
     */
    private function fieldGantt(): UploadedFile
    {
        return $this->xlsx([
            ['현장 통합 공정 간트 Rev.2'],
            ['착공 2026-07-17 → 사용승인 2026-09-29 · ★=주공정'],
            [],
            ['ID', '작업명', '영문명/비고', '공종', '공기/발주', '시작', '종료'],
            ['자재 조달 / Procurement'],
            ['M01', '도어·하드웨어 — 리드 3주', 'Doors', 'ARCH', '07-28발주', '2026-07-28', '2026-08-04'],
            ['M02', '타일·백커보드', 'Tile', 'ARCH', '미발주', '', ''],
            ['철거·지중 / Demo & Underground'],
            ['A190', '슬래브 절단', 'Slab Cutting', 'DEMO', '1d', '2026-07-22', '2026-07-25'],
            ['추가', '수밀테스트 구간 기초철근', 'Rebar', 'GC', '4d', '2026-07-24', '2026-07-28'],
            ['추가', '폼 해체 및 면정리', 'Formwork Removal', 'GC', '2d', '2026-08-03', '2026-08-04'],
            ['천장배관 / MEP Overhead'],
            ['★A250', '★전기 간선(EMT) 및 분기 배관', 'Conduit', 'ELEC', '17d', '2026-07-29', '2026-08-17'],
            ['A260', '급수/통기 배관', 'Plumbing Rough', 'PLUMB', '10d', '2026-08-03', '2026-08-12'],
            ['◆ 마일스톤 / Milestones'],
            ['M-1', 'Frame Inspection', '', 'MILE', '', '2026-08-17', ''],
            ['Rev.2 변경 요지 (착공 조기화, 배관 상세화):'],
            ['① 배관 상세화 — 언더그라운드를 오수와 급수로 분리, 만수/수압시험 순 명기.'],
        ]);
    }

    // ── 현장 간트 형식 ───────────────────────────────────────────────────

    public function test_a_field_gantt_is_read_with_its_own_header_names(): void
    {
        Storage::fake('local');
        $this->seedTree();
        $this->actingAs($this->user('admin'));

        $res = $this->post('/wbs-api/schedule/preview', [
            'schedule' => $this->fieldGantt(), 'project_code' => self::CODE,
        ])->assertOk();

        // 조달 2 + 철거 3 + 천장 2 = 7. "공기/발주" · "시작" · "종료" 를 못 읽으면 0 이 된다.
        $this->assertSame(7, $res->json('read.activities'));
    }

    public function test_section_titles_become_the_stages(): void
    {
        Storage::fake('local');
        $this->actingAs($this->user('admin'));

        $token = $this->post('/wbs-api/schedule/preview', [
            'schedule' => $this->fieldGantt(), 'project_code' => self::CODE,
        ])->json('token');
        $this->postJson('/wbs-api/schedule/replace', [
            'token' => $token, 'project_code' => self::CODE, 'confirm' => true,
        ])->assertOk();

        $stages = WbsItem::where('project_code', self::CODE)->where('level', 'stage')
            ->orderBy('sort_order')->pluck('name')->all();

        $this->assertSame(['자재 조달 / Procurement', '철거·지중 / Demo & Underground', '천장배관 / MEP Overhead'], $stages);
    }

    public function test_revision_notes_at_the_bottom_do_not_become_stages(): void
    {
        // 개정 이력도 "첫 칸만 채워진 줄" 이라 구간처럼 보인다. 아래에 작업이 없으면 구간이 아니다.
        Storage::fake('local');
        $this->actingAs($this->user('admin'));

        $res = $this->post('/wbs-api/schedule/preview', [
            'schedule' => $this->fieldGantt(), 'project_code' => self::CODE,
        ])->assertOk();

        $sections = $res->json('read.sections');
        $this->assertCount(3, $sections);
        foreach ($sections as $s) {
            $this->assertStringNotContainsString('Rev.2', $s);
            $this->assertStringNotContainsString('배관 상세화', $s);
        }
    }

    public function test_a_duration_written_as_17d_is_read_as_17_days(): void
    {
        Storage::fake('local');
        $this->actingAs($this->user('admin'));
        $this->importFieldGantt();

        $this->assertSame(17, (int) WbsItem::where('activity_id', 'A250')->value('days'));
    }

    public function test_an_order_status_in_the_duration_column_is_not_read_as_days(): void
    {
        // "07-28발주" 에서 숫자만 긁으면 728 일이 된다. 공기가 없는 것으로 봐야 한다.
        Storage::fake('local');
        $this->actingAs($this->user('admin'));
        $this->importFieldGantt();

        $this->assertNull(WbsItem::where('activity_id', 'M01')->value('days'));
        $this->assertSame('2026-07-28', WbsItem::where('activity_id', 'M01')->value('planned_start')->toDateString(),
            '공기를 못 읽어도 날짜 칸은 살아 있어야 한다');
    }

    public function test_a_starred_row_is_marked_critical_and_the_star_is_stripped(): void
    {
        Storage::fake('local');
        $this->actingAs($this->user('admin'));
        $this->importFieldGantt();

        $row = WbsItem::where('activity_id', 'A250')->first();
        $this->assertNotNull($row, 'ID 에서 ★ 를 떼야 A250 으로 찾을 수 있다');
        // 화면의 주공정 강조는 payload 가 아니라 is_critical 컬럼을 읽는다.
        $this->assertTrue((bool) $row->is_critical);
        $this->assertStringNotContainsString('★', $row->name);
    }

    public function test_rows_marked_only_as_추가_all_survive_with_distinct_keys(): void
    {
        // 여러 행이 ID 칸에 "추가" 라고만 적혀 온다. 키가 겹치면 마지막 하나만 남는다.
        Storage::fake('local');
        $this->actingAs($this->user('admin'));
        $this->importFieldGantt();

        $added = WbsItem::where('project_code', self::CODE)->where('activity_id', 'like', '추가-%')->get();

        $this->assertCount(2, $added);
        $this->assertCount(2, $added->pluck('activity_id')->unique(), '키가 서로 달라야 둘 다 남는다');
        $this->assertEqualsCanonicalizing(
            ['수밀테스트 구간 기초철근', '폼 해체 및 면정리'],
            $added->pluck('name')->all()
        );
    }

    public function test_reimporting_the_same_sheet_keeps_field_progress(): void
    {
        Storage::fake('local');
        $this->actingAs($this->user('admin'));
        $this->importFieldGantt();

        WbsItem::where('activity_id', 'A250')->update(['progress' => 60, 'status' => '진행중', 'company' => 'AUTORICA']);

        $this->importFieldGantt();

        $row = WbsItem::where('activity_id', 'A250')->first();
        $this->assertSame(60, $row->progress, '다시 수입해도 현장이 올린 진행률은 지켜야 한다');
        $this->assertSame('진행중', $row->status);
        $this->assertSame('AUTORICA', $row->company);
    }

    private function importFieldGantt(): void
    {
        $token = $this->post('/wbs-api/schedule/preview', [
            'schedule' => $this->fieldGantt(), 'project_code' => self::CODE,
        ])->json('token');

        $this->postJson('/wbs-api/schedule/replace', [
            'token' => $token, 'project_code' => self::CODE, 'confirm' => true,
        ])->assertOk();
    }

    // ── 완료 → 공정률 ───────────────────────────────────────────────────

    public function test_완료_처리한_공사가_공기만큼_공정률에_반영된다(): void
    {
        // 현장 간트에는 투입조 칸이 없어 공수가 전부 비어 있다. 그래도 공기가 있으니
        // 큰 작업을 끝냈을 때 큰 폭으로 올라야 한다 — 여기서 끊기면 "완료" 버튼이
        // 공정률에 아무 의미를 못 남긴다.
        Storage::fake('local');
        $this->actingAs($this->user('admin'));
        $this->importFieldGantt();

        $wbs = app(WbsService::class);
        $this->assertSame(0, $wbs->progressSummary(self::CODE)['progress']);

        // 공기 합 = 1 + 4 + 2 + 17 + 10 = 34일 (조달 2건·마일스톤은 공기 없음)
        $wbs->markStatus(self::CODE.'-W-A250', '완료'); // 17일

        $this->assertSame(50, $wbs->progressSummary(self::CODE)['progress'], '17/34 = 50%');
    }

    public function test_조달만_발주완료해도_공정률은_움직이지_않는다(): void
    {
        // 조달 23건을 눌러 공정률이 28% 로 뛰던 문제. 발주 행은 공기가 없어 무게 0 이다.
        Storage::fake('local');
        $this->actingAs($this->user('admin'));
        $this->importFieldGantt();

        $wbs = app(WbsService::class);
        $wbs->markStatus(self::CODE.'-W-M01', '완료');
        $wbs->markStatus(self::CODE.'-W-M02', '완료');

        $this->assertSame(0, $wbs->progressSummary(self::CODE)['progress']);
    }

    // ── 응답 모양 ────────────────────────────────────────────────────────

    public function test_every_list_in_the_preview_is_a_json_array_not_an_object(): void
    {
        // 액티비티는 ID 를 키로 갖는 맵이라, 그대로 내보내면 JSON 이 배열이 아니라
        // 객체가 된다. 화면은 배열로 받아 .map 을 돌리므로 그 자리에서 멈추고
        // 모달이 "읽는 중..." 에 굳는다. 서버는 통과하는데 화면만 죽는 종류의 버그라
        // 응답 모양을 여기서 못박는다.
        Storage::fake('local');
        $this->actingAs($this->user('admin'));

        $res = $this->post('/wbs-api/schedule/preview', [
            'schedule' => $this->fieldGantt(), 'project_code' => self::CODE,
        ])->assertOk();

        $decoded = json_decode($res->getContent(), true);

        foreach (['sample', 'sections', 'warnings', 'milestoneNames', 'activityIds'] as $key) {
            $this->assertArrayHasKey($key, $decoded['read'], "read.{$key} 가 없습니다");
            $this->assertTrue(
                array_is_list($decoded['read'][$key]),
                "read.{$key} 가 JSON 배열이 아닙니다 — 화면에서 .map 이 터집니다"
            );
        }
    }

    public function test_the_preview_sample_carries_what_the_screen_shows(): void
    {
        Storage::fake('local');
        $this->actingAs($this->user('admin'));

        $res = $this->post('/wbs-api/schedule/preview', [
            'schedule' => $this->fieldGantt(), 'project_code' => self::CODE,
        ])->assertOk();

        $first = $res->json('read.sample.0');

        $this->assertSame('M01', $first['id']);
        $this->assertStringContainsString('도어', $first['name']);
        $this->assertSame('ARCH', $first['trade']);
        $this->assertSame('2026-07-28', $first['start']);
    }
}
