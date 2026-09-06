<?php

namespace App\Console\Commands;

use App\Models\Equipment;
use App\Models\EquipmentRental;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 잘못 등록된 자재·장비 줄을 <b>먼저 보여 주고</b> 지운다.
 *
 * ── 왜 이 명령이 필요한가 ───────────────────────────────────────────────
 * 2026-09-06 나손: 주방 도면·기기 목록을 읽은 AI 가 그 안의 품목(디스포저·식기세척기·
 * 이동식 쓰레기통 …)을 <b>회사 장비 대장</b>에 143줄로 등록했다. 장비 대장은 우리가
 * 보유·임대해서 현장에 내보내는 자산을 적는 곳이지, 시공해서 남에게 넘길 공사 범위를
 * 적는 곳이 아니다. 대장이 이렇게 부풀면 "지금 현장에 뭐가 나가 있나" 를 아무도 못 읽는다.
 *
 * 화면에는 줄마다 삭제 버튼이 있지만 146번을 누르게 할 수는 없다.
 *
 * ── 왜 «보기» 가 먼저인가 ───────────────────────────────────────────────
 * equipments 에는 소프트 삭제가 없다. 지우면 <b>영구히 사라진다.</b> 그리고 143줄 안에
 * 사람이 손으로 넣은 진짜 장비가 섞여 있을 수 있다 — 그것까지 지우면 피해가 원래
 * 문제보다 크다. 그래서 기본은 «보기» 이고, --apply 를 붙여야만 지운다.
 * 지우기 직전에는 지울 줄 전체를 JSON 으로 떨어뜨린다 — 되돌릴 근거가 남는다.
 *
 * 기본 대상은 registration_method='AI자동분석' 뿐이다(--all-methods 로만 넓힌다).
 * 다만 <b>이 값은 «누가 넣었나» 를 뜻하지 않는다</b> — 컬럼 기본값이 'manual' 이라,
 * 값을 안 넣고 만든 줄은 자동 등록이어도 manual 로 남는다. 그래서 대상이 대장 전체보다
 * 훨씬 적으면 경고하고 --all-methods 를 권한다. 2026-09-06 에 이걸로 살았다: 주방기기
 * 143줄을 치우려는데 대상은 1줄뿐이었고 그 1줄이 진짜 굴착기였다.
 */
class PurgeEquipment extends Command
{
    protected $signature = 'equipment:purge
        {--apply : 실제로 지운다. 없으면 보여 주기만 한다}
        {--method=AI자동분석 : 이 등록 방식만 대상으로 한다}
        {--all-methods : 등록 방식을 가리지 않는다(사람이 손으로 넣은 줄까지 포함)}
        {--site= : 이 현장 ID 의 줄만}
        {--group= : 분류만(material·tool·equipment·safety·facility)}
        {--since= : 이 날짜 이후에 등록된 줄만 (YYYY-MM-DD)}
        {--until= : 이 날짜 이전에 등록된 줄만 (YYYY-MM-DD)}
        {--with-rentals : 임대 이력이 달린 줄도 지운다(기본은 건너뛴다)}
        {--backup= : 지울 줄을 적어 둘 파일 경로. 기본은 storage/app 아래}';

    protected $description = '잘못 등록된 자재·장비 줄을 먼저 보여 주고, --apply 를 붙였을 때만 지운다';

    public function handle(): int
    {
        $query = $this->scope();
        $rows = $query->get();

        if ($rows->isEmpty()) {
            $this->info('조건에 맞는 줄이 없습니다. 지울 것이 없습니다.');

            return self::SUCCESS;
        }

        $this->summarize($rows);
        $this->warnIfMostRowsAreOutOfScope($rows);

        // 임대 이력이 달린 줄은 자산으로 실제 쓰인 것이다 — 잘못 등록된 목록과 성격이 다르다.
        [$rows, $skipped] = $this->setAsideRented($rows);

        if ($rows->isEmpty()) {
            $this->warn('임대 이력을 뺀 나머지가 없습니다. 지울 것이 없습니다.');

            return self::SUCCESS;
        }

        $backup = $this->writeBackup($rows);
        $this->line('');
        $this->info('지울 줄을 파일에 적어 두었습니다: '.$backup);

        if (! $this->option('apply')) {
            $this->line('');
            $this->warn('지금은 보기만 했습니다. 아무것도 지우지 않았습니다.');
            $this->line('  위 목록이 전부 잘못 들어간 것이 맞으면, 같은 명령에 --apply 를 붙여 다시 실행하세요.');
            $this->line('  섞여 있으면 --site · --group · --since 로 범위를 좁힌 뒤 다시 보세요.');

            return self::SUCCESS;
        }

        try {
            $deleted = DB::transaction(fn (): int => Equipment::query()->whereIn('id', $rows->pluck('id'))->delete());
        } catch (Throwable $e) {
            $this->error('지우지 못했습니다: '.$e->getMessage());
            $this->line('아무것도 지워지지 않았습니다(전부 취소).');

            return self::FAILURE;
        }

        $this->line('');
        $this->info($deleted.'줄을 지웠습니다.');
        if ($skipped->isNotEmpty()) {
            $this->warn($skipped->count().'줄은 임대 이력이 있어 남겼습니다(--with-rentals 로 포함).');
        }
        $this->line('남은 자재·장비: '.Equipment::query()->count().'줄');

        return self::SUCCESS;
    }

    /** @return Builder<Equipment> */
    private function scope(): Builder
    {
        $query = Equipment::query();

        if (! $this->option('all-methods')) {
            $query->where('registration_method', (string) $this->option('method'));
        }
        if ($site = $this->option('site')) {
            $query->where('site_id', (int) $site);
        }
        if ($since = $this->option('since')) {
            $query->whereDate('created_at', '>=', $since);
        }
        if ($until = $this->option('until')) {
            $query->whereDate('created_at', '<=', $until);
        }

        $rows = $query->orderBy('id');

        // 분류는 저장된 값이 비어 있으면 이름에서 추론한다(화면과 같은 규칙) —
        // 그래서 SQL 로 거르지 않고 읽은 뒤에 거른다.
        if ($group = $this->option('group')) {
            return $rows->whereIn('id', Equipment::query()->get()
                ->filter(fn (Equipment $e): bool => $e->resolvedGroup() === $group)
                ->pluck('id'));
        }

        return $rows;
    }

    /** @param  Collection<int, Equipment>  $rows */
    private function summarize($rows): void
    {
        $this->line('');
        $this->info('대상 '.$rows->count().'줄');
        $this->line('');

        $this->line('등록 방식별:');
        foreach ($rows->groupBy(fn (Equipment $e): string => $e->registration_method ?: '(없음)') as $method => $group) {
            $this->line(sprintf('  %-14s %4d줄', $method, $group->count()));
        }

        $this->line('');
        $this->line('분류별:');
        foreach ($rows->groupBy(fn (Equipment $e): string => $e->resolvedGroup()) as $group => $items) {
            $label = Equipment::CATEGORY_GROUPS[$group] ?? $group;
            $this->line(sprintf('  %-20s %4d줄', $label, $items->count()));
        }

        // 무엇이 이 줄들을 만들었나. 치우기만 하고 통로를 못 막으면 다음 도면에서 또 쌓인다 —
        // 지우는 자리가 원인을 확인하기에 가장 좋은 자리다(대상이 눈앞에 다 있다).
        $this->line('');
        $this->line('만든 경로별:');
        foreach ($rows->groupBy(fn (Equipment $e): string => $this->originOf($e)) as $origin => $items) {
            $this->line(sprintf('  %-30s %4d줄', $origin, $items->count()));
        }

        $this->line('');
        $this->line('현장별:');
        $siteNames = Site::query()->pluck('name', 'id');
        foreach ($rows->groupBy(fn (Equipment $e): string => (string) ($e->site_id ?: 0)) as $siteId => $items) {
            $name = (int) $siteId === 0 ? '(현장 미지정)' : ((string) ($siteNames[(int) $siteId] ?? ('현장 '.$siteId)));
            $this->line(sprintf('  %-24s %4d줄', $name, $items->count()));
        }

        $this->line('');
        $this->line('등록일별:');
        foreach ($rows->groupBy(fn (Equipment $e): string => (string) $e->created_at?->toDateString()) as $day => $items) {
            $this->line(sprintf('  %-12s %4d줄', $day, $items->count()));
        }

        $this->line('');
        $this->line('이름 (앞 20줄):');
        foreach ($rows->take(20) as $row) {
            $this->line(sprintf('  #%-6d %s', $row->id, (string) $row->equipment_type));
        }
        if ($rows->count() > 20) {
            $this->line('  … 그리고 '.($rows->count() - 20).'줄 더 (전체는 아래 파일에)');
        }
    }

    /**
     * 대장의 대부분이 대상 밖이면 그 사실을 크게 말한다.
     *
     * <b>registration_method 는 «누가 넣었나» 를 뜻하지 않는다.</b> 컬럼 기본값이
     * 'manual' 이라, 그 값을 안 넣고 만든 줄은 전부 'manual' 이 된다 — 사람이 손으로
     * 넣은 것과 구별되지 않는다. 그래서 기본 필터(AI자동분석)만 믿으면 «치우려던 것은
     * 안 잡히고, 엉뚱한 것만 잡히는» 일이 벌어진다.
     *
     * 2026-09-06 나손에서 실제로 그랬다. 주방기기 143줄을 치우려고 돌렸더니 대상은
     * 1줄뿐이었고, 그 1줄은 정작 진짜 장비(미니 굴착기)였다. 그대로 --apply 했으면
     * 치우려던 것은 그대로 두고 멀쩡한 자산만 지웠을 것이다.
     */
    private function warnIfMostRowsAreOutOfScope(Collection $rows): void
    {
        if ($this->option('all-methods')) {
            return;
        }

        $total = Equipment::query()->count();
        $outside = $total - $rows->count();

        if ($outside <= 0) {
            return;
        }

        $this->line('');
        $this->warn(sprintf('대장에는 %d줄이 있는데 그중 %d줄만 대상입니다 — %d줄은 대상 밖입니다.', $total, $rows->count(), $outside));
        $this->line('  등록 방식이 다르면 여기 안 잡힙니다. 그리고 «manual» 은 사람이 넣었다는 뜻이 아닙니다 —');
        $this->line('  값을 안 넣고 만든 줄의 기본값이라 자동 등록된 줄도 manual 로 남습니다.');
        $this->line('  치우려는 것이 여기 안 보이면 전체를 먼저 보세요:');
        $this->line('     php artisan equipment:purge --all-methods');
    }

    /**
     * 이 줄을 만든 경로. payload 에 남은 표식으로 가른다.
     *
     * 경로마다 고칠 자리가 다르다 — 문서함 연결이면 DocumentEquipmentConnector 이고,
     * 앱 일괄 등록이면 MobileEquipmentController 다. 이름을 못 대면 엉뚱한 데를 고친다.
     */
    private function originOf(Equipment $equipment): string
    {
        $payload = is_array($equipment->payload) ? $equipment->payload : [];

        if (($payload['source'] ?? null) === 'document-hub') {
            return '문서함 연결 (문서 #'.($payload['document_id'] ?? '?').')';
        }
        if (str_starts_with((string) $equipment->equipment_code, 'DOC-')) {
            return '문서함 연결 (코드 DOC-)';
        }
        if (array_key_exists('order_quantity', $payload)) {
            return '계약서 판독 등록';
        }
        if ($payload === []) {
            return '앱 일괄 등록 (payload 없음)';
        }

        return '기타 ('.implode('·', array_slice(array_keys($payload), 0, 3)).')';
    }

    /**
     * 임대 이력이 달린 줄을 따로 뺀다 — 실제로 빌려 쓴 자산은 잘못 등록된 목록이 아니다.
     *
     * @param  Collection<int, Equipment>  $rows
     * @return array{0: Collection<int, Equipment>, 1: Collection<int, Equipment>}
     */
    private function setAsideRented($rows): array
    {
        if ($this->option('with-rentals')) {
            return [$rows, collect()];
        }

        $rented = EquipmentRental::query()
            ->whereIn('equipment_id', $rows->pluck('id'))
            ->pluck('equipment_id')
            ->unique()
            ->all();

        if ($rented === []) {
            return [$rows, collect()];
        }

        $skipped = $rows->filter(fn (Equipment $e): bool => in_array($e->id, $rented, true));
        $this->line('');
        $this->warn($skipped->count().'줄은 임대 이력이 있어 대상에서 뺐습니다 — 실제로 쓴 자산입니다.');
        $this->line('  그래도 지우려면 --with-rentals 를 붙이세요(임대 기록도 함께 사라집니다).');

        return [$rows->reject(fn (Equipment $e): bool => in_array($e->id, $rented, true)), $skipped];
    }

    /** @param  Collection<int, Equipment>  $rows */
    private function writeBackup($rows): string
    {
        $path = (string) ($this->option('backup') ?: storage_path('app/equipment-purge-'.now()->format('Ymd-His').'.json'));

        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($path, json_encode(
            $rows->map(fn (Equipment $e): array => $e->toArray())->values()->all(),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        ));

        return $path;
    }
}
