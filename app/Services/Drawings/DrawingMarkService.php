<?php

namespace App\Services\Drawings;

use App\Models\ClaimWorkRecord;
use App\Models\ContractBoqLine;
use App\Models\DrawingMark;
use App\Models\DrawingSheet;
use App\Models\IntelligentDocument;
use App\Models\PayApplicationAllocation;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkSection;
use App\Support\AiInformationAccess;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * 도면 위 표시 — 계약 줄의 일이 도면 어디에서 있었는지. 사장 지시(2026-09-25): «각 줄에 사진이나
 * 증거 자료를 넣었을 때 도면에 직접 내용이 넣어지게.»
 *
 * 표시는 도면 번호에 붙고(개정판이 와도 산다), 좌표는 쪽 크기 대비 비율이다. 색은 적지 않고
 * 연결된 현장 기록의 상태에서 매번 계산한다 — 기록이 확인되면 도면의 표시도 그 순간 초록이 된다.
 */
class DrawingMarkService
{
    public function __construct(private readonly SectionDrawingService $drawings) {}

    /**
     * 도면 한 장과 그 위의 표시 전부.
     *
     * @return array<string, mixed>
     */
    public function sheet(int $siteId, string $sheetNo): array
    {
        return $this->respond(function () use ($siteId, $sheetNo): array {
            [$user, $site] = $this->access($siteId, false);
            $no = DrawingSheet::normalizeNo($sheetNo);
            if ($no === null) {
                throw new InvalidArgumentException('도면 번호가 없습니다.');
            }
            $sheet = $this->latestSheet($site, $no);

            $marks = DrawingMark::query()->where('site_id', $site->id)->where('sheet_no', $no)
                ->with(['line.section', 'record'])->orderBy('id')->get();

            return [
                'success' => true,
                'sheetNo' => $no,
                'sheet' => $sheet ? $this->drawings->sheetRow($sheet) + [
                    'pdfUrl' => route('document-intelligence.preview', ['document' => $sheet->intelligent_document_id]),
                ] : null,
                'marks' => $this->rows($marks),
                'canManage' => $this->drawings->canManage($user),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function save(array $input): array
    {
        return $this->respond(function () use ($input): array {
            [, $site] = $this->access((int) ($input['siteId'] ?? 0), true);
            $no = DrawingSheet::normalizeNo(is_scalar($input['sheetNo'] ?? null) ? (string) $input['sheetNo'] : null);
            if ($no === null) {
                throw new InvalidArgumentException('어느 도면에 표시할지 도면 번호가 필요합니다.');
            }
            $shape = (string) ($input['shape'] ?? '');
            if (! in_array($shape, DrawingMark::SHAPES, true)) {
                throw new InvalidArgumentException('점·선·영역·메모 중 하나를 고르세요.');
            }
            $points = self::normalizePoints($input['points'] ?? null, $shape);
            $label = trim((string) ($input['label'] ?? ''));
            if (mb_strlen($label) > 500) {
                throw new InvalidArgumentException('메모는 500자 이내로 적으세요.');
            }

            $line = null;
            if (! empty($input['lineId'])) {
                $line = ContractBoqLine::with('contract')->find((int) $input['lineId']);
                if (! $line || (int) $line->contract?->site_id !== $site->id) {
                    throw new InvalidArgumentException('이 현장의 계약 줄만 도면에 표시할 수 있습니다.');
                }
            }
            $record = null;
            if (! empty($input['recordId'])) {
                $record = ClaimWorkRecord::find((int) $input['recordId']);
                if (! $record || ! $line || $record->contract_boq_line_id !== $line->id) {
                    throw new InvalidArgumentException('표시할 현장 기록이 그 계약 줄의 것이 아닙니다.');
                }
            }
            if ($shape === 'note' && $label === '') {
                throw new InvalidArgumentException('메모 내용을 적으세요.');
            }
            if ($line === null && $label === '') {
                throw new InvalidArgumentException('계약 줄을 고르거나 메모를 적으세요.');
            }
            $sectionId = $line?->work_section_id ?? (! empty($input['sectionId']) ? (int) $input['sectionId'] : null);
            if ($sectionId && ! WorkSection::whereKey($sectionId)->where('site_id', $site->id)->exists()) {
                $sectionId = null;
            }

            $mark = DrawingMark::create([
                'site_id' => $site->id, 'sheet_no' => $no, 'work_section_id' => $sectionId,
                'contract_boq_line_id' => $line?->id, 'claim_work_record_id' => $record?->id,
                'shape' => $shape, 'points' => $points, 'label' => $label !== '' ? $label : null, 'created_by' => auth()->id(),
            ]);

            return ['success' => true, 'id' => $mark->id, 'mark' => $this->rows(collect([$mark->load(['line.section', 'record'])]))[0]];
        });
    }

    /**
     * 도면의 축척을 적는다 — 도면에서 읽은 축척을 확인했거나, 아는 치수로 맞췄을 때.
     * 그 번호의 가장 최근 장(개정판)에 붙는다. 축척이 있어야 그은 선·영역이 수량이 된다.
     *
     * @return array<string, mixed>
     */
    public function setScale(int $siteId, string $sheetNo, mixed $feetPerPoint, string $label): array
    {
        return $this->respond(function () use ($siteId, $sheetNo, $feetPerPoint, $label): array {
            [, $site] = $this->access($siteId, true);
            $sheet = $this->latestSheet($site, (string) DrawingSheet::normalizeNo($sheetNo));
            if ($sheet === null) {
                throw new InvalidArgumentException('도면 파일이 올라와야 축척을 적을 수 있습니다.');
            }
            // 실물 크기 1:1(0.0012 ft/pt) 부터 토목 1" = 500'(6.9 ft/pt) 까지 — 그 밖은 잘못 잰 것이다.
            if (! is_numeric($feetPerPoint) || (float) $feetPerPoint < 0.0005 || (float) $feetPerPoint > 10) {
                throw new InvalidArgumentException('축척 값이 도면 축척의 범위를 벗어났습니다. 치수를 다시 재 주세요.');
            }
            $sheet->forceFill(['feet_per_point' => round((float) $feetPerPoint, 10), 'scale_label' => mb_substr(trim($label) ?: '사람이 맞춤', 0, 120)])->save();

            return ['success' => true, 'feetPerPoint' => (float) $sheet->feet_per_point, 'scaleLabel' => $sheet->scale_label];
        });
    }

    /** @return array<string, mixed> */
    public function delete(int $id): array
    {
        return $this->respond(function () use ($id): array {
            $mark = DrawingMark::find($id);
            if (! $mark) {
                throw new InvalidArgumentException('표시를 찾을 수 없습니다.');
            }
            $this->access($mark->site_id, true);
            $mark->delete();

            return ['success' => true];
        });
    }

    /**
     * 현장의 도면 번호별 표시 수 — 공정 카드의 도면 칩에 붙는다.
     *
     * @return array<string, int>
     */
    public function counts(int $siteId): array
    {
        return DrawingMark::query()->where('site_id', $siteId)->selectRaw('sheet_no, count(*) as n')->groupBy('sheet_no')
            ->pluck('n', 'sheet_no')->map(fn ($n) => (int) $n)->all();
    }

    /**
     * 좌표 검사 — 쪽 안(0~1)의 숫자만, 모양에 맞는 개수만.
     *
     * @return array<int, array{0: float, 1: float}>
     */
    public static function normalizePoints(mixed $points, string $shape): array
    {
        if (! is_array($points)) {
            throw new InvalidArgumentException('도면 위 위치가 없습니다.');
        }
        $out = [];
        foreach (array_values($points) as $p) {
            if (! is_array($p) || count($p) !== 2 || ! is_numeric($p[0] ?? null) || ! is_numeric($p[1] ?? null)) {
                throw new InvalidArgumentException('도면 위 위치 형식이 틀렸습니다.');
            }
            $x = (float) $p[0];
            $y = (float) $p[1];
            if ($x < 0 || $x > 1 || $y < 0 || $y > 1) {
                throw new InvalidArgumentException('도면 밖의 위치입니다.');
            }
            $out[] = [round($x, 6), round($y, 6)];
        }
        [$min, $max] = match ($shape) {
            'point', 'note' => [1, 1],
            'line' => [2, 200],
            default => [3, 200],
        };
        if (count($out) < $min || count($out) > $max) {
            throw new InvalidArgumentException(match ($shape) {
                'line' => '선은 두 점 이상 찍으세요.',
                'area' => '영역은 세 점 이상 찍으세요.',
                default => '점은 한 곳만 찍으세요.',
            });
        }

        return $out;
    }

    /**
     * 표시의 상태 — 기록에서 나온다. note(글 메모) · plan(줄만, 기록 없음) · pending · done · stored · rejected.
     */
    public static function statusOf(?ContractBoqLine $line, ?ClaimWorkRecord $record): string
    {
        if ($record === null) {
            return $line === null ? 'note' : 'plan';
        }

        return match ($record->status) {
            'verified' => $record->stage === 'stored' ? 'stored' : 'done',
            'rejected' => 'rejected',
            default => 'pending',
        };
    }

    /**
     * @param  Collection<int, DrawingMark>  $marks
     * @return array<int, array<string, mixed>>
     */
    private function rows(Collection $marks): array
    {
        $docIds = $marks->flatMap(fn (DrawingMark $m) => collect($m->record?->evidence ?? [])->where('type', 'document')->pluck('id'))->unique()->all();
        $docs = IntelligentDocument::query()->visibleTo(auth()->user())->whereIn('id', $docIds ?: [0])
            ->get(['id', 'title', 'original_file_name', 'mime_type', 'extension'])->keyBy('id');
        $users = User::query()->whereIn('id', $marks->pluck('created_by')->filter()->unique()->all() ?: [0])->pluck('name', 'id');
        // 몇 차 기성에 들어갔나 — 기성 회차에 배정된 수량(pay_application_allocations)이 그 사실이다.
        $rounds = PayApplicationAllocation::query()->whereIn('claim_work_record_id', $marks->pluck('claim_work_record_id')->filter()->unique()->all() ?: [0])
            ->with('application:id,application_no,status,period_end')->get()
            ->groupBy('claim_work_record_id')
            ->map(fn (Collection $g) => $g->map(fn (PayApplicationAllocation $a): array => [
                'no' => (int) $a->application?->application_no, 'status' => $a->application?->status,
                'periodEnd' => $a->application?->period_end?->toDateString(), 'qty' => (float) $a->quantity,
            ])->filter(fn (array $r): bool => $r['no'] > 0)->sortBy('no')->values()->all());

        return $marks->map(function (DrawingMark $m) use ($docs, $users, $rounds): array {
            $r = $m->record;
            $evidence = collect($r?->evidence ?? [])->map(function (array $e) use ($docs): ?array {
                if (($e['type'] ?? '') !== 'document' || ! $docs->has((int) $e['id'])) {
                    return null;
                }
                $d = $docs->get((int) $e['id']);

                return ['id' => $d->id, 'title' => $d->title ?: $d->original_file_name,
                    'url' => route('document-intelligence.preview', ['document' => $d->id]),
                    'image' => str_starts_with((string) $d->mime_type, 'image/') || in_array(strtolower((string) $d->extension), ['jpg', 'jpeg', 'png', 'webp'], true)];
            })->filter()->values()->all();

            return [
                'id' => $m->id, 'shape' => $m->shape, 'points' => $m->points, 'label' => $m->label,
                'status' => self::statusOf($m->line, $r),
                // 이 표시의 일이 청구된 기성 회차들(1차·2차…). 색을 회차별로 나누는 근거.
                'rounds' => $r ? ($rounds[$r->id] ?? []) : [],
                'sectionId' => $m->work_section_id,
                'line' => $m->line ? ['id' => $m->line->id, 'lineNo' => $m->line->line_no, 'description' => $m->line->description,
                    'unit' => $m->line->unit, 'section' => $m->line->section?->code] : null,
                'record' => $r ? ['id' => $r->id, 'stage' => $r->stage, 'status' => $r->status, 'reportedQty' => (float) $r->reported_qty,
                    'verifiedQty' => $r->verified_qty !== null ? (float) $r->verified_qty : null, 'workDate' => $r->work_date?->toDateString(),
                    'location' => $r->location, 'notes' => $r->notes, 'evidence' => $evidence] : null,
                'createdBy' => $users[$m->created_by] ?? null,
                'createdAt' => $m->created_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    /** 같은 번호가 여러 파일에 있으면(개정판) 가장 늦게 올라온 파일의 장 — 공정별 도면 화면과 같은 규칙. */
    private function latestSheet(Site $site, string $no): ?DrawingSheet
    {
        return DrawingSheet::query()->where('site_id', $site->id)->whereRaw('upper(sheet_no) = ?', [$no])
            ->with('document:id,title,original_file_name,created_at')->get()
            ->sortByDesc(fn (DrawingSheet $s) => [$s->document?->created_at?->timestamp ?? 0, $s->id])->first();
    }

    /** @return array{0: User, 1: Site} */
    private function access(int $siteId, bool $write): array
    {
        $user = auth()->user();
        if (! $user instanceof User || ! ($write ? $this->drawings->canManage($user) : $this->drawings->canView($user))) {
            throw new InvalidArgumentException($write ? '도면에 표시할 권한이 없습니다.' : '도면을 볼 권한이 없습니다.');
        }
        $site = Site::query()->find($siteId);
        if (! $site || ! AiInformationAccess::canUseSite($user, $site)) {
            throw new InvalidArgumentException('현장을 찾을 수 없습니다.');
        }

        return [$user, $site];
    }

    private function respond(callable $work): array
    {
        try {
            return $work();
        } catch (InvalidArgumentException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
