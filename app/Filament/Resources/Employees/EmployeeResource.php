<?php

namespace App\Filament\Resources\Employees;

use App\Filament\Resources\Employees\Pages\ManageEmployees;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Team;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationLabel = 'Employees';

    protected static ?string $modelLabel = 'Employee';

    protected static ?string $pluralModelLabel = 'Employees';

    protected static string | \UnitEnum | null $navigationGroup = 'SMART COMPANY';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('employee_number')
                ->label('Employee ID')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(80),
            TextInput::make('badge_number')
                ->label('Badge / NFC ID')
                ->unique(ignoreRecord: true)
                ->maxLength(80),
            TextInput::make('name')
                ->label('Full name')
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->email()
                ->unique(ignoreRecord: true)
                ->maxLength(255),
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
            TextInput::make('role')
                ->label('Role / Trade')
                ->maxLength(120),
            TextInput::make('nationality')
                ->maxLength(80),
            Select::make('employment_status')
                ->label('Status')
                ->options([
                    'active' => 'Active',
                    'pending' => 'Pending',
                    'on_leave' => 'On leave',
                    'inactive' => 'Inactive',
                    'terminated' => 'Terminated',
                ])
                ->default('active')
                ->required(),
            DatePicker::make('visa_expires_on')
                ->label('Visa expires'),
            DatePicker::make('safety_training_expires_on')
                ->label('Safety training expires'),
            KeyValue::make('payload')
                ->label('Extra data')
                ->keyLabel('Field')
                ->valueLabel('Value')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee_number')->label('Employee ID')->searchable()->sortable(),
                TextColumn::make('name')->label('Full name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->toggleable(),
                TextColumn::make('company.name')->label('Company')->searchable()->toggleable(),
                TextColumn::make('site.code')->label('Site')->badge()->sortable(),
                TextColumn::make('team.name')->label('Team')->searchable()->toggleable(),
                TextColumn::make('role')->label('Role')->searchable()->toggleable(),
                TextColumn::make('employment_status')->label('Status')->badge()->sortable(),
                TextColumn::make('badge_number')->label('Badge')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('visa_expires_on')->date()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('safety_training_expires_on')->date()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('employment_status')
                    ->label('Status')
                    ->options([
                        'active' => 'Active',
                        'pending' => 'Pending',
                        'on_leave' => 'On leave',
                        'inactive' => 'Inactive',
                        'terminated' => 'Terminated',
                    ]),
                SelectFilter::make('company_id')
                    ->label('Company')
                    ->options(fn (): array => Company::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('site_id')
                    ->label('Site')
                    ->options(fn (): array => Site::query()->orderBy('code')->pluck('code', 'id')->all()),
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

    public static function getPages(): array
    {
        return [
            'index' => ManageEmployees::route('/'),
        ];
    }
}
