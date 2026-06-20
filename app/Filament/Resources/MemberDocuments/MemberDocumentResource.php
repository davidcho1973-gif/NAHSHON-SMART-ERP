<?php

namespace App\Filament\Resources\MemberDocuments;

use App\Filament\Resources\MemberDocuments\Pages\ManageMemberDocuments;
use App\Models\MemberDocument;
use App\Models\MemberRegistration;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MemberDocumentResource extends Resource
{
    protected static ?string $model = MemberDocument::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationLabel = 'Member Documents';

    protected static ?string $modelLabel = 'Member Document';

    protected static ?string $pluralModelLabel = 'Member Documents';

    protected static string | \UnitEnum | null $navigationGroup = 'SMART COMPANY';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('member_registration_id')
                ->label('Member')
                ->options(fn (): array => MemberRegistration::query()
                    ->orderBy('full_name')
                    ->get()
                    ->mapWithKeys(fn (MemberRegistration $registration): array => [
                        $registration->id => "{$registration->full_name} ({$registration->registration_number})",
                    ])
                    ->all())
                ->searchable()
                ->required(),
            Select::make('document_type')
                ->options([
                    'id' => 'Government ID',
                    'visa' => 'Visa / Work Authorization',
                    'safety' => 'Safety Training',
                    'nfc' => 'Badge / NFC',
                    'contract' => 'Contract',
                    'insurance' => 'Insurance',
                    'other' => 'Other',
                ])
                ->required(),
            TextInput::make('title')->required()->maxLength(255),
            Select::make('status')
                ->options([
                    'pending' => 'Pending',
                    'verified' => 'Verified',
                    'needs_review' => 'Needs review',
                    'expired' => 'Expired',
                    'rejected' => 'Rejected',
                ])
                ->default('pending')
                ->required(),
            DatePicker::make('issued_on'),
            DatePicker::make('expires_on'),
            TextInput::make('file_path')->label('File path / Drive URL')->maxLength(255)->columnSpanFull(),
            KeyValue::make('extracted_data')->keyLabel('Field')->valueLabel('Value')->columnSpanFull(),
            Textarea::make('review_notes')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('memberRegistration.full_name')->label('Member')->searchable(),
                TextColumn::make('document_type')->badge()->sortable(),
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('expires_on')->date()->sortable(),
                TextColumn::make('verified_at')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('document_type')->options([
                    'id' => 'Government ID',
                    'visa' => 'Visa / Work Authorization',
                    'safety' => 'Safety Training',
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
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMemberDocuments::route('/'),
        ];
    }
}
