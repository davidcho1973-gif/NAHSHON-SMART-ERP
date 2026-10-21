<?php

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Called only after authorization, inside the same parent-locked transaction as the write. */
class ClaimWriteReceipt
{
    public static function replay(array $input, string $operation, int $subject): ?array
    {
        if (empty($input['requestId'])) {
            return null; // Internal callers can still rely on their enclosing transaction.
        }
        if (! Str::isUuid($input['requestId'])) {
            throw new InvalidArgumentException('저장 요청 번호를 확인하세요.');
        }
        $receipt = DB::table('claim_write_receipts')->where('user_id', auth()->id())->where('request_id', $input['requestId'])->first();
        if (! $receipt) {
            return null;
        }
        if ($receipt->operation !== $operation || (int) $receipt->subject_id !== $subject || $receipt->request_hash !== self::hash($input)) {
            throw new InvalidArgumentException('앞선 저장 요청과 내용이 다릅니다. 저장된 기록을 확인한 뒤 수정하세요. 현재 입력은 보관됩니다.');
        }

        return json_decode($receipt->response, true, 512, JSON_THROW_ON_ERROR);
    }

    public static function remember(array $input, string $operation, int $subject, array $response): array
    {
        if (! empty($input['requestId'])) {
            DB::table('claim_write_receipts')->insert([
                'user_id' => auth()->id(), 'request_id' => $input['requestId'], 'operation' => $operation,
                'subject_id' => $subject, 'request_hash' => self::hash($input),
                'response' => json_encode($response, JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
        }

        return $response;
    }

    private static function hash(array $value): string
    {
        $sort = function (array $data) use (&$sort): array {
            if (! array_is_list($data)) {
                ksort($data);
            }

            return array_map(fn ($v) => is_array($v) ? $sort($v) : $v, $data);
        };

        return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR));
    }
}
