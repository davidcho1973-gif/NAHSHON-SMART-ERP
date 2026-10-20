<?php

namespace App\Http\Controllers;

use App\Services\Admin\BillingAdminService;
use App\Services\Finance\ClaimEvidenceInboxService;
use App\Services\Finance\ClaimDailyEvidencePacketService;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

class ClaimEvidenceFileController extends Controller
{
    public function show(int $contract, string $type, int $id, BillingAdminService $billing, ClaimEvidenceInboxService $inbox, ClaimDailyEvidencePacketService $packets): Response
    {
        abort_unless($billing->canView(), 403);
        $contract = $billing->findAccessibleContract($contract);
        abort_unless($contract && $contract->direction === 'receivable', 404);
        try {
            $source = $type === 'cad_revision' ? $packets->cadPreview($contract, $id) : $inbox->readForPreview($contract, $type, $id);
            $bytes = $source['bytes'];
            $mime = $source['mime'] ?? 'application/octet-stream';
            $inline = in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'text/plain'], true);
            return response($bytes, 200, [
                'Content-Type' => $inline ? $mime : 'application/octet-stream',
                'Content-Disposition' => HeaderUtils::makeDisposition($inline ? 'inline' : 'attachment', $source['name'] ?? 'evidence', 'evidence'),
                'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "sandbox; default-src 'none'",
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
