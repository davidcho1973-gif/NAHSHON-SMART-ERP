<?php

namespace App\Services\Admin;

use App\Models\Equipment;
use App\Models\EquipmentChecklistLog;
use App\Models\EquipmentRental;
use App\Models\Site;
use App\Models\User;
use App\Support\AccessPolicy;
use Illuminate\Support\Facades\DB;

/**
 * 자재·장비 대장을 <b>정리</b>한다 — 내보내기 · 골라 지우기 · 전부 비우기.
 *
 * ── 물량/BOQ 와 다른 점 ────────────────────────────────────────────────
 * BOQ 는 아무도 가리키지 않아서 지워도 딸려 깨지는 표가 없었다. <b>장비는 다르다.</b>
 * 데이터베이스에 물어보니 두 표가 장비를 가리키고, 둘 다 CASCADE 다:
 *
 *   · equipment_rentals        — 불출·반납 이력 (임대료가 원가로 잡히는 근거)
 *   · equipment_checklist_logs — QR 사용 전/반납 점검 기록 (사고 조사에서 쓰는 것)
 *
 * 장비 한 대를 지우면 그 장비의 이력과 점검 기록이 <b>말없이 같이 사라진다.</b>
 * 그래서 지우기 전에 «장비 몇 대, 그에 딸린 이력 몇 건, 점검 기록 몇 건» 을 세어
 * 보여 준다. 숫자를 안 보여 주고 지우게 하면, 나중에 «점검 기록 어디 갔냐» 를
 * 물었을 때 아무도 답하지 못한다.
 *
 * ── 백업을 서버에 두지 않는 이유 ───────────────────────────────────────
 * Laravel Cloud 의 로컬 디스크는 배포마다 초기화된다. 사장님 컴퓨터로 내려가야
 * 백업이다(BoqSheetService 와 같은 이유, 같은 방식).
 */
class EquipmentSheetService
{
    /** 대장을 통째로 비우는 것은 되돌릴 수 없다 — 현장소장에게는 주지 않는다. */
    private const CLEAR_ROLES = ['super_admin', 'admin'];

    public const COLUMNS = [
        '자산ID', '대분류', '공종', '품명', '모델', '공급처', '구분', '수량', '상태',
        '현 위치(현장)', '취득금액', '일대료', '임대시작', '임대종료', '점검기한',
        '등록방법', '마지막점검', '등록일',
    ];

    public function canManage(?User $actor = null): bool
    {
        return AccessPolicy::canManageSite($actor ?? auth()->user());
    }

    public function canClear(?User $actor = null): bool
    {
        $actor ??= auth()->user();

        return $actor !== null
            && $actor->account_status === 'active'
            && in_array($actor->access_role, self::CLEAR_ROLES, true);
    }

    // ── 내보내기 ────────────────────────────────────────────────────────

    /**
     * 대장을 CSV 한 장으로. 지우기 전에 반드시 한 번 받아 두는 용도다.
     *
     * @return array<string, mixed>
     */
    public function export(string $siteId = 'ALL', string $group = 'ALL'): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '자재·장비를 관리할 권한이 없습니다.'];
        }

        $rows = $this->scoped($siteId, $group)->orderBy('equipment_code')->get();

        return [
            'success' => true,
            'count' => $rows->count(),
            'fileName' => '자재장비-'.$this->scopeSlug($siteId, $group).'-'.now()->format('Ymd-His').'.csv',
            'csv' => $this->toCsv($rows),
        ];
    }

    // ── 골라 지우기 ─────────────────────────────────────────────────────

    /**
     * 체크한 것만 지운다. 「이건 지우고 저건 남기고」 가 대부분의 실제 정리다.
     *
     * @param  array<int, mixed>  $ids
     * @return array<string, mixed>
     */
    public function deleteMany(array $ids): array
    {
        if (! $this->canManage()) {
            return ['success' => false, 'error' => '자재·장비를 관리할 권한이 없습니다.'];
        }

        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return ['success' => false, 'error' => '지울 항목을 고르세요.'];
        }

        // 내 권한으로 볼 수 있는 것만 — 남의 회사 장비가 목록에 섞여 들어오면 안 된다.
        $rows = Equipment::query()->visibleTo(auth()->user())->whereIn('id', $ids)->get();
        if ($rows->isEmpty()) {
            return ['success' => false, 'error' => '지울 수 있는 항목이 없습니다.'];
        }

        $csv = $this->toCsv($rows);
        $counts = $this->cascadeCounts($rows->pluck('id')->all());

        Equipment::query()->whereIn('id', $rows->pluck('id'))->delete();

        return [
            'success' => true,
            'count' => $rows->count(),
            'rentals' => $counts['rentals'],
            'checks' => $counts['checks'],
            'backupCsv' => $csv,
            'backupName' => '자재장비-삭제전백업-'.now()->format('Ymd-His').'.csv',
        ];
    }

    // ── 전부 비우기 ─────────────────────────────────────────────────────

    /**
     * 범위 안의 자재·장비를 통째로 지운다.
     *
     * @param  bool  $dryRun  true 면 세기만 한다(확인 창이 쓴다).
     * @return array<string, mixed>
     */
    public function clear(string $siteId = 'ALL', string $group = 'ALL', string $confirm = '', bool $dryRun = false): array
    {
        if (! $this->canClear()) {
            return ['success' => false, 'error' => '대장을 비울 권한이 없습니다. 최고관리자에게 요청하세요.'];
        }

        $rows = $this->scoped($siteId, $group)->orderBy('equipment_code')->get();
        $count = $rows->count();
        $counts = $this->cascadeCounts($rows->pluck('id')->all());

        if ($dryRun) {
            return [
                'success' => true,
                'dryRun' => true,
                'count' => $count,
                // 딸려 지워지는 것 — 이 숫자를 안 보여 주고 지우게 하면 안 된다.
                'rentals' => $counts['rentals'],
                'checks' => $counts['checks'],
                'scope' => $this->scopeLabel($siteId, $group),
            ];
        }

        if ($count === 0) {
            return ['success' => false, 'error' => '이 범위에는 지울 자재·장비가 없습니다.'];
        }

        // 눌러서 지우는 것이 아니라 <b>적어서</b> 지운다.
        if (trim($confirm) !== '전부 삭제') {
            return ['success' => false, 'error' => '확인란에 「전부 삭제」 라고 정확히 적어 주세요.'];
        }

        $csv = $this->toCsv($rows);

        DB::transaction(function () use ($rows): void {
            Equipment::query()->whereIn('id', $rows->pluck('id'))->delete();
        });

        return [
            'success' => true,
            'count' => $count,
            'rentals' => $counts['rentals'],
            'checks' => $counts['checks'],
            'backupCsv' => $csv,
            'backupName' => '자재장비-삭제전백업-'.now()->format('Ymd-His').'.csv',
        ];
    }

    // ── 도우미 ──────────────────────────────────────────────────────────

    /**
     * 이 장비들을 지우면 함께 사라지는 것의 수.
     *
     * @param  array<int, int>  $ids
     * @return array{rentals: int, checks: int}
     */
    private function cascadeCounts(array $ids): array
    {
        if ($ids === []) {
            return ['rentals' => 0, 'checks' => 0];
        }

        return [
            'rentals' => EquipmentRental::query()->whereIn('equipment_id', $ids)->count(),
            'checks' => EquipmentChecklistLog::query()->whereIn('equipment_id', $ids)->count(),
        ];
    }

    /** 내 권한으로 볼 수 있는 것 중, 고른 범위만. */
    private function scoped(string $siteId, string $group)
    {
        $query = Equipment::query()->visibleTo(auth()->user());

        if ($siteId !== '' && strtoupper($siteId) !== 'ALL') {
            $site = Site::query()->where('code', $siteId)->orWhere('id', (int) $siteId)->first();
            // 없는 현장 코드를 «전체» 로 읽으면 한 현장만 지우려다 전부 지운다.
            $query->where('site_id', $site?->id ?? -1);
        }

        if ($group !== '' && strtoupper($group) !== 'ALL') {
            $query->where('category_group', $group);
        }

        return $query;
    }

    private function scopeLabel(string $siteId, string $group): string
    {
        $where = (strtoupper($siteId) === 'ALL' || $siteId === '')
            ? '전체 현장'
            : (Site::query()->where('code', $siteId)->orWhere('id', (int) $siteId)->value('name') ?: $siteId);

        $what = (strtoupper($group) === 'ALL' || $group === '')
            ? '자재·장비 전부'
            : (Equipment::CATEGORY_GROUPS[$group] ?? $group);

        return $where.' · '.$what;
    }

    private function scopeSlug(string $siteId, string $group): string
    {
        $parts = [];
        if (strtoupper($siteId) !== 'ALL' && $siteId !== '') {
            $parts[] = preg_replace('/[^A-Za-z0-9\-]/', '', $siteId) ?: 'site';
        }
        if (strtoupper($group) !== 'ALL' && $group !== '') {
            $parts[] = $group;
        }

        return $parts === [] ? '전체' : implode('-', $parts);
    }

    /** @param \Illuminate\Support\Collection<int, Equipment> $rows */
    private function toCsv($rows): string
    {
        $rows->loadMissing('site:id,code,name');

        $out = fopen('php://memory', 'r+');
        fputcsv($out, self::COLUMNS);

        foreach ($rows as $e) {
            fputcsv($out, [
                $e->equipment_code,
                Equipment::CATEGORY_GROUPS[$e->resolvedGroup()] ?? $e->category_group,
                Equipment::TRADES[$e->resolvedTrade()] ?? $e->trade,
                $e->equipment_type,
                $e->model,
                $e->vendor,
                $e->acquisition_type,
                $e->quantity,
                $e->status,
                $e->site?->name,
                $e->asset_value,
                $e->daily_rate,
                $e->rent_start?->toDateString(),
                $e->rent_end?->toDateString(),
                $e->inspection_due_on?->toDateString(),
                $e->registration_method,
                $e->last_checked_at?->toDateTimeString(),
                $e->created_at?->toDateString(),
            ]);
        }

        rewind($out);
        $body = (string) stream_get_contents($out);
        fclose($out);

        // BOM 이 없으면 엑셀이 한글 품명을 깨서 연다.
        return "\xEF\xBB\xBF".$body;
    }
}
