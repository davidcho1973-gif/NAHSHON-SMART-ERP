<?php

namespace App\Filament\Resources\CommunicationRooms\Pages;

use App\Filament\Resources\CommunicationRooms\CommunicationRoomResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCommunicationRooms extends ManageRecords
{
    protected static string $resource = CommunicationRoomResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('메신저 방 만들기')
                ->modalHeading('메신저 방 만들기')
                ->createAnother(false),
        ];
    }
}
