<?php

namespace App\Filament\Resources\MemberDocuments;

use App\Filament\Resources\MemberDocuments\Pages\ManageMemberDocuments;
use App\Filament\Resources\MemberDocuments\Pages\ManageMemberUploadedDocuments;
use App\Models\MemberRegistration;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
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
use Illuminate\Database\Eloquent\Builder;

class MemberDocumentResource extends Resource
{
    protected static ?string $model = MemberRegistration::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationLabel = 'HR Documents';

    protected static ?string $modelLabel = 'HR Document Summary';

    protected static ?string $pluralModelLabel = 'HR Documents';

    protected static string | \UnitEnum | null $navigationGroup = 'HUMAN RESOURCE';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function documentForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('document_type')
                ->options([
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
                TextColumn::make('full_name')
                    ->label('Member')
                    ->searchable()
                    ->sortable()
                    ->url(fn (MemberRegistration $record): string => static::getUrl('documents', ['record' => $record])),
                TextColumn::make('employee.employee_number')
                    ->label('Employee')
                    ->badge()
                    ->placeholder('Not synced')
                    ->toggleable(),
                TextColumn::make('company.name')->label('Company')->toggleable(),
                TextColumn::make('site.code')->label('Site')->badge(),
                TextColumn::make('documents_count')
                    ->label('Docs')
                    ->badge()
                    ->sortable(),
                TextColumn::make('verified_documents_count')
                    ->label('Verified')
                    ->badge()
                    ->color('success')
                    ->sortable(),
                TextColumn::make('pending_documents_count')
                    ->label('Pending')
                    ->badge()
                    ->color('warning')
                    ->sortable(),
                TextColumn::make('expired_documents_count')
                    ->label('Expired')
                    ->badge()
                    ->color('danger')
                    ->sortable(),
                TextColumn::make('document_status')->label('Document status')->badge()->sortable(),
                TextColumn::make('onboarding_status')->label('Member status')->badge()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('document_status')->options([
                    'missing' => 'Missing',
                    'pending' => 'Pending',
                    'verified' => 'Verified',
                    'expired' => 'Expired',
                ]),
                SelectFilter::make('onboarding_status')->options([
                    'draft' => 'Draft',
                    'invited' => 'Invited',
                    'submitted' => 'Submitted',
                    'under_review' => 'Under review',
                    'employee_registration' => 'Employee registration',
                    'screening' => 'Screening (legacy)',
                    'approved' => 'Approved (legacy)',
                    'interview' => 'Interview (legacy)',
                    'interview_passed' => 'Interview passed (legacy)',
                    'safety_training' => 'Hoffman safety training (legacy)',
                    'badge_pending' => 'Badge / NFC pending (legacy)',
                    'active' => 'Active',
                    'rejected' => 'Rejected',
                    'archived' => 'Archived',
                ]),
            ])
            ->recordActions([
                Action::make('documents')
                    ->label('Documents')
                    ->icon('heroicon-o-document-check')
                    ->url(fn (MemberRegistration $record): string => static::getUrl('documents', ['record' => $record])),
                DeleteAction::make(),
            ])
            ->recordUrl(fn (MemberRegistration $record): string => static::getUrl('documents', ['record' => $record]));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['employee', 'company', 'site'])
            ->withCount([
                'documents',
                'documents as verified_documents_count' => fn (Builder $query): Builder => $query->where('status', 'verified'),
                'documents as pending_documents_count' => fn (Builder $query): Builder => $query->whereIn('status', ['pending', 'needs_review']),
                'documents as expired_documents_count' => fn (Builder $query): Builder => $query->where('status', 'expired'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMemberDocuments::route('/'),
            'documents' => ManageMemberUploadedDocuments::route('/{record}/documents'),
        ];
    }
}
