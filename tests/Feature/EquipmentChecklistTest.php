<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\EquipmentChecklistItem;
use App\Models\EquipmentChecklistLog;
use App\Models\EquipmentChecklistTemplate;
use App\Models\EquipmentRental;
use App\Models\Site;
use App\Models\UnifiedAlert;
use App\Models\User;
use App\Services\Equipment\EquipmentChecklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 장비 사용 점검 — QR 로 열고, 본 사실을 기록으로 남긴다.
 *
 * ── 이 시험들이 지키는 것 ──────────────────────────────────────────────
 *  ① <b>치명 항목이 걸리면 장비가 실제로 선다.</b> 기록만 남기고 상태를 안 바꾸면,
 *     다음 사람이 스캔했을 때 아무 일도 없었던 것처럼 열린다. 앞사람이 발견한
 *     결함이 뒷사람에게 전달되지 않는 점검은 서류일 뿐이다.
 *  ② <b>답이 기록 안에서 완결된다.</b> 질문 문장까지 통째로 베껴 담는다. 참조만
 *     걸어 두면 질문지를 고치는 순간 과거 기록의 뜻이 같이 바뀐다 — 사고 조사에서
 *     필요한 것이 바로 그 «그날 무엇을 확인했나» 다.
 *  ③ <b>수불이 한 곳에서만 일어난다.</b> 점검이 장비를 불출·반납시키는데, 그 규칙을
 *     여기서 또 쓰면 «이 장비 지금 어디 있나» 에 답이 여러 개가 된다.
 */
class EquipmentChecklistTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Company $company;

    private Employee $employee;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'code' => 'OWN', 'name' => 'ERP', 'legal_name' => 'ERP LLC',
            'company_type' => Company::TYPE_OWN, 'status' => 'active',
        ]);
        $this->site = Site::query()->create([
            'company_id' => $this->company->id, 'code' => 'S-1', 'name' => 'Test Site',
            'country' => 'US', 'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        $this->employee = Employee::query()->create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'employee_number' => 'W-1', 'first_name' => 'A', 'last_name' => 'B', 'name' => 'A B',
            'employment_status' => 'active',
        ]);
        $this->user = User::query()->create([
            'name' => 'A B', 'email' => 'ab@example.test', 'password' => Hash::make('x'),
            'access_role' => 'worker', 'access_scope' => 'assigned_sites',
            'account_status' => 'active', 'employee_id' => $this->employee->id,
        ]);
    }

    private function service(): EquipmentChecklistService
    {
        return app(EquipmentChecklistService::class);
    }

    private function excavator(): Equipment
    {
        return Equipment::query()->create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'equipment_type' => 'Excavator 320', 'model' => 'CAT 320',
            'category_group' => 'equipment', 'trade' => 'heavy',
            'status' => Equipment::STATUS_AVAILABLE,
        ]);
    }

    /** @return array<int, array{ok: bool}> 모든 항목에 같은 답. */
    private function answerAll(EquipmentChecklistTemplate $template, bool $ok): array
    {
        return $template->items->mapWithKeys(fn ($i) => [$i->id => ['ok' => $ok]])->all();
    }

    // ── 질문지 고르기 ───────────────────────────────────────────────────

    public function test_a_deployment_with_no_checklists_still_gets_one(): void
    {
        // 아무도 질문지를 만들지 않은 배포에서 작업자가 QR 을 찍는다. 여기서
        // «점검표가 없습니다» 가 뜨면 그 사람은 다시는 안 찍는다.
        $this->assertSame(0, EquipmentChecklistTemplate::query()->count());

        $template = $this->service()->templateFor($this->excavator(), 'pre_use');

        $this->assertNotNull($template);
        $this->assertTrue($template->items->isNotEmpty());
    }

    public function test_the_checklist_follows_the_trade_of_the_equipment(): void
    {
        $this->service()->ensureDefaults();

        $heavy = $this->service()->templateFor($this->excavator(), 'pre_use');
        $tool = Equipment::query()->create([
            'company_id' => $this->company->id, 'site_id' => $this->site->id,
            'equipment_type' => 'Angle Grinder', 'model' => 'DW402', 'category_group' => 'tool', 'trade' => 'power_tool',
            'status' => Equipment::STATUS_AVAILABLE,
        ]);

        $this->assertNotSame($heavy->id, $this->service()->templateFor($tool, 'pre_use')->id,
            '굴착기와 그라인더가 같은 질문을 받으면 질문지가 아무 말도 안 하는 것과 같다.');
    }

    public function test_a_checklist_made_for_one_machine_beats_the_trade_one(): void
    {
        // 「굴착기 전부」 위에 「그 한 대만」 을 덧댈 수 있어야 한다. 못 덧대면
        // 그 한 대 때문에 스무 대의 질문지를 고치게 된다.
        $this->service()->ensureDefaults();
        $equipment = $this->excavator();

        $special = EquipmentChecklistTemplate::query()->create([
            'scope_type' => 'equipment', 'scope_value' => (string) $equipment->id,
            'name' => '그 한 대 전용', 'stage' => 'pre_use', 'status' => 'active',
        ]);
        EquipmentChecklistItem::query()->create([
            'equipment_checklist_template_id' => $special->id,
            'label_ko' => '전용 항목', 'label_en' => 'Special', 'label_es' => 'Especial',
            'stage' => 'pre_use', 'status' => 'active',
        ]);

        $this->assertSame($special->id, $this->service()->templateFor($equipment, 'pre_use')->id);
    }

    public function test_a_site_checklist_beats_the_company_wide_one(): void
    {
        // 원청사마다 요구가 다르다. 현장 전용이 없으면 그 요구를 넣을 자리가 없어
        // 회사 공통표를 그 현장 기준으로 고치게 되고, 다른 현장이 같이 바뀐다.
        $this->service()->ensureDefaults();
        $equipment = $this->excavator();

        $siteOne = EquipmentChecklistTemplate::query()->create([
            'site_id' => $this->site->id, 'scope_type' => 'trade', 'scope_value' => 'heavy',
            'name' => '이 현장 전용', 'stage' => 'pre_use', 'status' => 'active',
        ]);
        EquipmentChecklistItem::query()->create([
            'equipment_checklist_template_id' => $siteOne->id,
            'label_ko' => '현장 항목', 'label_en' => 'Site item', 'label_es' => 'Punto de obra',
            'stage' => 'pre_use', 'status' => 'active',
        ]);

        $this->assertSame($siteOne->id, $this->service()->templateFor($equipment, 'pre_use')->id);
    }

    public function test_the_return_checklist_is_one_set_for_every_trade(): void
    {
        // 돌려놓을 때 묻는 것은 굴착기든 그라인더든 같다. 공종마다 베끼면 고칠 곳이
        // 여덟 군데가 되고, 그중 한 곳만 고쳐진다.
        $this->service()->ensureDefaults();

        $a = $this->service()->templateFor($this->excavator(), 'post_use');
        $tool = Equipment::query()->create([
            'company_id' => $this->company->id, 'equipment_type' => 'Grinder', 'model' => 'DW402',
            'category_group' => 'tool', 'trade' => 'power_tool', 'status' => Equipment::STATUS_AVAILABLE,
        ]);

        $this->assertNotNull($a);
        $this->assertSame($a->id, $this->service()->templateFor($tool, 'post_use')->id);
    }

    public function test_running_the_defaults_twice_does_not_double_them(): void
    {
        $first = $this->service()->ensureDefaults();
        $second = $this->service()->ensureDefaults();

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second, '배포할 때마다 질문지가 한 벌씩 늘면 아무도 어느 것이 진짜인지 모른다.');
    }

    // ── 제출과 판정 ─────────────────────────────────────────────────────

    public function test_passing_the_check_hands_the_equipment_to_that_worker(): void
    {
        $equipment = $this->excavator();
        $template = $this->service()->templateFor($equipment, 'pre_use');

        $result = $this->service()->submit($equipment, $this->user, 'pre_use',
            $this->answerAll($template, true));

        $this->assertTrue($result['success']);
        $this->assertSame(EquipmentChecklistLog::PASS, $result['result']);

        $equipment->refresh();
        $this->assertSame(Equipment::STATUS_IN_USE, $equipment->status);
        $this->assertSame($this->employee->id, $equipment->employee_id);
        $this->assertNotNull($equipment->last_checked_at);

        // 수불이 실제로 한 줄 열려야 한다 — 이 줄이 없으면 임대료가 원가에 안 잡힌다.
        $this->assertSame(1, EquipmentRental::query()
            ->where('equipment_id', $equipment->id)->whereNull('returned_at')->count());
    }

    public function test_a_critical_failure_actually_stops_the_machine(): void
    {
        // 이 시험이 이 기능 전체의 목적이다. 기록만 남고 장비가 그대로 돌면 점검은
        // 서류일 뿐이다.
        $equipment = $this->excavator();
        $template = $this->service()->templateFor($equipment, 'pre_use');
        $critical = $template->items->firstWhere('severity', EquipmentChecklistItem::CRITICAL);
        $this->assertNotNull($critical, '중장비 기본표에 치명 항목이 하나도 없으면 안 된다.');

        $answers = $this->answerAll($template, true);
        $answers[$critical->id] = ['ok' => false, 'note' => '브레이크가 밀립니다'];

        $result = $this->service()->submit($equipment, $this->user, 'pre_use', $answers);

        $this->assertSame(EquipmentChecklistLog::BLOCKED, $result['result']);

        $equipment->refresh();
        $this->assertSame(Equipment::STATUS_NEEDS_INSPECTION, $equipment->status);
        $this->assertNull($equipment->employee_id, '못 쓰는 장비를 사람 앞으로 넘기면 안 된다.');
        $this->assertSame(0, EquipmentRental::query()
            ->where('equipment_id', $equipment->id)->whereNull('returned_at')->count());
    }

    public function test_a_stopped_machine_cannot_be_checked_back_into_service(): void
    {
        // 다시 점검해서 통과시키는 길을 열어 두면, 앞사람이 발견한 결함을 뒷사람이
        // 「맞다」 로 밀고 나갈 수 있다. 해제는 사람이 한다.
        $equipment = $this->excavator();
        $equipment->update(['status' => Equipment::STATUS_NEEDS_INSPECTION]);
        $template = $this->service()->templateFor($equipment, 'pre_use');

        $result = $this->service()->submit($equipment, $this->user, 'pre_use',
            $this->answerAll($template, true));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('사용 금지', $result['error']);
        $this->assertSame(Equipment::STATUS_NEEDS_INSPECTION, $equipment->refresh()->status);
    }

    public function test_a_manager_can_clear_the_block_and_the_machine_comes_back(): void
    {
        $equipment = $this->excavator();
        $equipment->update(['status' => Equipment::STATUS_NEEDS_INSPECTION]);

        $manager = User::query()->create([
            'name' => 'M', 'email' => 'm@example.test', 'password' => Hash::make('x'),
            'access_role' => 'site_manager', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);

        $result = $this->service()->clearBlock($equipment, $manager, '브레이크 교체 완료');

        $this->assertTrue($result['success']);
        $this->assertSame(Equipment::STATUS_AVAILABLE, $equipment->refresh()->status);
    }

    public function test_a_non_critical_problem_is_recorded_but_does_not_stop_the_work(): void
    {
        // 타이어가 좀 닳았다고 그날 공정을 세우면, 다음부터 사람들이 전부 「맞다」 만
        // 누른다. 막는 것과 기록하는 것은 다르다.
        $equipment = $this->excavator();
        $template = $this->service()->templateFor($equipment, 'pre_use');
        $normal = $template->items->firstWhere('severity', EquipmentChecklistItem::NORMAL);
        $this->assertNotNull($normal);

        $answers = $this->answerAll($template, true);
        $answers[$normal->id] = ['ok' => false, 'note' => '타이어 마모'];

        $result = $this->service()->submit($equipment, $this->user, 'pre_use', $answers);

        $this->assertSame(EquipmentChecklistLog::FAIL, $result['result']);
        $this->assertSame(Equipment::STATUS_IN_USE, $equipment->refresh()->status);
    }

    public function test_an_unanswered_item_is_refused(): void
    {
        // 반쯤 채운 점검표는 «봤다» 는 기록이 되는데 실제로는 안 본 것이라,
        // 있는 것이 없는 것보다 나쁘다.
        $equipment = $this->excavator();
        $template = $this->service()->templateFor($equipment, 'pre_use');
        $answers = $this->answerAll($template, true);
        array_pop($answers);

        $result = $this->service()->submit($equipment, $this->user, 'pre_use', $answers);

        $this->assertFalse($result['success']);
        $this->assertSame(0, EquipmentChecklistLog::query()->count());
        $this->assertSame(Equipment::STATUS_AVAILABLE, $equipment->refresh()->status);
    }

    public function test_the_record_keeps_the_question_it_asked(): void
    {
        // 질문지는 나중에 고쳐진다. 참조만 걸어 두면 그 순간 과거 기록의 뜻이 바뀐다.
        $equipment = $this->excavator();
        $template = $this->service()->templateFor($equipment, 'pre_use');
        $item = $template->items->first();

        $answers = $this->answerAll($template, true);
        $answers[$item->id] = ['ok' => false, 'note' => '문제 있음'];
        $this->service()->submit($equipment, $this->user, 'pre_use', $answers);

        $log = EquipmentChecklistLog::query()->latest('id')->firstOrFail();
        $asked = $item->label_ko;

        // 질문지를 통째로 바꿔 본다.
        $item->update(['label_ko' => '완전히 다른 질문']);

        $log->refresh();
        $this->assertSame($asked, $log->answers[0]['label_ko'],
            '그날 무엇을 확인했는지가 기록 안에서 완결돼야 한다.');
    }

    public function test_the_return_check_gives_the_equipment_back(): void
    {
        $equipment = $this->excavator();
        $pre = $this->service()->templateFor($equipment, 'pre_use');
        $this->service()->submit($equipment, $this->user, 'pre_use', $this->answerAll($pre, true));

        $post = $this->service()->templateFor($equipment->refresh(), 'post_use');
        $result = $this->service()->submit($equipment, $this->user, 'post_use', $this->answerAll($post, true));

        $this->assertTrue($result['success']);
        $equipment->refresh();
        $this->assertSame(Equipment::STATUS_AVAILABLE, $equipment->status);
        $this->assertNull($equipment->employee_id);
        $this->assertSame(0, EquipmentRental::query()
            ->where('equipment_id', $equipment->id)->whereNull('returned_at')->count());
    }

    public function test_the_screen_offers_the_return_check_to_whoever_holds_it(): void
    {
        // 사람에게 «지금 빌리는 중입니까 돌려놓는 중입니까» 를 고르게 하지 않는다.
        // 시스템이 이미 아는 것을 묻는 화면은 반드시 잘못 눌린다.
        $equipment = $this->excavator();
        $pre = $this->service()->templateFor($equipment, 'pre_use');
        $this->service()->submit($equipment, $this->user, 'pre_use', $this->answerAll($pre, true));

        $screen = $this->service()->screen($equipment->refresh(), $this->user);
        $this->assertSame('post_use', $screen['stage']);

        // 다른 사람이 스캔하면 «사용 전» 이되, 누가 들고 있는지 말해 줘야 한다.
        $other = User::query()->create([
            'name' => 'C D', 'email' => 'cd@example.test', 'password' => Hash::make('x'),
            'access_role' => 'worker', 'access_scope' => 'assigned_sites', 'account_status' => 'active',
            'employee_id' => Employee::query()->create([
                'company_id' => $this->company->id, 'site_id' => $this->site->id,
                'employee_number' => 'W-2', 'first_name' => 'C', 'last_name' => 'D', 'name' => 'C D',
                'employment_status' => 'active',
            ])->id,
        ]);

        $otherScreen = $this->service()->screen($equipment->refresh(), $other);
        $this->assertSame('pre_use', $otherScreen['stage']);
        $this->assertSame('A B', $otherScreen['heldBy']);
    }

    // ── 알림 ────────────────────────────────────────────────────────────

    public function test_a_defect_reaches_the_alert_list(): void
    {
        // 화면을 열어야 보이는 숫자는 사건이 아니다. 반장의 아침 알림에 실려야 한다.
        $equipment = $this->excavator();
        $template = $this->service()->templateFor($equipment, 'pre_use');
        $critical = $template->items->firstWhere('severity', EquipmentChecklistItem::CRITICAL);

        $answers = $this->answerAll($template, true);
        $answers[$critical->id] = ['ok' => false, 'note' => '브레이크가 밀립니다'];
        $this->service()->submit($equipment, $this->user, 'pre_use', $answers);

        $alert = UnifiedAlert::query()->where('event_type', 'equipment_blocked')->first();
        $this->assertNotNull($alert, '장비를 세웠는데 아무에게도 안 알리면 그 장비는 그냥 멈춰 있는다.');
        $this->assertSame('critical', $alert->severity);
        $this->assertSame('SAFE', $alert->source_module, '치명 결함은 안전 사건이다 — 재고 알림에 섞으면 지나친다.');
        $this->assertStringContainsString($equipment->equipment_code, (string) $alert->title);
    }

    public function test_a_clean_check_raises_nothing(): void
    {
        // 정상 점검까지 알림을 띄우면 목록이 정상 기록으로 덮이고, 진짜 사건이 묻힌다.
        $equipment = $this->excavator();
        $template = $this->service()->templateFor($equipment, 'pre_use');
        $this->service()->submit($equipment, $this->user, 'pre_use', $this->answerAll($template, true));

        $this->assertSame(0, UnifiedAlert::query()
            ->whereIn('event_type', ['equipment_blocked', 'equipment_defect'])->count());
    }

    // ── 경고 ────────────────────────────────────────────────────────────

    public function test_it_warns_but_does_not_block_when_the_worker_has_not_clocked_in(): void
    {
        // 장비 점검을 막으면 사람이 점검 없이 그냥 쓴다. 문턱은 낮아야 한다.
        $screen = $this->service()->screen($this->excavator(), $this->user);

        $this->assertNotEmpty($screen['warnings']);
        $this->assertStringContainsString('출근', implode(' ', $screen['warnings']));
        $this->assertNotNull($screen['template'], '경고가 있다고 점검표를 감추면 안 된다.');
    }

    public function test_it_warns_when_the_equipment_belongs_to_another_site(): void
    {
        $other = Site::query()->create([
            'company_id' => $this->company->id, 'code' => 'S-2', 'name' => 'Other Site',
            'country' => 'US', 'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        $equipment = $this->excavator();
        $equipment->update(['site_id' => $other->id]);

        $screen = $this->service()->screen($equipment, $this->user);

        $this->assertStringContainsString('Other Site', implode(' ', $screen['warnings']));
    }

    // ── QR ──────────────────────────────────────────────────────────────

    public function test_the_qr_token_is_stable_once_it_exists(): void
    {
        // 토큰을 다시 만들면 <b>이미 장비에 붙어 있는 스티커가 그 자리에서 죽는다.</b>
        $equipment = $this->excavator();

        $first = $equipment->ensureQrToken();
        $second = $equipment->fresh()->ensureQrToken();

        $this->assertSame($first, $second);
    }

    public function test_the_qr_token_cannot_be_guessed_from_the_equipment_number(): void
    {
        // 스티커에 장비 번호를 그대로 박으면 번호를 올려가며 남의 장비 화면을 열 수 있다.
        $equipment = $this->excavator();
        $token = $equipment->ensureQrToken();

        $this->assertStringStartsWith('eq_', $token);
        $this->assertGreaterThan(40, strlen($token));
        $this->assertNull(Equipment::forQrToken((string) $equipment->id));
        $this->assertSame($equipment->id, Equipment::forQrToken($token)?->id);
    }

    // ── 경로 ────────────────────────────────────────────────────────────

    public function test_scanning_the_sticker_opens_the_checklist(): void
    {
        $equipment = $this->excavator();
        $token = $equipment->ensureQrToken();

        $this->actingAs($this->user)
            ->get("/eq/{$token}")
            ->assertOk()
            ->assertSee('Excavator 320', false);
    }

    public function test_scanning_it_logged_out_sends_you_to_login_and_back(): void
    {
        // 로그인 뒤 홈으로 떨어뜨리면 그 사람은 장비 앞에 서서 길을 잃는다.
        $equipment = $this->excavator();
        $token = $equipment->ensureQrToken();

        $this->get("/eq/{$token}")->assertRedirect(route('login'));
        $this->assertStringContainsString("/eq/{$token}", (string) session('url.intended'));
    }

    public function test_a_dead_sticker_says_so_instead_of_crashing(): void
    {
        $this->actingAs($this->user)
            ->get('/eq/eq_thisdoesnotexist')
            ->assertRedirect(route('attendance-app.index'));
    }

    public function test_submitting_through_the_route_records_the_photo(): void
    {
        Storage::fake('public');
        $equipment = $this->excavator();
        $token = $equipment->ensureQrToken();
        $template = $this->service()->templateFor($equipment, 'pre_use');
        $item = $template->items->firstWhere('severity', EquipmentChecklistItem::NORMAL);

        $payload = ['stage' => 'pre_use', 'lang' => 'ko', 'answers' => []];
        foreach ($template->items as $i) {
            $payload['answers'][$i->id] = ['ok' => $i->id === $item->id ? '0' : '1'];
        }
        $payload['photo_'.$item->id] = UploadedFile::fake()->image('damage.jpg');

        $this->actingAs($this->user)
            ->post("/eq/{$token}/submit", $payload)
            ->assertOk()
            ->assertJson(['success' => true, 'result' => EquipmentChecklistLog::FAIL]);

        $log = EquipmentChecklistLog::query()->latest('id')->firstOrFail();
        $this->assertCount(1, $log->photos, '이상을 사진 없이 적으면 반장이 가서 다시 봐야 한다.');
        Storage::disk('public')->assertExists($log->photos[0]);
    }

    public function test_the_sticker_sheet_is_not_open_to_everyone(): void
    {
        // 스티커 한 장이 곧 그 장비의 열쇠다. 작업자 계정이 전 장비 토큰을 한 번에
        // 받아 갈 수 있으면 토큰을 추측 불가능하게 만든 뜻이 없어진다.
        $this->actingAs($this->user)->get('/equipment-stickers')->assertForbidden();

        $manager = User::query()->create([
            'name' => 'M', 'email' => 'm2@example.test', 'password' => Hash::make('x'),
            'access_role' => 'site_manager', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);
        $this->excavator();

        $this->actingAs($manager)->get('/equipment-stickers')->assertOk()->assertSee('조작부 근처');
    }

    public function test_the_worker_app_shows_what_that_person_is_holding(): void
    {
        // 스티커가 더러워졌거나 장비가 창고 안쪽이면 반납할 길이 막힌다.
        $equipment = $this->excavator();
        $template = $this->service()->templateFor($equipment, 'pre_use');
        $this->service()->submit($equipment, $this->user, 'pre_use', $this->answerAll($template, true));

        $home = app(\App\Services\Attendance\WorkerAttendanceService::class)->home($this->employee);

        $this->assertCount(1, $home['myEquipment']);
        $this->assertSame($equipment->equipment_code, $home['myEquipment'][0]['code']);
        $this->assertStringContainsString('/eq/', $home['myEquipment'][0]['url']);
    }
}
