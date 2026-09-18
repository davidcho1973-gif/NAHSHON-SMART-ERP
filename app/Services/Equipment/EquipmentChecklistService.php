<?php

namespace App\Services\Equipment;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\EquipmentChecklistItem;
use App\Models\EquipmentChecklistLog;
use App\Models\EquipmentChecklistTemplate;
use App\Models\User;
use App\Services\Alerts\UnifiedAlertService;
use App\Support\DefaultEquipmentChecklists;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 장비 사용 점검 — 규칙은 여기 한 곳에만 있다.
 *
 * ── 왜 이 기능이 있나 ──────────────────────────────────────────────────
 * 장비 대장은 «무엇이 어디 있나» 까지만 안다. 그 장비를 오늘 쓰기 전에 <b>누가
 * 봤는지</b> 는 아무 데도 없었다. 사고가 나면 그때부터 아무도 설명하지 못한다 —
 * 브레이크가 언제부터 밀렸는지, 그 슬링의 끊어진 가닥을 본 사람이 있었는지.
 *
 * ── 흐름 ───────────────────────────────────────────────────────────────
 * 작업자가 <b>폰 기본 카메라</b>로 장비에 붙은 QR 을 찍는다 → 점검 화면이 열린다
 * → 항목마다 「맞다 / 아니다」 → 제출. 앱 안의 스캐너를 쓰지 않는 이유는 아이폰
 * 사파리에 BarcodeDetector 가 없기 때문이다(출퇴근 게이트 QR 과 같은 방식).
 *
 * ── 판정 ───────────────────────────────────────────────────────────────
 *  · 전부 확인   → 통과. 그 사람에게 불출(수불 한 줄)되고 상태가 «사용중» 이 된다.
 *  · 치명 이상   → <b>차단.</b> 불출하지 않고 장비를 «점검필요» 로 세운다.
 *                  가동 가능 대수에서 빠지고 배정 목록에도 안 뜬다.
 *  · 일반 이상   → 기록하고 쓰게 둔다. 알림으로 반장이 본다.
 *
 * ── 왜 «차단» 이 상태를 바꾸나 ─────────────────────────────────────────
 * 기록만 남기고 상태를 안 바꾸면, 다음 사람이 같은 장비를 스캔했을 때 아무 일도
 * 없었던 것처럼 열린다. 앞사람이 발견한 결함이 뒷사람에게 전달되지 않는 점검은
 * 서류일 뿐이다. 그래서 장비 대장의 상태를 바꾸고, 해제는 사람이 한다.
 */
class EquipmentChecklistService
{
    public function __construct(
        private readonly EquipmentAssignmentService $assignments,
        private readonly UnifiedAlertService $alerts,
    ) {
    }

    // ── 질문지 고르기 ───────────────────────────────────────────────────

    /**
     * 이 장비에 쓸 질문지. <b>좁은 것이 이긴다</b> — 그 한 대 전용 > 품목명 > 공종 >
     * 대분류. 그래야 「굴착기 전부」 위에 「그 한 대만」 을 덧댈 수 있다.
     *
     * 현장 전용 질문지가 있으면 회사 공통보다 먼저다. 원청사마다 요구가 다르다.
     */
    public function templateFor(Equipment $equipment, string $stage): ?EquipmentChecklistTemplate
    {
        $found = $this->findTemplate($equipment, $stage);
        if ($found) {
            return $found;
        }

        // 기본표가 아직 DB 에 안 들어온 배포다. 여기서 한 번 깔고 다시 찾는다.
        //
        // 시드 마이그레이션으로 넣지 않는 이유: 그러면 질문 한 줄을 고칠 때마다 새
        // 마이그레이션을 써야 하고, 이미 들어간 배포는 안 바뀐다. 기본표의 원본은
        // 코드(DefaultEquipmentChecklists)이고 DB 는 그 복사본이다. 사람이 고친
        // 질문지(is_default=false)는 여기서도 절대 안 덮는다.
        // «이미 깔았나» 를 클래스 변수로 기억하지 않는다. 처음에 그렇게 썼다가
        // 시험에서 잡혔다: 그 표식은 <b>요청이 끝나도 안 지워져서</b>, 한 번 확인한
        // 프로세스는 그 뒤로 영영 기본표를 안 깐다. 같은 프로세스가 여러 요청을
        // 받는 곳(큐 작업자·Octane)에서 그대로 재현된다.
        //
        // 대신 표를 한 번 본다. 질문지를 못 찾을 때만 도는 길이라 평소 비용이 없다.
        if (EquipmentChecklistTemplate::query()->where('is_default', true)->exists()) {
            return null;   // 기본표는 있다. 이 장비에 맞는 것이 없을 뿐이다.
        }

        return $this->ensureDefaults() > 0 ? $this->findTemplate($equipment, $stage) : null;
    }

    private function findTemplate(Equipment $equipment, string $stage): ?EquipmentChecklistTemplate
    {
        $candidates = [
            ['equipment', (string) $equipment->id],
            ['equipment_type', (string) $equipment->equipment_type],
            ['trade', $equipment->resolvedTrade()],
            ['category_group', $equipment->resolvedGroup()],
            // 마지막 그물 — 공종을 가리지 않는 한 벌(반납 점검이 이것이다). 이 줄이
            // 없으면 «모든 장비 공통» 질문지를 만들 길이 없어, 같은 질문을 공종
            // 수만큼 베껴야 한다. 베낀 것은 한 곳만 고쳐진다.
            ['category_group', '*'],
        ];

        foreach ($candidates as [$scopeType, $value]) {
            if (blank($value)) {
                continue;
            }

            $template = EquipmentChecklistTemplate::query()
                ->with(['items' => fn ($q) => $q->where('status', 'active')
                    ->whereIn('stage', [$stage, 'both'])])
                ->where('status', 'active')
                ->where('scope_type', $scopeType)
                ->where('scope_value', $value)
                ->whereIn('stage', [$stage, 'both'])
                ->where(fn ($q) => $q->whereNull('site_id')->orWhere('site_id', $equipment->site_id))
                // 현장 전용이 회사 공통을 이긴다. 그 다음은 사람이 만든 것이 기본표를 이긴다.
                ->orderByRaw('CASE WHEN site_id IS NULL THEN 1 ELSE 0 END')
                ->orderBy('is_default')
                ->orderBy('sort_order')
                ->first();

            if ($template && $template->items->isNotEmpty()) {
                return $template;
            }
        }

        return null;
    }

    /**
     * 코드에 적힌 기본표를 DB 에 복사한다. 이미 있으면 그대로 둔다 —
     * 사람이 고친 줄(is_default=false)은 절대 덮지 않는다.
     *
     * @return int 새로 만든 질문지 수
     */
    public function ensureDefaults(): int
    {
        $made = 0;

        foreach (DefaultEquipmentChecklists::all() as $trade => $spec) {
            $made += $this->ensureOne('trade', $trade, $spec['name']['ko'],
                DefaultEquipmentChecklists::PRE, $spec['items']) ? 1 : 0;
        }

        // 반납 점검은 공종과 무관하게 한 벌이다. 장비를 돌려놓을 때 묻는 것은
        // 굴착기든 그라인더든 같다 — 공종마다 베끼면 고칠 곳이 여덟 군데가 된다.
        $made += $this->ensureOne('category_group', '*', '사용 종료 점검',
            DefaultEquipmentChecklists::POST, DefaultEquipmentChecklists::returnItems()) ? 1 : 0;

        return $made;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function ensureOne(string $scopeType, string $scopeValue, string $name, string $stage, array $items): bool
    {
        $exists = EquipmentChecklistTemplate::query()
            ->where('scope_type', $scopeType)
            ->where('scope_value', $scopeValue)
            ->where('stage', $stage)
            ->exists();

        if ($exists) {
            return false;
        }

        $template = EquipmentChecklistTemplate::query()->create([
            'scope_type' => $scopeType,
            'scope_value' => $scopeValue,
            'name' => $name,
            'stage' => $stage,
            'status' => 'active',
            'is_default' => true,
        ]);

        foreach (array_values($items) as $i => $item) {
            EquipmentChecklistItem::query()->create([
                'equipment_checklist_template_id' => $template->id,
                'sort_order' => ($i + 1) * 10,
                'label_ko' => $item['ko'],
                'label_en' => $item['en'],
                'label_es' => $item['es'],
                'severity' => $item['severity'] ?? EquipmentChecklistItem::NORMAL,
                'requires_photo_on_fail' => true,
                'stage' => $stage,
                'status' => 'active',
            ]);
        }

        return true;
    }

    // ── 화면이 필요한 것 ────────────────────────────────────────────────

    /**
     * 점검 화면 한 장에 필요한 모든 것.
     *
     * @return array<string, mixed>
     */
    public function screen(Equipment $equipment, ?User $user, string $lang = 'ko'): array
    {
        $equipment->loadMissing(['site', 'activeRental.employee', 'latestChecklistLog.employee']);
        $employee = $user?->employee;

        // 「사용 시작」 인가 「사용 종료」 인가 — 사람에게 고르게 하지 않고 상태로 정한다.
        // 손에 든 것을 지금 빌리는 중인지 돌려놓는 중인지는 시스템이 이미 안다.
        $open = $equipment->activeRental;
        $mine = $open && $employee && (int) $open->employee_id === (int) $employee->id;
        $stage = $mine ? EquipmentChecklistTemplate::STAGE_POST : EquipmentChecklistTemplate::STAGE_PRE;

        $template = $this->templateFor($equipment, $stage);

        return [
            'equipment' => [
                'id' => $equipment->id,
                'code' => $equipment->equipment_code,
                'name' => $equipment->equipment_type,
                'model' => $equipment->model,
                'vendor' => $equipment->vendor,
                'status' => $equipment->status,
                'photo' => $equipment->photo_front,
                'site' => $equipment->site?->name,
                'siteCode' => $equipment->site?->code,
                'lastCheckedAt' => $equipment->last_checked_at?->toDateTimeString(),
                'inspectionDueOn' => $equipment->inspection_due_on?->toDateString(),
            ],
            'stage' => $stage,
            'blocked' => $equipment->status === Equipment::STATUS_NEEDS_INSPECTION,
            'heldBy' => $open && ! $mine ? ($open->employee?->name ?: '다른 작업자') : null,
            'template' => $template ? [
                'id' => $template->id,
                'name' => $template->name,
                'items' => $template->items->map(fn (EquipmentChecklistItem $i): array => [
                    'id' => $i->id,
                    'label' => $i->label($lang),
                    'help' => $i->help($lang),
                    'critical' => $i->isCritical(),
                    'photoOnFail' => $i->requires_photo_on_fail,
                ])->values()->all(),
            ] : null,
            'warnings' => $employee ? $this->warnings($equipment, $employee, $lang) : [],
            'worker' => $employee ? ['name' => $employee->name, 'number' => $employee->employee_number] : null,
        ];
    }

    /**
     * 점검을 막지는 않지만 말은 해야 하는 것들.
     *
     * <b>경고로만 두는 이유:</b> 장비 점검을 막으면 사람이 점검 없이 그냥 쓴다.
     * 막아서 얻는 것보다 잃는 것이 크다 — 안전 점검은 문턱이 낮아야 한다.
     *
     * @return array<int, string>
     */
    private function warnings(Equipment $equipment, Employee $employee, string $lang): array
    {
        $t = self::WARNINGS[$lang] ?? self::WARNINGS['ko'];
        $out = [];

        // ① 오늘 출근을 안 찍었다. 장비를 쓰는데 근무 기록이 없으면 그 시간이 임금에
        //    안 잡히고, 사고가 나면 «그날 현장에 있었나» 부터 다투게 된다.
        $today = Carbon::now($equipment->site?->timezone ?: config('app.timezone'))->toDateString();
        $clockedIn = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->where('attendance_date', $today)
            ->where('event_type', 'clock_in')
            ->where('status', '!=', 'rejected')
            ->exists();
        if (! $clockedIn) {
            $out[] = $t['no_clock_in'];
        }

        // ② 이 장비가 있는 현장과 내 배정 현장이 다르다. 남의 현장 장비를 들고 가면
        //    그 현장에서는 «없어졌다» 가 되고, 임대료도 엉뚱한 현장 원가로 잡힌다.
        if ($equipment->site_id && $employee->site_id && (int) $equipment->site_id !== (int) $employee->site_id) {
            $out[] = str_replace(':site', (string) ($equipment->site?->name ?: $equipment->site_id), $t['other_site']);
        }

        // ③ 안전교육이 만료됐다.
        if ($employee->safety_training_expires_on && $employee->safety_training_expires_on->isPast()) {
            $out[] = $t['safety_expired'];
        }

        // ④ 장비 자체의 정기점검 기한이 지났다. 사용 전 점검과 별개의 정비 주기다.
        if ($equipment->inspection_due_on && $equipment->inspection_due_on->isPast()) {
            $out[] = $t['inspection_overdue'];
        }

        return $out;
    }

    // ── 제출 ────────────────────────────────────────────────────────────

    /**
     * 점검 제출. 판정하고, 장비 상태를 맞추고, 수불을 열거나 닫고, 알림을 띄운다.
     *
     * @param  array<int|string, array{ok?: bool, note?: string|null, photo?: string|null}>  $answers  항목 id => 답
     * @param  array<string, mixed>|null  $signal  lat/lng/accuracy
     * @return array<string, mixed>
     */
    public function submit(
        Equipment $equipment,
        User $user,
        string $stage,
        array $answers,
        ?array $signal = null,
        string $lang = 'ko',
        ?string $notes = null,
    ): array {
        $t = self::MESSAGES[$lang] ?? self::MESSAGES['ko'];
        $employee = $user->employee;

        if (! $employee) {
            return ['success' => false, 'error' => $t['no_employee']];
        }

        $isPreStage = $stage === EquipmentChecklistTemplate::STAGE_PRE;

        // 세워 둔 장비를 다시 점검해서 통과시키는 길을 열어 두면, 앞사람이 발견한
        // 결함을 뒷사람이 「맞다」 로 밀고 나갈 수 있다. 해제는 사람이 한다.
        if ($isPreStage && $equipment->status === Equipment::STATUS_NEEDS_INSPECTION) {
            return ['success' => false, 'error' => $t['is_blocked']];
        }

        $template = $this->templateFor($equipment, $stage);
        if (! $template) {
            return ['success' => false, 'error' => $t['no_template']];
        }

        // 빠뜨린 항목이 있으면 안 받는다. 반쯤 채운 점검표는 «봤다» 는 기록이
        // 되는데 실제로는 안 본 것이라, 있는 것이 없는 것보다 나쁘다.
        $recorded = [];
        $failed = 0;
        $criticalFailed = 0;

        foreach ($template->items as $item) {
            $answer = $answers[$item->id] ?? $answers[(string) $item->id] ?? null;
            if (! is_array($answer) || ! array_key_exists('ok', $answer) || ! is_bool($answer['ok'])) {
                return ['success' => false, 'error' => $t['incomplete']];
            }

            $ok = $answer['ok'];
            if (! $ok) {
                $failed++;
                if ($item->isCritical()) {
                    $criticalFailed++;
                }
            }

            // 질문 문장을 그대로 베껴 담는다. 질문지가 나중에 바뀌어도 그날 무엇을
            // 확인했는지가 이 줄 안에서 완결돼야 한다.
            $recorded[] = [
                'item_id' => $item->id,
                'label' => $item->label($lang),
                'label_ko' => $item->label_ko,
                'severity' => $item->severity,
                'ok' => $ok,
                'note' => is_string($answer['note'] ?? null) ? trim($answer['note']) : null,
                'photo' => is_string($answer['photo'] ?? null) ? $answer['photo'] : null,
            ];
        }

        $isPre = $isPreStage;
        $result = match (true) {
            $criticalFailed > 0 => EquipmentChecklistLog::BLOCKED,
            $failed > 0 => EquipmentChecklistLog::FAIL,
            default => EquipmentChecklistLog::PASS,
        };

        $log = DB::transaction(function () use (
            $equipment, $employee, $user, $template, $stage, $isPre,
            $recorded, $failed, $criticalFailed, $result, $signal, $notes
        ): EquipmentChecklistLog {
            $rental = null;

            if ($isPre && $result !== EquipmentChecklistLog::BLOCKED) {
                // 불출은 EquipmentAssignmentService 한 곳에서만 한다. 여기서 수불을
                // 직접 쓰면 규칙이 두 벌이 되고, 두 벌이면 «이 장비 지금 어디 있나» 에
                // 답이 여러 개가 된다(그 서비스 주석에 적힌 그대로다).
                $rental = $this->assignments->assign($equipment, [
                    'company_id' => $employee->company_id,
                    'team_id' => $employee->team_id,
                    'employee_id' => $employee->id,
                    'site_id' => $equipment->site_id ?: $employee->site_id,
                    'notes' => '사용 전 점검 통과 — '.$employee->name,
                ]);
            }

            if (! $isPre) {
                $rental = $equipment->activeRental;
                $this->assignments->returnToStock($equipment, $failed > 0 ? '반납 점검에서 이상 보고됨' : null);
            }

            if ($result === EquipmentChecklistLog::BLOCKED) {
                // 불출하지 않고 장비를 세운다. 다음 사람이 스캔하면 이 상태가 먼저 보인다.
                $equipment->update(['status' => Equipment::STATUS_NEEDS_INSPECTION]);
            }

            $equipment->forceFill(['last_checked_at' => now()])->save();

            return EquipmentChecklistLog::query()->create([
                'equipment_id' => $equipment->id,
                'equipment_checklist_template_id' => $template->id,
                'equipment_rental_id' => $rental?->id,
                'company_id' => $equipment->company_id ?: $employee->company_id,
                'site_id' => $equipment->site_id ?: $employee->site_id,
                'team_id' => $employee->team_id,
                'employee_id' => $employee->id,
                'user_id' => $user->id,
                'stage' => $stage,
                'result' => $result,
                'failed_count' => $failed,
                'critical_failed_count' => $criticalFailed,
                'answers' => $recorded,
                'photos' => array_values(array_filter(array_column($recorded, 'photo'))),
                'notes' => filled($notes) ? trim((string) $notes) : null,
                'latitude' => $signal['lat'] ?? null,
                'longitude' => $signal['lng'] ?? null,
                'accuracy_m' => isset($signal['accuracy']) ? (int) $signal['accuracy'] : null,
                'submitted_at' => now(),
            ]);
        });

        if ($failed > 0) {
            $this->raiseAlert($equipment, $log);
        }

        return [
            'success' => true,
            'result' => $result,
            'logId' => $log->id,
            'message' => match ($result) {
                EquipmentChecklistLog::BLOCKED => $t['blocked'],
                EquipmentChecklistLog::FAIL => $isPre ? $t['fail_pre'] : $t['fail_post'],
                default => $isPre ? $t['pass_pre'] : $t['pass_post'],
            },
        ];
    }

    /**
     * 결함을 통합 알림에 올린다.
     *
     * 화면을 열어야 보이는 숫자는 사건이 아니다 — 여기 실려야 반장·관리자의 아침
     * 알림에 뜬다. 지문(fingerprint)에 점검 기록 id 를 넣어 한 건이 한 줄이 되게 한다.
     */
    private function raiseAlert(Equipment $equipment, EquipmentChecklistLog $log): void
    {
        $blocked = $log->result === EquipmentChecklistLog::BLOCKED;
        $bad = array_column($log->failedAnswers(), 'label_ko');

        $this->alerts->emit('equipment-check-fail:'.$log->id, [
            'company_id' => $log->company_id,
            'site_id' => $log->site_id,
            'employee_id' => $log->employee_id,
            // 치명 결함은 안전 사건이다. 장비 재고 알림에 섞으면 재고 정리하다 지나친다.
            'source_module' => $blocked ? 'SAFE' : 'INV',
            'source_type' => EquipmentChecklistLog::class,
            'source_id' => (string) $log->id,
            'event_type' => $blocked ? 'equipment_blocked' : 'equipment_defect',
            'severity' => $blocked ? 'critical' : 'warning',
            'title' => ($blocked ? '사용 금지: ' : '장비 이상: ')
                .$equipment->equipment_code.' '.(string) $equipment->equipment_type,
            'content' => ($blocked
                    ? '사용 전 점검에서 치명 항목이 걸려 이 장비를 세웠습니다. '
                    : '점검에서 이상이 보고됐습니다. ')
                .($bad !== [] ? '['.implode(' / ', array_slice($bad, 0, 3)).'] ' : '')
                .'점검자: '.($log->employee?->name ?: '-'),
            'action_url' => '/?view=equipment',
            'occurred_at' => $log->submitted_at,
        ]);
    }

    /**
     * 사용 금지를 푼다 — 사람이 고쳤거나 확인했다는 뜻이다.
     *
     * 시스템이 스스로 풀지 않는다. 다음 점검이 통과하면 자동으로 풀리게 해 두면,
     * 고장난 장비를 그냥 「맞다」 로 밀고 나가는 길이 열린다.
     *
     * @return array<string, mixed>
     */
    public function clearBlock(Equipment $equipment, User $user, ?string $note = null): array
    {
        if ($equipment->status !== Equipment::STATUS_NEEDS_INSPECTION) {
            return ['success' => false, 'error' => '이 장비는 사용 금지 상태가 아닙니다.'];
        }

        $equipment->update(['status' => Equipment::STATUS_AVAILABLE]);

        $this->alerts->emit('equipment-block-cleared:'.$equipment->id.':'.now()->timestamp, [
            'company_id' => $equipment->company_id,
            'site_id' => $equipment->site_id,
            'source_module' => 'INV',
            'source_type' => Equipment::class,
            'source_id' => (string) $equipment->id,
            'event_type' => 'equipment_block_cleared',
            'severity' => 'info',
            'status' => 'completed',
            'title' => '사용 금지 해제: '.$equipment->equipment_code,
            'content' => ($user->name ?: '관리자').' 님이 해제했습니다.'.(filled($note) ? ' 사유: '.$note : ''),
            'action_url' => '/?view=equipment',
        ]);

        return ['success' => true, 'status' => $equipment->status];
    }

    /** @var array<string, array<string, string>> */
    private const MESSAGES = [
        'ko' => [
            'no_employee' => '연결된 직원 정보가 없습니다. 관리자에게 문의해 주세요.',
            'no_template' => '이 장비에 맞는 점검표가 아직 없습니다. 관리자에게 알려 주세요.',
            'is_blocked' => '이 장비는 사용 금지 상태입니다. 반장이 해제해야 다시 쓸 수 있습니다.',
            'incomplete' => 'answer 를 빠뜨린 항목이 있습니다. 모든 항목에 답해 주세요.',
            'pass_pre' => '점검 완료. 이 장비를 사용하셔도 됩니다.',
            'fail_pre' => '접수했습니다. 이상 항목이 반장에게 전달됐습니다. 사용은 가능합니다.',
            'blocked' => '이 장비는 사용하지 마세요. 치명 항목에 이상이 있어 사용 금지로 표시했고 반장에게 알렸습니다.',
            'pass_post' => '반납 완료. 수고하셨습니다.',
            'fail_post' => '반납했습니다. 보고한 이상은 반장에게 전달됐습니다.',
        ],
        'en' => [
            'no_employee' => 'No employee record is linked to your account. Contact your manager.',
            'no_template' => 'There is no checklist for this equipment yet. Please tell your manager.',
            'is_blocked' => 'This equipment is out of service. Your foreman must clear it before it can be used.',
            'incomplete' => 'Some items are unanswered. Please answer every item.',
            'pass_pre' => 'Check complete. You may use this equipment.',
            'fail_pre' => 'Received. The problems were sent to your foreman. You may still use it.',
            'blocked' => 'Do not use this equipment. A critical item failed, so it is marked out of service and your foreman has been notified.',
            'pass_post' => 'Returned. Thank you.',
            'fail_post' => 'Returned. The problems you reported were sent to your foreman.',
        ],
        'es' => [
            'no_employee' => 'Su cuenta no está vinculada a un empleado. Hable con su supervisor.',
            'no_template' => 'Todavía no hay lista de revisión para este equipo. Avise a su supervisor.',
            'is_blocked' => 'Este equipo está fuera de servicio. Su capataz debe liberarlo antes de usarlo.',
            'incomplete' => 'Faltan respuestas. Conteste todos los puntos.',
            'pass_pre' => 'Revisión completa. Puede usar este equipo.',
            'fail_pre' => 'Recibido. Los problemas se enviaron a su capataz. Puede usarlo.',
            'blocked' => 'No use este equipo. Falló un punto crítico, quedó fuera de servicio y se avisó a su capataz.',
            'pass_post' => 'Devuelto. Gracias.',
            'fail_post' => 'Devuelto. Los problemas que reportó se enviaron a su capataz.',
        ],
    ];

    /** @var array<string, array<string, string>> */
    private const WARNINGS = [
        'ko' => [
            'no_clock_in' => '오늘 출근이 기록되지 않았습니다. 먼저 출근을 찍어 주세요.',
            'other_site' => '이 장비는 :site 현장 소속입니다. 가져가시려면 반장에게 말씀해 주세요.',
            'safety_expired' => '안전교육 유효기간이 지났습니다. 사무실에 갱신을 요청해 주세요.',
            'inspection_overdue' => '이 장비는 정기점검 기한이 지났습니다.',
        ],
        'en' => [
            'no_clock_in' => 'You have not clocked in today. Please clock in first.',
            'other_site' => 'This equipment belongs to :site. Tell your foreman before taking it.',
            'safety_expired' => 'Your safety training has expired. Ask the office to renew it.',
            'inspection_overdue' => 'This equipment is past its scheduled inspection date.',
        ],
        'es' => [
            'no_clock_in' => 'Hoy no ha marcado entrada. Marque la entrada primero.',
            'other_site' => 'Este equipo pertenece a :site. Avise a su capataz antes de llevarlo.',
            'safety_expired' => 'Su capacitación de seguridad venció. Pida a la oficina que la renueve.',
            'inspection_overdue' => 'Este equipo pasó su fecha de inspección programada.',
        ],
    ];
}
