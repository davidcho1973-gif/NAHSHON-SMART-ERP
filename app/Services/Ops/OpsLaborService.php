<?php

namespace App\Services\Ops;

use App\Models\Company;
use App\Models\OpsLaborReport;
use App\Models\Site;
use App\Services\Attendance\DailyHeadcountService;
use Illuminate\Support\Carbon;

/**
 * 오늘 몇 명 나와서 일했나 — 상황실의 핵심 보고.
 *
 * 두 숫자를 나란히 둔다.
 *   보고 인원  : 상황실에 올라온 글·사진에서 AI 가 읽은 값(또는 관리자가 직접 입력)
 *   실제 QR    : 게이트 QR 로 본인이 찍은 값 (= 급여 근거, AI 가 건드리지 않는다)
 *
 * 두 값이 어긋나면 그 차이가 곧 관리 포인트다. 협력사가 3명 왔다고 하는데 2명만 찍혔다면
 * 미등록 인원이거나 QR 미스캔이고, 어느 쪽이든 오늘 안에 확인해야 한다.
 */
class OpsLaborService
{
    public function __construct(private readonly DailyHeadcountService $headcount) {}

    /**
     * @return array<string, mixed>
     */
    public function forDate(?int $siteId, ?string $date = null): array
    {
        $site = $siteId ? Site::find($siteId) : null;
        $tz = $site?->timezone ?: config('app.timezone');
        $workDate = $date ?: Carbon::now($tz)->toDateString();

        $reports = OpsLaborReport::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->where('work_date', $workDate)
            ->with('company')
            ->orderByDesc('headcount')
            ->get();

        $actual = $this->headcount->today($siteId, $workDate);

        // 실제 QR 실적을 업체명으로 묶어 보고와 대조한다.
        $actualByCompany = [];
        foreach ($actual['companies'] as $c) {
            $actualByCompany[$c['company']] = ($actualByCompany[$c['company']] ?? 0) + $c['count'];
        }

        $rows = $reports->map(function (OpsLaborReport $r) use ($actualByCompany): array {
            $label = $r->label();
            $qr = (int) ($actualByCompany[$label] ?? 0);

            return [
                'id' => $r->id,
                'company' => $label,
                'trade' => $r->trade,
                'reported' => $r->headcount,
                'actualQr' => $qr,
                'gap' => $r->headcount - $qr,
                'note' => $r->note,
                'fromOps' => $r->ops_intake_batch_id !== null,
            ];
        })->all();

        $reportedTotal = (int) $reports->sum('headcount');
        $actualTotal = (int) ($actual['direct']['count'] + $actual['indirect']['count'] + $actual['other']['count']);

        return [
            'success' => true,
            'date' => $workDate,
            'reportedTotal' => $reportedTotal,
            'actualTotal' => $actualTotal,
            'gap' => $reportedTotal - $actualTotal,
            'rows' => $rows,
            // QR 로만 찍히고 보고에 없는 업체 — 반대 방향의 누락도 보여야 한다.
            'qrOnly' => collect($actual['companies'])
                ->reject(fn (array $c) => $reports->contains(fn (OpsLaborReport $r) => $r->label() === $c['company']))
                ->map(fn (array $c): array => ['company' => $c['company'], 'actualQr' => $c['count']])
                ->values()->all(),
            'direct' => $actual['direct'],
            'indirect' => $actual['indirect'],
        ];
    }

    /**
     * 관리자가 직접 보고 인원을 넣거나 고친다(AI 가 잘못 읽었을 때).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function save(?int $siteId, array $input, ?int $userId = null): array
    {
        $label = trim((string) ($input['company'] ?? ''));
        $headcount = (int) ($input['headcount'] ?? 0);

        if ($label === '') {
            return ['success' => false, 'error' => '업체명을 입력하세요.'];
        }
        if ($headcount < 0 || $headcount > 999) {
            return ['success' => false, 'error' => '인원수가 올바르지 않습니다.'];
        }

        $site = $siteId ? Site::find($siteId) : null;
        $tz = $site?->timezone ?: config('app.timezone');
        $workDate = (string) ($input['date'] ?? '') ?: Carbon::now($tz)->toDateString();

        $company = Company::query()->where('name', $label)->first();

        $row = OpsLaborReport::updateOrCreate(
            ['site_id' => $siteId, 'work_date' => $workDate, 'company_label' => $label],
            [
                'company_id' => $company?->id,
                'trade' => trim((string) ($input['trade'] ?? '')) ?: null,
                'headcount' => $headcount,
                'note' => trim((string) ($input['note'] ?? '')) ?: null,
                'reported_by_id' => $userId,
            ],
        );

        return ['success' => true, 'id' => $row->id];
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(int $id): array
    {
        $row = OpsLaborReport::find($id);
        if (! $row) {
            return ['success' => false, 'error' => '보고를 찾을 수 없습니다.'];
        }
        $row->delete();

        return ['success' => true];
    }
}
