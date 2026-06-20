<?php

namespace App\Filament\Resources\UserAccesses\Pages;

use App\Filament\Resources\UserAccesses\UserAccessResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Str;

class ManageUserAccesses extends ManageRecords
{
    protected static string $resource = UserAccessResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateDataUsing(function (array $data): array {
                    $data['password'] = Str::password(32);
                    $data['email_verified_at'] = now();

                    return $data;
                }),
        ];
    }
}
