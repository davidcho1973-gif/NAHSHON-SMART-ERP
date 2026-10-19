<?php

namespace App\Services\Finance;

use InvalidArgumentException;

/** Typed arithmetic, never executable formula text or an AI-supplied total. */
class ClaimQuantityCalculation
{
    public function calculate(array $rows): array
    {
        if (count($rows) > 100) {
            throw new InvalidArgumentException('산출 항목은 100개 이내로 입력하세요.');
        }
        $total = 0;
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('산출 항목을 확인하세요.');
            }
            $values = [];
            foreach (['length', 'height', 'count', 'deduction'] as $key) {
                $v = $row[$key] ?? ($key === 'height' || $key === 'count' ? 1 : 0);
                if (! is_numeric($v) || ! is_finite((float) $v) || (float) $v < 0 || (float) $v > 1000000) {
                    throw new InvalidArgumentException('산출 치수는 0 이상 유효한 숫자여야 합니다.');
                }
                $values[$key] = round((float) $v, 4);
            }
            $qty = round($values['length'] * $values['height'] * $values['count'] - $values['deduction'], 4);
            if ($qty < 0 || $qty > 99999999999.9999) {
                throw new InvalidArgumentException('공제량 또는 산출 수량을 확인하세요.');
            }
            $out[] = $values + ['label' => mb_substr((string) ($row['label'] ?? ''), 0, 255), 'quantity' => $qty];
            $total += $qty;
        }

        return ['rows' => $out, 'total' => round($total, 4)];
    }
}
