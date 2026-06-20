<?php

namespace App\Filament\Resources\SmartRecords\Pages;

use App\Filament\Resources\SmartRecords\SmartRecordResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSmartRecords extends ManageRecords
{
    protected static string $resource = SmartRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
