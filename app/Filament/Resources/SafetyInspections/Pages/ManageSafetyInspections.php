<?php

namespace App\Filament\Resources\SafetyInspections\Pages;

use App\Filament\Resources\SafetyInspections\SafetyInspectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSafetyInspections extends ManageRecords
{
    protected static string $resource = SafetyInspectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
