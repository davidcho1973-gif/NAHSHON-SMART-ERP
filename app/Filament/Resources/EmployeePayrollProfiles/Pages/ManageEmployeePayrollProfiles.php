<?php

namespace App\Filament\Resources\EmployeePayrollProfiles\Pages;

use App\Filament\Resources\EmployeePayrollProfiles\EmployeePayrollProfileResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageEmployeePayrollProfiles extends ManageRecords
{
    protected static string $resource = EmployeePayrollProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
