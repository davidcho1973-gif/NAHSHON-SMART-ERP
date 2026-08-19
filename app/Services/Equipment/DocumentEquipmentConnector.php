<?php

namespace App\Services\Equipment;

use App\Models\Equipment;
use App\Models\IntelligentDocument;

/**
 * 문서함에 들어온 장비 임대·구매 문서를 장비 대장에 등록한다.
 *
 * 선벨트 임대 영수증을 문서함에 올렸더니 경비(돈)로는 넘어갔는데 <b>장비 대장에는
 * 그 굴착기가 없었다.</b> 빌린 장비가 대장에 없으면 "지금 현장에 뭐가 나가 있나 ·
 * 반납일이 언제냐 · 점검이 임박했냐" 를 아무도 모른다 — 문서를 알맞은 모듈에
 * 넣으라는 요구에서 재무만 잇고 장비를 빼먹은 것이다.
 *
 * 분석이 equipment(임대/구매, 이름, 모델, 요율, 기간)를 구조화해서 뽑으면, 여기서
 * 장비 대장에 한 줄을 만든다:
 *  - acquisition_type: rental → 임대, purchase → 소유 (기존 화면의 소유/임대 렌즈 그대로)
 *  - 현장이 지정된 문서면 사용중(현장 배치), 아니면 대기중(창고)
 *  - registration_method = 'AI자동분석' — 장비 사진 자동 등록과 같은 표식
 *
 * ## 요율(daily_rate)은 일부러 바로 채우지 않는다
 *
 * daily_rate 를 채우면 RentalExpenseConnector 가 매월 임대료를 자동으로 원가에
 * 넣는데, 같은 문서가 이미 경비(실제 청구액)로도 넘어가 있다 — 둘 다 살면 같은
 * 임대료가 두 번 잡힌다. 그래서 읽은 요율은 payload 에 보관하고, 사람이 장비
 * 화면에서 요율을 확정하는 순간부터 월별 자동 계상으로 전환한다(그때는 문서함
 * 인보이스 경비를 반려하면 된다).
 *
 * 원칙:
 *  - 멱등: equipment_code("DOC-{문서id}")가 열쇠다. 재분석해도 한 줄이다.
 *  - 사람이 손댄 줄(registration_method 가 바뀐 줄)은 다시 건드리지 않는다.
 */
class DocumentEquipmentConnector
{
    public function sync(IntelligentDocument $document): void
    {
        $info = (array) (($document->ai_payload ?? [])['equipment'] ?? []);
        $involved = strtolower(trim((string) ($info['involved'] ?? '')));
        $name = trim((string) ($info['name'] ?? ''));

        if (! in_array($involved, ['rental', 'purchase'], true) || $name === '') {
            return;
        }

        $code = "DOC-{$document->id}";
        $existing = Equipment::query()->where('equipment_code', $code)->first();

        // 사람이 이미 다듬은 줄은 재분석이 덮어쓰지 않는다.
        if ($existing && $existing->registration_method !== 'AI자동분석') {
            return;
        }

        $money = (array) (($document->ai_payload ?? [])['money'] ?? []);
        $date = static fn ($v): ?string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($v)) === 1
            ? trim($v) : null;

        $attributes = [
            'company_id' => $document->company_id,
            'site_id' => $document->site_id,
            'project_id' => $document->project_id,
            'equipment_type' => $name,
            'model' => trim((string) ($info['model'] ?? '')) ?: null,
            'vendor' => trim((string) ($money['payee'] ?? '')) ?: (trim((string) $document->sender) ?: null),
            'acquisition_type' => $involved === 'purchase' ? '소유' : '임대',
            'rent_start' => $involved === 'rental' ? $date($info['rent_start'] ?? null) : null,
            'rent_end' => $involved === 'rental' ? $date($info['rent_end'] ?? null) : null,
            // 현장이 있으면 현장 배치(사용중), 없으면 창고(대기중).
            'status' => $document->site_id ? '사용중' : '대기중',
            'registration_method' => 'AI자동분석',
            'payload' => [
                'source' => 'document-hub',
                'document_id' => $document->id,
                'document_uuid' => $document->uuid,
                'document_title' => $document->title,
                // 요율은 여기 보관 — 사람이 장비 화면에서 확정해야 월별 자동 계상이 시작된다.
                'rate' => is_numeric($info['rate'] ?? null) ? (float) $info['rate'] : null,
                'rate_unit' => trim((string) ($info['rate_unit'] ?? '')) ?: null,
                'invoice_amount' => is_numeric($money['amount'] ?? null) ? (float) $money['amount'] : null,
            ],
        ];

        if (! $existing) {
            Equipment::query()->create($attributes + ['equipment_code' => $code]);

            return;
        }

        $existing->update($attributes);
    }
}
