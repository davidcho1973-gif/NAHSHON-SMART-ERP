<?php

namespace App\Services\Finance;

use App\Models\IntelligentDocument;
use App\Models\MobileExpense;
use App\Support\ReceiptFilePayload;

/** One pending-expense writer; registration never constitutes approval or payment. */
class ExpenseRegistrationService
{
    public const CURRENCY = 'USD';

    public function registerPending(array $attributes): MobileExpense
    {
        return MobileExpense::query()->create(array_replace($attributes, [
            'status' => 'pending', 'reviewed_at' => null, 'reviewed_by_user_id' => null,
            'paid_at' => null, 'paid_by_user_id' => null, 'payment_reference' => null,
            'payroll_run_id' => null,
        ]));
    }

    /**
     * A retained, byte-identical proof already filed in the shared document hub need not
     * be copied into another folder. Never trust a client-supplied source_ref by itself.
     */
    public function hasCanonicalReceipt(MobileExpense $expense): bool
    {
        $id = $expense->ocr_data['document_id'] ?? null;
        $hash = $expense->ocr_data['receipt_proof_sha256'] ?? null;
        if (! is_int($id) || $id <= 0 || $expense->source_ref !== 'document:'.$id
            || ! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
            return false;
        }
        $document = IntelligentDocument::query()->find($id);
        if (! $document || $document->document_type !== 'receipt' || $document->ai_status !== 'ready'
            || $document->access_level !== 'scope' || ! in_array($document->confidentiality, ['public', 'internal'], true)
            || ! $document->site_id || ! $document->company_id
            || (int) $document->site_id !== (int) $expense->site_id
            || (int) $document->company_id !== (int) $expense->company_id
            || ! hash_equals((string) $document->sha256, $hash)) {
            return false;
        }
        $raw = $expense->receipt_file;
        $position = is_resource($raw) ? ftell($raw) : false;
        if (is_resource($raw)) {
            rewind($raw);
        }
        try {
            $bytes = ReceiptFilePayload::decode($raw);
        } finally {
            if (is_resource($raw) && $position !== false) {
                fseek($raw, $position);
            }
        }

        return $bytes !== null && hash_equals($hash, hash('sha256', $bytes));
    }
}
