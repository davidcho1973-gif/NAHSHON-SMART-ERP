<?php

namespace App\Filament\Resources\ProjectContracts\Pages;

use App\Filament\Resources\ProjectContracts\ProjectContractResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageProjectContracts extends ManageRecords
{
    protected static string $resource = ProjectContractResource::class;

    public function getSubheading(): ?string
    {
        return '원청사별 계약 조건과 PROJECT 연결을 관리하고, 계약 원본·변경계약·COI·W-9·Bond의 버전과 만료일을 추적합니다.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('원청 계약 등록')
                ->modalHeading('원청 계약 등록')
                ->modalDescription('PROJECT와 원청사를 연결한 뒤 금액·일정·준법 조건을 입력하고, 저장 후 계약서류를 업로드하세요.')
                ->modalWidth('7xl')
                ->mutateDataUsing(fn (array $data): array => ProjectContractResource::enforceWritableScope($data)),
        ];
    }
}
