<?php

namespace App\Filament\Resources\SmartRecords;

use App\Filament\Resources\SmartRecords\Pages\ManageSmartRecords;
use App\Models\SmartRecord;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
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

class SmartRecordResource extends Resource
{
    protected static ?string $model = SmartRecord::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-square-3-stack-3d';

    protected static ?string $navigationLabel = 'ERP Records';

    protected static ?string $modelLabel = 'ERP Record';

    protected static ?string $pluralModelLabel = 'ERP Records';

    protected static string | \UnitEnum | null $navigationGroup = 'SMART COMPANY';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('module')
                ->options([
                    'hr' => 'HR / Attendance',
                    'wbs' => 'WBS / Project',
                    'equipment' => 'Equipment',
                    'finance' => 'Finance',
                    'safety' => 'Safety',
                    'vendors' => 'Vendors',
                    'vehicle' => 'Vehicle',
                    'rental' => 'Rental',
                    'housing' => 'Housing',
                    'inventory' => 'Inventory',
                ])
                ->required(),
            TextInput::make('record_key')->label('Record ID')->required()->maxLength(80),
            TextInput::make('name')->required()->maxLength(160),
            TextInput::make('category')->maxLength(120),
            TextInput::make('site')->maxLength(80),
            TextInput::make('status')->maxLength(80),
            TextInput::make('amount')->numeric()->prefix('$'),
            DatePicker::make('occurred_on'),
            KeyValue::make('payload')
                ->keyLabel('Field')
                ->valueLabel('Value')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('module')->badge()->sortable()->searchable(),
                TextColumn::make('record_key')->label('ID')->sortable()->searchable(),
                TextColumn::make('name')->sortable()->searchable(),
                TextColumn::make('category')->toggleable()->searchable(),
                TextColumn::make('site')->badge()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('amount')->money('USD')->sortable(),
                TextColumn::make('occurred_on')->date()->sortable(),
                TextColumn::make('updated_at')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('module')->options([
                    'hr' => 'HR / Attendance',
                    'wbs' => 'WBS / Project',
                    'equipment' => 'Equipment',
                    'finance' => 'Finance',
                    'safety' => 'Safety',
                    'vendors' => 'Vendors',
                    'vehicle' => 'Vehicle',
                    'rental' => 'Rental',
                    'housing' => 'Housing',
                    'inventory' => 'Inventory',
                ]),
                SelectFilter::make('site')->options([
                    'ALL' => 'Global',
                    'HFF-02' => 'HFF-02',
                    'LGES-AZ' => 'LGES-AZ',
                    'NV-05' => 'NV-05',
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
            'index' => ManageSmartRecords::route('/'),
        ];
    }
}

