<?php

namespace App\Services\Admin;

use App\Models\Equipment;
use App\Models\EquipmentChecklistItem;
use App\Models\EquipmentChecklistLog;
use App\Models\EquipmentChecklistTemplate;
use App\Models\Site;
use App\Models\User;
use App\Services\Equipment\EquipmentChecklistService;
use Illuminate\Support\Carbon;

/**
 * 장비 점검 — 관리 화면 쪽.
 *
 * 화면이 답해야 하는 것은 세 가지뿐이다.
 *   ① 지금 <b>못 쓰는 장비</b>가 있나 (있으면 오늘 공정이 걸린다)
 *   ② 오늘 누가 무엇을 점검했나
 *   ③ 질문지를 고칠 수 있나
 *
 * 나머지 숫자는 넣지 않는다. 현황판에 숫자를 늘리면 정작 ①이 안 보인다.
 */
class EquipmentCheckAdminService
{
    /** 점검 기록을 볼 수 있는 사람. 반장도 자기 조 장비 상태는 알아야 한다. */
    private const VIEW_ROLES = ['super_admin', 'admin', 'site_manager', 'safety_manager', 'hr_manager', 'foreman'];

    /** 사용 금지를 풀거나 질문지를 고치는 사람. 반장은 뺀다 — 자기가 막은 것을 자기가 푼다. */
    private const MANAGE_ROLES = ['super_admin', 'admin', 'site_manager', 'safety_manager'];

    public function __construct(private readonly EquipmentChecklistService $checklists)
    {
    }

    public function canView(?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user !== null && in_array($user->access_role, self::VIEW_ROLES, true);
    }

    public function canManage(?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user !== null && in_array($user->access_role, self::MANAGE_ROLES, true);
    }

    /**
     * 화면 한 장.
     *
     * @return array<string, mixed>
     */
    public function board(string $siteId = 'ALL', int $days = 14): array
    {
        $user = auth()->user();
        if (! $this->canView($user)) {
            return ['success' => false, 'error' => '장비 점검 기록을 볼 권한이 없습니다.'];
        }

        $site = $this->resolveSite($siteId);
        $since = Carbon::now()->subDays(max(1, min($days, 90)))->startOfDay();

        $logs = EquipmentChecklistLog::query()
            ->with(['equipment:id,equipment_code,equipment_type,status', 'employee:id,name', 'site:id,code,name'])
            ->when($site, fn ($q) => $q->where('site_id', $site->id))
            ->where('submitted_at', '>=', $since)
            ->orderByDesc('submitted_at')
            ->limit(400)
            ->get();

        // 못 쓰는 장비 — 이 목록이 비어 있는 것이 정상이다. 비어 있지 않으면 오늘 일이 걸린다.
        $blocked = Equipment::query()->visibleTo($user)
            ->with(['site:id,code,name', 'latestChecklistLog.employee:id,name'])
            ->where('status', Equipment::STATUS_NEEDS_INSPECTION)
            ->when($site, fn ($q) => $q->where('site_id', $site->id))
            ->orderBy('equipment_code')
            ->get()
            ->map(fn (Equipment $e): array => [
                'id' => $e->id,
                'code' => $e->equipment_code,
                'name' => $e->equipment_type,
                'site' => $e->site?->name,
                'since' => $e->latestChecklistLog?->submitted_at?->toDateTimeString(),
                'by' => $e->latestChecklistLog?->employee?->name,
                'reasons' => array_column($e->latestChecklistLog?->failedAnswers() ?? [], 'label_ko'),
            ])->values()->all();

        // 스티커를 아직 안 붙인 장비. 점검표를 아무리 잘 만들어도 QR 이 안 붙어 있으면
        // 아무 일도 일어나지 않는다 — 이 숫자가 이 기능의 실제 보급률이다.
        $neverChecked = Equipment::query()->visibleTo($user)
            ->where(fn ($q) => $q->where('is_bulk', false)->orWhereNull('is_bulk'))
            ->when($site, fn ($q) => $q->where('site_id', $site->id))
            ->whereNull('last_checked_at')
            ->count();

        return [
            'success' => true,
            'canManage' => $this->canManage($user),
            'blocked' => $blocked,
            'neverChecked' => $neverChecked,
            'stickerUrl' => route('equipment-checklist.sheet', $site ? ['site' => $site->id] : []),
            'logs' => $logs->map(fn (EquipmentChecklistLog $l): array => [
                'id' => $l->id,
                'at' => $l->submitted_at?->toDateTimeString(),
                'stage' => $l->stage === EquipmentChecklistTemplate::STAGE_POST ? '반납' : '사용 전',
                'result' => match ($l->result) {
                    EquipmentChecklistLog::BLOCKED => '사용 금지',
                    EquipmentChecklistLog::FAIL => '이상',
                    default => '정상',
                },
                'resultCode' => $l->result,
                'equipment' => trim(($l->equipment?->equipment_code ?: '').' '.($l->equipment?->equipment_type ?: '')),
                'by' => $l->employee?->name,
                'site' => $l->site?->name,
                'failed' => $l->failed_count,
                'reasons' => array_map(
                    fn (array $a): string => $a['label_ko'].(filled($a['note'] ?? null) ? ' — '.$a['note'] : ''),
                    $l->failedAnswers(),
                ),
                'photos' => array_map(
                    fn (string $p): string => route('equipment-checklist.photo', ['path' => $p]),
                    array_values(array_filter((array) $l->photos)),
                ),
            ])->values()->all(),
        ];
    }

    /**
     * 사용 금지 해제. 사람이 고쳤거나 확인했다는 뜻이다.
     *
     * @return array<string, mixed>
     */
    public function clearBlock(mixed $equipmentId, ?string $note = null): array
    {
        $user = auth()->user();
        if (! $this->canManage($user)) {
            return ['success' => false, 'error' => '사용 금지를 해제할 권한이 없습니다.'];
        }

        $equipment = Equipment::query()->visibleTo($user)->find((int) $equipmentId);
        if (! $equipment) {
            return ['success' => false, 'error' => '장비를 찾지 못했습니다.'];
        }

        return $this->checklists->clearBlock($equipment, $user, $note);
    }

    // ── 질문지 ──────────────────────────────────────────────────────────

    /**
     * 질문지 목록. 항목까지 같이 내려보낸다 — 화면이 목록·상세를 따로 부르면
     * 두 번 기다리고, 그 사이에 바뀐 것을 화면이 모른다.
     *
     * @return array<string, mixed>
     */
    public function templates(): array
    {
        if (! $this->canView()) {
            return ['success' => false, 'error' => '권한이 없습니다.'];
        }

        // 화면을 처음 여는 배포에서도 기본표가 보여야 한다.
        $this->checklists->ensureDefaults();

        $rows = EquipmentChecklistTemplate::query()
            ->with(['items', 'site:id,code,name'])
            ->orderBy('scope_type')
            ->orderBy('scope_value')
            ->get()
            ->map(fn (EquipmentChecklistTemplate $t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'scopeType' => $t->scope_type,
                'scopeValue' => $t->scope_value,
                'scopeLabel' => $this->scopeLabel($t),
                'stage' => $t->stage === EquipmentChecklistTemplate::STAGE_POST ? '반납' : '사용 전',
                'site' => $t->site?->name,
                'isDefault' => $t->is_default,
                'status' => $t->status,
                'items' => $t->items->map(fn (EquipmentChecklistItem $i): array => [
                    'id' => $i->id,
                    'ko' => $i->label_ko, 'en' => $i->label_en, 'es' => $i->label_es,
                    'severity' => $i->severity,
                    'critical' => $i->isCritical(),
                    'status' => $i->status,
                ])->values()->all(),
            ])->values()->all();

        return ['success' => true, 'canManage' => $this->canManage(), 'templates' => $rows];
    }

    /**
     * 항목 한 줄 저장(새로 만들거나 고치거나).
     *
     * 기본표(is_default)를 고치면 <b>그 질문지는 더 이상 기본표가 아니다.</b>
     * 그래야 다음 배포가 사장님이 고친 문장을 조용히 되돌리지 않는다.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function saveItem(mixed $templateId, array $data): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '점검표를 고칠 권한이 없습니다.'];
        }

        $template = EquipmentChecklistTemplate::query()->find((int) $templateId);
        if (! $template) {
            return ['success' => false, 'error' => '점검표를 찾지 못했습니다.'];
        }

        $ko = trim((string) ($data['ko'] ?? ''));
        if ($ko === '') {
            return ['success' => false, 'error' => '한국어 문장은 비울 수 없습니다.'];
        }

        // 번역이 비면 한국어를 넣어 둔다. 빈 줄을 내려보내면 그 언어 화면에서
        // 항목이 통째로 사라지는데, 사라진 줄은 아무도 못 찾는다.
        $en = trim((string) ($data['en'] ?? '')) ?: $ko;
        $es = trim((string) ($data['es'] ?? '')) ?: $ko;

        $attributes = [
            'equipment_checklist_template_id' => $template->id,
            'label_ko' => $ko, 'label_en' => $en, 'label_es' => $es,
            'severity' => ($data['severity'] ?? '') === EquipmentChecklistItem::CRITICAL
                ? EquipmentChecklistItem::CRITICAL
                : EquipmentChecklistItem::NORMAL,
            'stage' => $template->stage === EquipmentChecklistTemplate::STAGE_POST
                ? EquipmentChecklistTemplate::STAGE_POST
                : EquipmentChecklistTemplate::STAGE_PRE,
            'status' => ($data['status'] ?? 'active') === 'archived' ? 'archived' : 'active',
            'requires_photo_on_fail' => true,
        ];

        $itemId = (int) ($data['id'] ?? 0);
        if ($itemId > 0) {
            $item = EquipmentChecklistItem::query()
                ->where('equipment_checklist_template_id', $template->id)->find($itemId);
            if (! $item) {
                return ['success' => false, 'error' => '항목을 찾지 못했습니다.'];
            }
            $item->update($attributes);
        } else {
            $attributes['sort_order'] = ((int) $template->items()->max('sort_order')) + 10;
            EquipmentChecklistItem::query()->create($attributes);
        }

        if ($template->is_default) {
            $template->update(['is_default' => false]);
        }

        return ['success' => true];
    }

    private function scopeLabel(EquipmentChecklistTemplate $template): string
    {
        if ($template->scope_value === '*') {
            return '모든 장비';
        }

        return match ($template->scope_type) {
            'trade' => Equipment::TRADES[$template->scope_value] ?? (string) $template->scope_value,
            'category_group' => Equipment::CATEGORY_GROUPS[$template->scope_value] ?? (string) $template->scope_value,
            'equipment' => '장비 #'.$template->scope_value,
            default => (string) $template->scope_value,
        };
    }

    private function resolveSite(string $siteId): ?Site
    {
        if ($siteId === '' || strtoupper($siteId) === 'ALL') {
            return null;
        }

        return Site::query()->where('code', $siteId)->orWhere('id', (int) $siteId)->first();
    }
}
