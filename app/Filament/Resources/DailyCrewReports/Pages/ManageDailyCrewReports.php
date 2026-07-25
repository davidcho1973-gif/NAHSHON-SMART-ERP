<?php

namespace App\Filament\Resources\DailyCrewReports\Pages;

use App\Filament\Resources\DailyCrewReports\DailyCrewReportResource;
use Filament\Resources\Pages\ManageRecords;

class ManageDailyCrewReports extends ManageRecords
{
    protected static string $resource = DailyCrewReportResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
