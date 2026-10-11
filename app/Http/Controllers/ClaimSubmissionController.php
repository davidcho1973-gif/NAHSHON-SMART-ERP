<?php

namespace App\Http\Controllers;

use App\Services\Finance\ClaimSubmissionService;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class ClaimSubmissionController extends Controller
{
    public function download(int $submission, ClaimSubmissionService $submissions): Response
    {
        try {
            $row = $submissions->getDownload($submission);

            return Storage::disk($row->disk)->download($row->file_path, $row->file_name, [
                'Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function response(int $submission, int $event, ClaimSubmissionService $submissions): Response
    {
        try {
            $file = $submissions->getResponseDownload($submission, $event);

            return Storage::disk($file['disk'])->download($file['path'], $file['name'], [
                'Content-Type' => 'application/octet-stream', 'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
