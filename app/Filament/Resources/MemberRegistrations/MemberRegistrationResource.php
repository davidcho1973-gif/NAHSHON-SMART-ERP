<?php

namespace App\Filament\Resources\MemberRegistrations;

use App\Filament\Resources\MemberRegistrations\Pages\ManageMemberRegistrations;
use App\Models\Company;
use App\Models\MemberRegistration;
use App\Models\Site;
use App\Models\Team;
use Filament\Actions\Action;
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

class MemberRegistrationResource extends Resource
{
    protected static ?string $model = MemberRegistration::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-user-plus';

    protected static ?string $navigationLabel = 'Member Registrations';

    protected static ?string $modelLabel = 'Member Registration';

    protected static ?string $pluralModelLabel = 'Member Registrations';

    protected static string | \UnitEnum | null $navigationGroup = 'SMART COMPANY';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('member_type')
                ->options([
                    'worker' => 'Worker',
                    'staff' => 'Staff',
                    'vendor' => 'Vendor',
                    'visitor' => 'Visitor',
                    'driver' => 'Driver',
                ])
                ->default('worker')
                ->required(),
            TextInput::make('full_name')->required()->maxLength(255),
            TextInput::make('preferred_name')->maxLength(255),
            TextInput::make('email')->email()->maxLength(255),
            TextInput::make('phone')->tel()->maxLength(80),
            TextInput::make('employee_number')->label('Employee ID')->maxLength(80),
            TextInput::make('badge_number')->maxLength(80),
            Select::make('company_id')
                ->label('Company')
                ->options(fn (): array => Company::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable(),
            Select::make('site_id')
                ->label('Site')
                ->options(fn (): array => Site::query()->orderBy('code')->pluck('code', 'id')->all())
                ->searchable(),
            Select::make('team_id')
                ->label('Team')
                ->options(fn (): array => Team::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable(),
            TextInput::make('nationality')->maxLength(80),
            TextInput::make('role')->maxLength(120),
            TextInput::make('trade')->maxLength(120),
            DatePicker::make('start_date'),
            DatePicker::make('end_date'),
            TextInput::make('visa_type')->maxLength(60),
            DatePicker::make('visa_expires_on'),
            DatePicker::make('safety_training_expires_on'),
            Select::make('identity_status')
                ->options([
                    'pending' => 'Pending',
                    'verified' => 'Verified',
                    'needs_review' => 'Needs review',
                ])
                ->default('pending')
                ->required(),
            Select::make('document_status')
                ->options([
                    'missing' => 'Missing',
                    'pending' => 'Pending',
                    'verified' => 'Verified',
                    'expired' => 'Expired',
                ])
                ->default('missing')
                ->required(),
            Select::make('onboarding_status')
                ->options([
                    'draft' => 'Draft',
                    'invited' => 'Invited',
                    'submitted' => 'Submitted',
                    'screening' => 'Screening',
                    'approved' => 'Approved',
                    'active' => 'Active',
                    'rejected' => 'Rejected',
                    'archived' => 'Archived',
                ])
                ->default('draft')
                ->required(),
            Textarea::make('notes')->columnSpanFull(),
            KeyValue::make('payload')
                ->keyLabel('Signal')
                ->valueLabel('Value')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('registration_number')->label('Registration')->searchable()->sortable(),
                TextColumn::make('full_name')->searchable()->sortable(),
                TextColumn::make('company.name')->label('Company')->toggleable(),
                TextColumn::make('site.code')->label('Site')->badge(),
                TextColumn::make('member_type')->badge()->sortable(),
                TextColumn::make('onboarding_status')->label('Status')->badge()->sortable(),
                TextColumn::make('risk_level')->badge()->sortable(),
                TextColumn::make('automation_score')->label('Auto %')->sortable(),
                TextColumn::make('document_status')->badge()->toggleable(),
                TextColumn::make('submitted_at')->since()->sortable()->toggleable(),
                TextColumn::make('approved_at')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('onboarding_status')->options([
                    'draft' => 'Draft',
                    'invited' => 'Invited',
                    'submitted' => 'Submitted',
                    'screening' => 'Screening',
                    'approved' => 'Approved',
                    'active' => 'Active',
                    'rejected' => 'Rejected',
                    'archived' => 'Archived',
                ]),
                SelectFilter::make('risk_level')->options([
                    'low' => 'Low',
                    'medium' => 'Medium',
                    'high' => 'High',
                ]),
                SelectFilter::make('member_type')->options([
                    'worker' => 'Worker',
                    'staff' => 'Staff',
                    'vendor' => 'Vendor',
                    'visitor' => 'Visitor',
                    'driver' => 'Driver',
                ]),
            ])
            ->recordActions([
                Action::make('intake')
                    ->label('Open intake')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (MemberRegistration $record): string => $record->intakeUrl())
                    ->openUrlInNewTab(),
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (MemberRegistration $record): bool => $record->onboarding_status !== 'active')
                    ->action(function (MemberRegistration $record): void {
                        $record->approve(auth()->user());
                    }),
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
            'index' => ManageMemberRegistrations::route('/'),
        ];
    }
}
