<?php

namespace App\Http\Controllers;

use App\Filament\Resources\ProjectContracts\ProjectContractResource;
use App\Models\ProjectContractDocument;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectContractDocumentController extends Controller
{
    public function download(ProjectContractDocument $document): StreamedResponse
    {
        $user = auth()->user();

        abort_unless(
            $user
                && $user->account_status === 'active'
                && ProjectContractResource::canViewAny()
                && ProjectContractResource::getEloquentQuery()
                    ->whereKey($document->project_contract_id)
                    ->exists(),
            403,
        );

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
