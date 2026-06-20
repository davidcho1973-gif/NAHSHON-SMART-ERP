<?php

namespace App\Filament\Resources\Companies;

use App\Filament\Resources\Companies\Pages\ManageCompanies;
use App\Models\Company;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-briefcase';

    protected static ?string $navigationLabel = '원청사 관리 (Companies)';

    protected static ?string $modelLabel = '원청사/회사';

    protected static ?string $pluralModelLabel = '원청사 목록';

    protected static string | \UnitEnum | null $navigationGroup = 'SMART COMPANY';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('회사 코드')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(40),
            TextInput::make('name')
                ->label('회사명/원청사명')
                ->required()
                ->maxLength(255),
            TextInput::make('legal_name')
                ->label('법인명')
                ->maxLength(255),
            Select::make('status')
                ->label('상태')
                ->options([
                    'active' => 'Active',
                    'inactive' => 'Inactive',
                ])
                ->default('active')
                ->required(),
            Select::make('sites')
                ->label('담당 현장 (Sites)')
                ->relationship('sites', 'code')
                ->multiple()
                ->preload()
                ->searchable(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('회사 코드')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('name')
                    ->label('회사명')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('legal_name')
                    ->label('법인명')
                    ->toggleable()
                    ->searchable(),
                TextColumn::make('sites.code')
                    ->label('담당 현장')
                    ->badge()
                    ->wrap(),
                TextColumn::make('status')
                    ->label('상태')
                    ->badge()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('수정일')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                //
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
            'index' => ManageCompanies::route('/'),
        ];
    }
}
