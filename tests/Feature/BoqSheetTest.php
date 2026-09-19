<?php

namespace Tests\Feature;

use App\Models\BoqItem;
use App\Models\Company;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Services\Admin\BoqSheetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 물량/BOQ 를 표 파일로 주고받는다 — 내보내기 · 올리기 · 비우기.
 *
 * ── 왜 이 묶음이 통째로 필요했나 ───────────────────────────────────────
 * 대장에 줄을 <b>새로 넣는 길이 아예 없었다.</b> 화면의 「수정」은 이미 있는 줄의
 * 수량·단가만 고치고, 새 줄은 전용 임포트 명령이나 도면 AI 판독으로만 들어왔다.
 * 그래서 «전부 지우고 다시 입력» 을 지우기만 만들어 주면, 대장이 빈 채로 남고
 * 다시 채울 방법이 없어진다 — 그건 고쳐 주는 것이 아니라 망가뜨리는 것이다.
 *
 * ── 여기서 지키는 것 ───────────────────────────────────────────────────
 *  ① <b>왕복이 깨지지 않는다.</b> 내보낸 파일을 그대로 올리면 같은 대장이 된다.
 *     이것이 «전부 지우고 다시 입력» 의 실제 복구 수단이다.
 *  ② <b>절반만 들어가지 않는다.</b> 한 줄이라도 잘못돼 있으면 아무것도 지우지
 *     않고 그 줄 번호를 돌려준다. 절반 들어간 대장은 빈 대장보다 나쁘다 —
 *     어디까지 들어갔는지 모르는 채로 합계가 틀린다.
 *  ③ <b>지우기 전에 손에 쥐여 준다.</b> 비우기는 지우기 직전의 내용을 CSV 로
 *     돌려준다. 서버에 백업을 «두는» 것은 배포마다 지워져 못 믿는다.
 */
class BoqSheetTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Project $project;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'code' => 'OWN', 'name' => 'ERP', 'legal_name' => 'ERP LLC',
            'company_type' => Company::TYPE_OWN, 'status' => 'active',
        ]);
        $site = Site::query()->create([
            'company_id' => $this->company->id, 'code' => 'S-1', 'name' => 'Test Site',
            'country' => 'US', 'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        $this->project = Project::query()->create([
            'company_id' => $this->company->id, 'site_id' => $site->id,
            'project_code' => 'P-001', 'name' => 'Test Project', 'construction_type' => 'electrical',
        ]);
        $this->admin = User::query()->create([
            'name' => 'Boss', 'email' => 'boss@example.test', 'password' => Hash::make('x'),
            'access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);
        $this->actingAs($this->admin);
    }

    private function svc(): BoqSheetService
    {
        return app(BoqSheetService::class);
    }

    private function seedRows(int $count = 3): void
    {
        for ($i = 1; $i <= $count; $i++) {
            BoqItem::query()->create([
                'company_id' => $this->company->id,
                'site_id' => $this->project->site_id,
                'project_id' => $this->project->id,
                'seq' => $i,
                'discipline_code' => '01', 'discipline' => '기계',
                'name_kr' => "배관 자재 {$i}", 'name_en' => "Pipe {$i}",
                'spec' => '2" SCH40', 'unit' => 'M',
                'qty' => 10 * $i, 'qty_basis' => '문서확정',
                'unit_price' => 25.5,
            ]);
        }
    }

    /** 제목 줄 + 주어진 줄들로 CSV 를 만든다. */
    private function csv(array $lines): string
    {
        $out = "\xEF\xBB\xBF".implode(',', BoqSheetService::COLUMNS)."\n";

        return $out.implode("\n", $lines)."\n";
    }

    // ── 내보내기 ────────────────────────────────────────────────────────

    public function test_export_puts_every_row_in_the_sheet(): void
    {
        $this->seedRows(3);

        $r = $this->svc()->export($this->project->id);

        $this->assertTrue($r['success']);
        $this->assertSame(3, $r['count']);
        $this->assertStringContainsString('배관 자재 2', $r['csv']);
        $this->assertStringContainsString('P-001', $r['fileName']);
    }

    public function test_the_sheet_opens_in_excel_without_breaking_korean(): void
    {
        // BOM 이 없으면 엑셀이 한글을 깨서 연다. 그 파일을 고쳐 다시 올리면 품명이
        // 통째로 깨진 채 대장에 들어간다 — 되돌릴 수 없는 손상이다.
        $this->seedRows(1);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $this->svc()->export($this->project->id)['csv']);
    }

    // ── 올리기 ──────────────────────────────────────────────────────────

    public function test_a_sheet_can_create_rows_that_did_not_exist(): void
    {
        // 이것이 이 작업 전체의 이유다. 지금까지 대장에 줄을 새로 넣을 길이 없었다.
        $this->assertSame(0, BoqItem::query()->count());

        $r = $this->svc()->import($this->project->id, $this->csv([
            '1,01,기계,밸브 게이트 2인치,Gate Valve 2",2" 150#,EA,12,문서확정,145.50,,견적서 3쪽,S020,',
            '2,02,전기,전선관 EMT 1인치,EMT Conduit 1",1" EMT,M,300,도면판독,3.25,,E-201,,',
        ]));

        $this->assertTrue($r['success'], $r['error'] ?? '');
        $this->assertSame(2, $r['imported']);
        $this->assertSame(2, BoqItem::query()->count());

        $valve = BoqItem::query()->where('name_kr', '밸브 게이트 2인치')->firstOrFail();
        $this->assertSame('EA', $valve->unit);
        $this->assertSame('12.00', (string) $valve->qty);
        $this->assertSame('S020', $valve->wbs_activity_id);
        $this->assertSame('사람', $valve->extracted_by, '사람이 표로 올린 줄인지 AI 가 뽑은 줄인지 구분돼야 한다.');
    }

    public function test_the_amount_is_computed_not_trusted(): void
    {
        // 엑셀에서 수량만 고치고 금액 칸을 안 고치는 일이 흔하다. 파일의 금액을
        // 믿으면 대장 합계가 조용히 틀린다.
        $this->svc()->import($this->project->id, $this->csv([
            '1,01,기계,테스트,Test,,EA,10,문서확정,100,99999999,,,',
        ]));

        $this->assertSame('1000.00', (string) BoqItem::query()->firstOrFail()->amount);
    }

    public function test_it_reads_numbers_the_way_people_write_them(): void
    {
        $this->svc()->import($this->project->id, $this->csv([
            '1,01,기계,테스트,Test,,EA,"1,250",문서확정,"$3.50",,,,',
        ]));

        $row = BoqItem::query()->firstOrFail();
        $this->assertSame('1250.00', (string) $row->qty);
        $this->assertSame('3.50', (string) $row->unit_price);
    }

    public function test_replace_swaps_the_whole_register(): void
    {
        $this->seedRows(5);

        $r = $this->svc()->import($this->project->id, $this->csv([
            '1,01,기계,새 물량,New,,EA,1,문서확정,10,,,,',
        ]), 'replace');

        $this->assertTrue($r['success']);
        $this->assertSame(5, $r['replaced']);
        $this->assertSame(1, BoqItem::query()->count());
        $this->assertSame('새 물량', BoqItem::query()->firstOrFail()->name_kr);
    }

    public function test_append_keeps_what_is_there(): void
    {
        $this->seedRows(3);

        $this->svc()->import($this->project->id, $this->csv([
            '99,01,기계,덧붙인 줄,Added,,EA,1,문서확정,10,,,,',
        ]), 'append');

        $this->assertSame(4, BoqItem::query()->count());
        // 번호는 파일의 것을 믿지 않고 이어서 매긴다 — (프로젝트, 번호)가 유일해야 한다.
        $this->assertSame(4, (int) BoqItem::query()->where('name_kr', '덧붙인 줄')->value('seq'));
    }

    public function test_duplicate_numbers_in_the_sheet_do_not_break_the_upload(): void
    {
        // 엑셀에서 줄을 복사하면 번호가 그대로 복사된다. 그 번호를 믿으면
        // 유일 제약에 걸려 418줄짜리 파일이 통째로 안 들어간다.
        $r = $this->svc()->import($this->project->id, $this->csv([
            '1,01,기계,가,A,,EA,1,문서확정,10,,,,',
            '1,01,기계,나,B,,EA,1,문서확정,10,,,,',
            '1,01,기계,다,C,,EA,1,문서확정,10,,,,',
        ]));

        $this->assertTrue($r['success'], $r['error'] ?? '');
        $this->assertSame([1, 2, 3], BoqItem::query()->orderBy('seq')->pluck('seq')->all());
    }

    public function test_a_bad_line_stops_everything_and_says_which_line(): void
    {
        // 절반만 들어간 대장은 빈 대장보다 나쁘다 — 어디까지 들어갔는지 아무도 모른다.
        $this->seedRows(4);

        $r = $this->svc()->import($this->project->id, $this->csv([
            '1,01,기계,멀쩡한 줄,Fine,,EA,1,문서확정,10,,,,',
            '2,01,기계,,NoName,,EA,1,문서확정,10,,,,',
            '3,01,기계,수량이 글자,Bad,,EA,열두개,문서확정,10,,,,',
        ]), 'replace');

        $this->assertFalse($r['success']);
        $this->assertSame(4, BoqItem::query()->count(), '파일이 잘못됐으면 기존 물량은 그대로 남아야 한다.');

        $joined = implode("\n", $r['lineErrors']);
        $this->assertStringContainsString('3번째 줄', $joined);
        $this->assertStringContainsString('4번째 줄', $joined);
        $this->assertStringContainsString('품명', $joined);
        $this->assertStringContainsString('수량', $joined);
    }

    public function test_it_refuses_a_sheet_with_the_wrong_columns(): void
    {
        $r = $this->svc()->import($this->project->id, "이름,갯수\n밸브,3\n");

        $this->assertFalse($r['success']);
        $this->assertStringContainsString('품명(국문)', $r['error']);
        $this->assertSame(0, BoqItem::query()->count());
    }

    public function test_an_unknown_quantity_basis_is_refused(): void
    {
        $r = $this->svc()->import($this->project->id, $this->csv([
            '1,01,기계,테스트,Test,,EA,1,대충추측,10,,,,',
        ]));

        $this->assertFalse($r['success']);
        $this->assertStringContainsString('수량근거', implode('', $r['lineErrors']));
    }

    public function test_blank_lines_from_excel_are_not_errors(): void
    {
        // 엑셀은 파일 끝에 빈 줄을 남긴다. 그것을 오류로 세면 멀쩡한 파일이 계속 거절된다.
        $r = $this->svc()->import($this->project->id,
            $this->csv(['1,01,기계,테스트,Test,,EA,1,문서확정,10,,,,', ',,,,,,,,,,,,,', '']));

        $this->assertTrue($r['success'], $r['error'] ?? '');
        $this->assertSame(1, $r['imported']);
    }

    // ── 비우기 ──────────────────────────────────────────────────────────

    public function test_clearing_needs_the_phrase_typed_out(): void
    {
        // 목록을 훑다 잘못 누르는 사고와, 지우겠다고 마음먹고 적는 일은 손이 다르다.
        $this->seedRows(3);

        $this->assertFalse($this->svc()->clear($this->project->id, '네')['success']);
        $this->assertFalse($this->svc()->clear($this->project->id, '')['success']);
        $this->assertSame(3, BoqItem::query()->count());

        $this->assertTrue($this->svc()->clear($this->project->id, '전부 삭제')['success']);
        $this->assertSame(0, BoqItem::query()->count());
    }

    public function test_the_dry_run_counts_without_touching_anything(): void
    {
        $this->seedRows(3);

        $r = $this->svc()->clear($this->project->id, '', true);

        $this->assertSame(3, $r['count']);
        $this->assertSame(255.0 + 510.0 + 765.0, $r['amount']);
        $this->assertSame(3, BoqItem::query()->count());
    }

    public function test_what_was_deleted_comes_back_in_your_hand(): void
    {
        // 이 시험이 «되돌릴 수 없음» 을 견딜 만하게 만든다. 지운 내용이 파일로
        // 나오고, 그 파일을 그대로 올리면 대장이 원래대로 돌아온다.
        $this->seedRows(3);
        $before = $this->svc()->export($this->project->id)['csv'];

        $cleared = $this->svc()->clear($this->project->id, '전부 삭제');

        $this->assertSame(3, $cleared['count']);
        $this->assertSame(0, BoqItem::query()->count());
        $this->assertNotEmpty($cleared['backupCsv']);

        // 되돌린다.
        $restored = $this->svc()->import($this->project->id, $cleared['backupCsv'], 'replace');

        $this->assertTrue($restored['success'], $restored['error'] ?? '');
        $this->assertSame(3, BoqItem::query()->count());
        $this->assertSame($before, $this->svc()->export($this->project->id)['csv'],
            '되돌린 대장이 지우기 전과 글자 하나까지 같아야 한다.');
    }

    // ── 한 줄 지우기 ────────────────────────────────────────────────────

    public function test_one_row_can_be_removed(): void
    {
        $this->seedRows(3);
        $id = BoqItem::query()->where('seq', 2)->value('id');

        $this->assertTrue($this->svc()->deleteItem($id)['success']);
        $this->assertSame(2, BoqItem::query()->count());
        $this->assertNull(BoqItem::query()->find($id));
    }

    // ── 권한 ────────────────────────────────────────────────────────────

    public function test_a_site_manager_may_edit_but_may_not_empty_the_register(): void
    {
        // 대장을 통째로 비우는 것은 되돌릴 수 없다. 한 사람이 실수하면 프로젝트
        // 견적이 통째로 사라진다.
        $this->seedRows(2);
        $manager = User::query()->create([
            'name' => 'M', 'email' => 'm@example.test', 'password' => Hash::make('x'),
            'access_role' => 'site_manager', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);
        $this->actingAs($manager);

        $this->assertTrue($this->svc()->canManage());
        $this->assertFalse($this->svc()->canClear());

        $this->assertFalse($this->svc()->clear($this->project->id, '전부 삭제')['success']);
        $this->assertFalse($this->svc()->import($this->project->id, $this->csv([
            '1,01,기계,테스트,Test,,EA,1,문서확정,10,,,,',
        ]), 'replace')['success'], '교체는 기존 것을 지운다 — 비우기와 같은 권한이어야 한다.');

        $this->assertSame(2, BoqItem::query()->count());

        // 뒤에 붙이는 것은 지우지 않으므로 할 수 있어야 한다.
        $this->assertTrue($this->svc()->import($this->project->id, $this->csv([
            '1,01,기계,테스트,Test,,EA,1,문서확정,10,,,,',
        ]), 'append')['success']);
    }

    public function test_a_worker_cannot_touch_the_register_at_all(): void
    {
        $this->seedRows(2);
        $worker = User::query()->create([
            'name' => 'W', 'email' => 'w@example.test', 'password' => Hash::make('x'),
            'access_role' => 'worker', 'access_scope' => 'assigned_sites', 'account_status' => 'active',
        ]);
        $this->actingAs($worker);

        $this->assertFalse($this->svc()->export($this->project->id)['success']);
        $this->assertFalse($this->svc()->clear($this->project->id, '전부 삭제')['success']);
        $this->assertFalse($this->svc()->deleteItem(BoqItem::query()->value('id'))['success']);
        $this->assertSame(2, BoqItem::query()->count());
    }

    public function test_it_refuses_when_no_project_is_chosen(): void
    {
        // 프로젝트를 안 고른 채로 «전부 비우기» 가 전체 대장을 쓸어버리면 안 된다.
        $this->seedRows(3);

        $this->assertFalse($this->svc()->clear(null, '전부 삭제')['success']);
        $this->assertFalse($this->svc()->clear(0, '전부 삭제')['success']);
        $this->assertSame(3, BoqItem::query()->count());
    }

    public function test_the_whole_round_trip_works_through_the_real_api(): void
    {
        // 서비스만 시험하면 디스패치 오타(이름·인자 순서)가 그대로 배포된다.
        // 화면이 실제로 부르는 길로 한 바퀴 돌려 본다: 올리기 → 내보내기 → 비우기.
        $csv = $this->csv([
            '1,01,기계,밸브,Valve,,EA,4,문서확정,50,,,,',
            '2,01,기계,플랜지,Flange,,EA,8,문서확정,12.5,,,,',
        ]);

        $call = fn (string $method, array $args) => $this->postJson(
            '/smart-company-api/'.$method,
            ['args' => $args],
        );

        $call('api_importBoq', [$this->project->id, $csv, 'replace'])
            ->assertOk()->assertJson(['success' => true, 'imported' => 2]);
        $this->assertSame(2, BoqItem::query()->count());

        $call('api_exportBoq', [$this->project->id])
            ->assertOk()->assertJson(['success' => true, 'count' => 2]);

        // 확인 문구가 틀리면 지워지지 않는다 — 경로를 타고 와도 마찬가지여야 한다.
        $call('api_clearBoq', [$this->project->id, '네', false])->assertOk()->assertJson(['success' => false]);
        $this->assertSame(2, BoqItem::query()->count());

        $call('api_clearBoq', [$this->project->id, '전부 삭제', false])
            ->assertOk()->assertJson(['success' => true, 'count' => 2]);
        $this->assertSame(0, BoqItem::query()->count());
    }

    public function test_the_screen_offers_all_of_this(): void
    {
        // 서버가 받을 준비가 돼 있어도 화면에 길이 없으면 아무도 못 쓴다.
        $js = (string) file_get_contents(base_path('public/js/admin-registers.js'));

        foreach (['api_exportBoq', 'api_importBoq', 'api_clearBoq', 'api_deleteBoqItem',
            '표로 내보내기', '표에서 올리기', '전체 비우기'] as $needle) {
            $this->assertStringContainsString($needle, $js, "화면에 {$needle} 가 없습니다.");
        }
    }
}
