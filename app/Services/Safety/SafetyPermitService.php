<?php

namespace App\Services\Safety;

use App\Models\SafetyPermit;
use App\Models\SafetyWorkItem;
use Illuminate\Support\Collection;

/**
 * 작업허가서(PTW) 발행·승인·서명 로직.
 *
 * AI 안전계획의 permits(텍스트 목록: 화기작업/고소작업/밀폐공간/전기 LOTO …)를
 * 실제 발행·서명 대상 문서(safety_permits)로 승격한다. 발행 → 승인 → 서명완료 워크플로.
 */
class SafetyPermitService
{
    /**
     * 안전카드의 AI 계획서 permits 로부터 작업허가서를 발행한다(이미 발행된 유형은 건너뜀).
     *
     * @return array<string, mixed>
     */
    public function issueFromCard(string $workCode, ?int $userId = null): array
    {
        $card = SafetyWorkItem::query()->where('work_code', $workCode)->first();
        if (! $card) {
            return ['success' => false, 'error' => "안전카드를 찾을 수 없습니다: {$workCode}"];
        }

        $plan = is_array($card->plan_payload) ? ($card->plan_payload['plan'] ?? []) : [];
        $permits = is_array($plan['permits'] ?? null) ? $plan['permits'] : [];
        if ($permits === []) {
            return ['success' => false, 'error' => 'AI 계획서에 필요한 작업허가가 없습니다. 계획을 먼저 생성/편집하세요.'];
        }

        // 안전 조치(precautions): 계획의 위험 대책 + 필수 PPE 를 합쳐 허가서 조건으로.
        $controls = collect($plan['hazards'] ?? [])->pluck('control')->filter()->values()->all();
        $ppe = is_array($plan['required_ppe'] ?? null) ? $plan['required_ppe'] : [];
        $precautions = array_values(array_unique(array_merge($controls, $ppe)));

        $existingTypes = SafetyPermit::query()->where('safety_work_item_id', $card->id)->pluck('type')->all();
        $date = $card->work_date?->toDateString();
        $created = 0;

        foreach ($permits as $p) {
            $type = trim((string) $p);
            if ($type === '' || in_array($type, $existingTypes, true)) {
                continue;
            }
            SafetyPermit::query()->create([
                'safety_work_item_id' => $card->id, 'wbs_code' => $card->wbs_code, 'site_id' => $card->site_id,
                'permit_no' => $this->nextPermitNo($date),
                'type' => $type, 'title' => $type, 'precautions' => $precautions,
                'valid_from' => $date, 'valid_to' => $date,
                'status' => '발행', 'issued_by_id' => $userId, 'issued_at' => now(),
            ]);
            $existingTypes[] = $type;
            $created++;
        }

        return ['success' => true, 'issued' => $created, 'permits' => $this->listForCard($card)];
    }

    /**
     * 카드 하나에 딸린 허가서 목록.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forWorkCode(string $workCode): array
    {
        $card = SafetyWorkItem::query()->where('work_code', $workCode)->first();

        return $card ? $this->listForCard($card) : [];
    }

    /**
     * 발행 → 승인 → 서명완료 (+ 취소) 상태 전이.
     *
     * @return array<string, mixed>
     */
    public function act(int $permitId, string $action, ?int $userId = null, ?string $signedBy = null): array
    {
        $permit = SafetyPermit::query()->find($permitId);
        if (! $permit) {
            return ['success' => false, 'error' => '작업허가서를 찾을 수 없습니다.'];
        }

        switch ($action) {
            case 'approve':
                if ($permit->status === '취소') {
                    return ['success' => false, 'error' => '취소된 허가서는 승인할 수 없습니다.'];
                }
                $permit->status = '승인';
                $permit->approved_by_id = $userId;
                $permit->approved_at = now();
                break;
            case 'sign':
                if ($permit->status !== '승인') {
                    return ['success' => false, 'error' => '승인된 허가서만 서명할 수 있습니다(발행 → 승인 → 서명).'];
                }
                $permit->status = '서명완료';
                $permit->signed_by = $signedBy !== null && trim($signedBy) !== '' ? trim($signedBy) : '작업책임자';
                $permit->signed_at = now();
                break;
            case 'cancel':
                $permit->status = '취소';
                break;
            default:
                return ['success' => false, 'error' => '알 수 없는 동작입니다.'];
        }

        $permit->save();

        return ['success' => true, 'permit' => $permit->toClientArray()];
    }

    /**
     * 최근 발행 허가서(전역 PTW 목록용).
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 50): array
    {
        return SafetyPermit::query()->with('workItem')->latest()->limit($limit)->get()
            ->map->toClientArray()->all();
    }

    /**
     * @return array{issued: int, approved: int, signed: int, total: int}
     */
    public function stats(): array
    {
        $by = SafetyPermit::query()->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        return [
            'issued' => (int) ($by['발행'] ?? 0),
            'approved' => (int) ($by['승인'] ?? 0),
            'signed' => (int) ($by['서명완료'] ?? 0),
            'total' => (int) $by->sum(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listForCard(SafetyWorkItem $card): array
    {
        return SafetyPermit::query()->with('workItem')->where('safety_work_item_id', $card->id)
            ->orderBy('id')->get()->map->toClientArray()->all();
    }

    private function nextPermitNo(?string $date): string
    {
        $date ??= now()->toDateString();
        $prefix = 'PTW-' . str_replace('-', '', mb_substr($date, 2, 8));
        $seq = SafetyPermit::query()->where('permit_no', 'like', $prefix . '-%')->count() + 1;

        return $prefix . '-' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }
}
