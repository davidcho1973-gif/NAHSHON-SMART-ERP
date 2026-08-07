<?php

namespace App\Filament\Resources\AttendanceLogs\Pages;

use App\Filament\Resources\AttendanceLogs\AttendanceLogResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAttendanceLogs extends ManageRecords
{
    protected static string $resource = AttendanceLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('출퇴근 기록 추가'),
        ];
    }
}
