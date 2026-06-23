<?php

namespace App\Filament\Resources\Housing\Pages;

use App\Filament\Resources\Housing\HousingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageHousing extends ManageRecords
{
    protected static string $resource = HousingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
