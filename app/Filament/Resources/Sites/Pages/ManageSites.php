<?php

namespace App\Filament\Resources\Sites\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Sites\SiteResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSites extends ManageRecords
{
    protected static string $resource = SiteResource::class;

    public function getSubheading(): ?string
    {
        return '현장을 먼저 만들고, 같은 화면에서 PROJECT·계약 회사·현장 팀을 함께 구성합니다.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('manageProjects')
                ->label('PROJECT 상세 관리')
                ->icon('heroicon-o-clipboard-document-list')
                ->color('gray')
                ->url(fn (): string => ProjectResource::getUrl()),
            CreateAction::make()
                ->label('현장 / PROJECT 등록')
                ->modalHeading('현장 / PROJECT 통합 등록')
                ->modalDescription('기본정보를 입력한 뒤 PROJECT·계약 회사·팀 탭을 순서대로 구성하세요.')
                ->modalWidth('7xl'),
        ];
    }
}
