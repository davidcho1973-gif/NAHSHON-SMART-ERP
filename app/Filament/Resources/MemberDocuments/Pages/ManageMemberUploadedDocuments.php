<?php

namespace App\Filament\Resources\MemberDocuments\Pages;

use App\Filament\Resources\MemberDocuments\MemberDocumentResource;
use App\Models\MemberDocument;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ManageMemberUploadedDocuments extends ManageRelatedRecords
{
    protected static string $resource = MemberDocumentResource::class;

    protected static string $relationship = 'documents';

    protected static bool $shouldSkipAuthorization = true;

    protected static ?string $breadcrumb = 'Documents';

    public function getTitle(): string
    {
        return 'Documents - ' . $this->getOwnerRecord()->full_name;
    }

    public function form(Schema $schema): Schema
    {
        return MemberDocumentResource::documentForm($schema);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('document_type')->label('Document type')->badge()->sortable(),
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('issued_on')->date()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('expires_on')->date()->sortable(),
                TextColumn::make('file_path')
                    ->label('Uploaded file')
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? 'Open' : 'No file')
                    ->url(fn (MemberDocument $record): ?string => $record->file_path ?: null)
                    ->openUrlInNewTab()
                    ->toggleable(),
                TextColumn::make('verified_at')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('document_type')->options([
                    'id' => 'Government ID',
                    'id_back' => 'Government ID Back',
                    'certification' => 'Certification',
                    'visa' => 'Visa / Work Authorization',
                    'safety' => 'Safety Orientation',
                    'safety_training' => 'Safety Training',
                    'nfc' => 'Badge / NFC',
                    'contract' => 'Contract',
                    'insurance' => 'Insurance',
                    'other' => 'Other',
                ]),
                SelectFilter::make('status')->options([
                    'pending' => 'Pending',
                    'verified' => 'Verified',
                    'needs_review' => 'Needs review',
                    'expired' => 'Expired',
                    'rejected' => 'Rejected',
                ]),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add document'),
        ];
    }
}
