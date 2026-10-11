<?php

namespace App\Http\Controllers;

use App\Services\Finance\ClaimSubmissionService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClaimSubmissionController extends Controller
{
    public function download(int $submission, ClaimSubmissionService $submissions): StreamedResponse
    {
        $row = $submissions->getDownload($submission);

        return Storage::disk($row->disk)->download($row->file_path, $row->file_name, [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
