<?php

namespace App\Filament\Resources\CommunicationMessages\Pages;

use App\Filament\Resources\CommunicationMessages\CommunicationMessageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCommunicationMessages extends ManageRecords
{
    protected static string $resource = CommunicationMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('메시지 작성')
                ->modalHeading('메시지 작성')
                ->createAnother(false),
        ];
    }
}
