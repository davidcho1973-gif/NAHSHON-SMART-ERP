<?php

namespace App\Filament\Resources\MemberRegistrations\Pages;

use App\Filament\Resources\MemberRegistrations\MemberRegistrationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMemberRegistrations extends ManageRecords
{
    protected static string $resource = MemberRegistrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
