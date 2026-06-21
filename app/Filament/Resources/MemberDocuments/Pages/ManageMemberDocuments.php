<?php

namespace App\Filament\Resources\MemberDocuments\Pages;

use App\Filament\Resources\MemberDocuments\MemberDocumentResource;
use Filament\Resources\Pages\ManageRecords;

class ManageMemberDocuments extends ManageRecords
{
    protected static string $resource = MemberDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
