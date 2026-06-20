<?php

namespace App\Filament\Resources\UserAccesses;

use App\Filament\Resources\UserAccesses\Pages\ManageUserAccesses;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UserAccessResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Access Control';

    protected static ?string $modelLabel = 'Access Account';

    protected static ?string $pluralModelLabel = 'Access Control';

    protected static ?string $slug = 'access-control';

    protected static string | \UnitEnum | null $navigationGroup = 'SMART COMPANY';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('employee_id')
                ->label('Member / Employee')
                ->options(fn (): array => Employee::query()
                    ->orderBy('name')
                    ->get()
                    ->mapWithKeys(fn (Employee $employee): array => [
                        $employee->id => "{$employee->name} ({$employee->employee_number})",
                    ])
                    ->all())
                ->searchable(),
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->email()
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),
            Select::make('access_role')
                ->label('Role')
                ->options(User::ROLE_OPTIONS)
                ->default('worker')
                ->required(),
            Select::make('access_scope')
                ->label('Scope')
                ->options(User::SCOPE_OPTIONS)
                ->default('self')
                ->required(),
            Select::make('account_status')
                ->label('Status')
                ->options(User::STATUS_OPTIONS)
                ->default('active')
                ->required(),
            Select::make('allowed_company_id')
                ->label('Allowed company')
                ->options(fn (): array => Company::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable(),
            Select::make('allowed_site_id')
                ->label('Allowed site')
                ->options(fn (): array => Site::query()->orderBy('code')->pluck('code', 'id')->all())
                ->searchable(),
            Select::make('allowed_team_id')
                ->label('Allowed team')
                ->options(fn (): array => Team::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable(),
            TextInput::make('google_id')
                ->label('Google ID')
                ->disabled()
                ->dehydrated(false),
            Textarea::make('access_notes')
                ->label('Access notes')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->sortable(),
                TextColumn::make('employee.employee_number')->label('Employee ID')->searchable()->toggleable(),
                TextColumn::make('access_role')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => User::ROLE_OPTIONS[$state] ?? (string) $state)
                    ->sortable(),
                TextColumn::make('access_scope')
                    ->label('Scope')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => User::SCOPE_OPTIONS[$state] ?? (string) $state)
                    ->sortable(),
                TextColumn::make('allowedCompany.name')->label('Company')->toggleable(),
                TextColumn::make('allowedSite.code')->label('Site')->badge()->toggleable(),
                TextColumn::make('allowedTeam.name')->label('Team')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('account_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => User::STATUS_OPTIONS[$state] ?? (string) $state)
                    ->sortable(),
                TextColumn::make('last_login_at')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('access_role')->label('Role')->options(User::ROLE_OPTIONS),
                SelectFilter::make('access_scope')->label('Scope')->options(User::SCOPE_OPTIONS),
                SelectFilter::make('account_status')->label('Status')->options(User::STATUS_OPTIONS),
                SelectFilter::make('allowed_site_id')
                    ->label('Allowed site')
                    ->options(fn (): array => Site::query()->orderBy('code')->pluck('code', 'id')->all()),
            ])
            ->recordActions([
                Action::make('activate')
                    ->label('Activate')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (User $record): bool => $record->account_status !== 'active')
                    ->action(fn (User $record): bool => $record->forceFill(['account_status' => 'active'])->save()),
                Action::make('suspend')
                    ->label('Suspend')
                    ->icon('heroicon-o-no-symbol')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => $record->account_status === 'active')
                    ->action(fn (User $record): bool => $record->forceFill(['account_status' => 'suspended'])->save()),
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
            'index' => ManageUserAccesses::route('/'),
        ];
    }
}
