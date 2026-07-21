<?php

namespace App\Filament\Resources\ProjectContracts\Pages;

use App\Filament\Resources\ProjectContracts\ProjectContractResource;
use App\Models\ProjectContract;
use App\Models\ProjectContractDocument;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ManageProjectContractDocuments extends ManageRelatedRecords
{
    protected static string $resource = ProjectContractResource::class;

    protected static string $relationship = 'documents';

    protected static bool $shouldSkipAuthorization = true;

    protected static ?string $breadcrumb = '계약서류';

    protected function authorizeAccess(): void
    {
        $record = $this->getOwnerRecord();

        abort_unless(
            ProjectContractResource::canViewAny()
                && ProjectContractResource::getEloquentQuery()->whereKey($record->getKey())->exists(),
            403,
        );
    }

    public function getTitle(): string
    {
        /** @var ProjectContract $contract */
        $contract = $this->getOwnerRecord();

        return '계약서류 - '.$contract->internal_reference.' · '.$contract->title;
    }

    public function getSubheading(): ?string
    {
        return '현재 유효 버전과 만료일을 유지하고, 새 버전이 승인되면 이전 파일은 대체됨(Superseded)으로 변경하세요.';
    }

    public function form(Schema $schema): Schema
    {
        return ProjectContractResource::documentForm($schema);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('document_type')
                    ->label('서류 유형')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ProjectContractDocument::TYPE_OPTIONS[$state] ?? (string) $state)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->label('서류명')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('document_number')
                    ->label('문서번호')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('version')
                    ->label('버전')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('상태')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ProjectContractDocument::STATUS_OPTIONS[$state] ?? (string) $state)
                    ->color(fn (?string $state, ProjectContractDocument $record): string => match (true) {
                        $record->expires_on?->isPast() && $record->is_current => 'danger',
                        $state === 'approved' => 'success',
                        $state === 'under_review' => 'warning',
                        in_array($state, ['expired', 'rejected'], true) => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('expires_on')
                    ->label('만료일')
                    ->date()
                    ->placeholder('만료 없음')
                    ->color(fn (?ProjectContractDocument $record): string => match (true) {
                        ! $record?->expires_on => 'gray',
                        $record->expires_on->isPast() => 'danger',
                        $record->expires_on->lte(today()->addDays(30)) => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('is_current')
                    ->label('현재본')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Current' : 'Archive')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                TextColumn::make('original_file_name')
                    ->label('파일명')
                    ->placeholder('파일 없음')
                    ->limit(28)
                    ->tooltip(fn (ProjectContractDocument $record): ?string => $record->original_file_name),
                TextColumn::make('file_size')
                    ->label('크기')
                    ->formatStateUsing(fn (?int $state): string => self::formatBytes($state))
                    ->toggleable(),
                TextColumn::make('uploadedBy.name')
                    ->label('업로드')
                    ->toggleable(),
                TextColumn::make('reviewed_at')
                    ->label('승인일')
                    ->dateTime('m/d/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('document_type')
                    ->label('서류 유형')
                    ->options(ProjectContractDocument::TYPE_OPTIONS),
                SelectFilter::make('status')
                    ->label('상태')
                    ->options(ProjectContractDocument::STATUS_OPTIONS),
                SelectFilter::make('is_current')
                    ->label('버전')
                    ->options(['1' => 'Current', '0' => 'Archive']),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('다운로드')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('info')
                    ->url(fn (ProjectContractDocument $record): string => route('project-contract-document.download', ['document' => $record]))
                    ->openUrlInNewTab()
                    ->visible(fn (ProjectContractDocument $record): bool => filled($record->file_path)),
                Action::make('approve')
                    ->label('승인')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (ProjectContractDocument $record): bool => ProjectContractResource::canManageDocuments() && $record->status !== 'approved')
                    ->action(function (ProjectContractDocument $record): void {
                        $record->update([
                            'status' => 'approved',
                            'reviewed_by' => auth()->id(),
                            'reviewed_at' => now(),
                        ]);

                        Notification::make()->success()->title('계약서류 승인 완료')->send();
                    }),
                Action::make('supersede')
                    ->label('이전 버전 처리')
                    ->icon('heroicon-o-archive-box')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (ProjectContractDocument $record): bool => ProjectContractResource::canManageDocuments() && $record->is_current)
                    ->action(function (ProjectContractDocument $record): void {
                        $record->update(['status' => 'superseded', 'is_current' => false]);
                        Notification::make()->success()->title('이전 버전으로 보관했습니다.')->send();
                    }),
                EditAction::make()
                    ->label('서류 수정')
                    ->visible(fn (): bool => ProjectContractResource::canManageDocuments()),
                DeleteAction::make()
                    ->visible(fn (): bool => ProjectContractResource::canDeleteDocuments()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => ProjectContractResource::canDeleteDocuments()),
                ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToContracts')
                ->label('계약 목록')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(ProjectContractResource::getUrl()),
            CreateAction::make()
                ->label('계약서류 업로드')
                ->icon('heroicon-o-cloud-arrow-up')
                ->modalHeading('계약서류 업로드')
                ->modalWidth('4xl')
                ->mutateDataUsing(function (array $data): array {
                    $data['uploaded_by'] = auth()->id();
                    $data['disk'] = 'local';

                    return $data;
                })
                ->visible(fn (): bool => ProjectContractResource::canManageDocuments()),
        ];
    }

    private static function formatBytes(?int $bytes): string
    {
        if (! $bytes) {
            return '-';
        }

        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1).' MB';
        }

        return number_format($bytes / 1024, 1).' KB';
    }
}
