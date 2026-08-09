<?php

namespace App\Http\Controllers;

use App\Models\ProjectContractDocument;
use App\Services\Admin\ContractAdminService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectContractDocumentController extends Controller
{
    public function download(ProjectContractDocument $document, ContractAdminService $contracts): StreamedResponse
    {
        abort_unless($contracts->canAccessContract((int) $document->project_contract_id), 403);

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($document->disk ?: (string) config('document-intelligence.disk', 'local'));

        abort_unless(filled($document->file_path) && $disk->exists($document->file_path), 404);

        return $disk->download(
            $document->file_path,
            $document->downloadName(),
            ['Content-Type' => $document->mime_type ?: 'application/octet-stream'],
        );
    }
}
