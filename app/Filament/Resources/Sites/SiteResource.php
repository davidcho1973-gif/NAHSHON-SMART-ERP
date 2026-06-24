<?php

namespace App\Filament\Resources\Sites;

use App\Filament\Resources\Sites\Pages\ManageSites;
use App\Models\Site;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SiteResource extends Resource
{
    protected static ?string $model = Site::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = '현장 관리 (Sites)';

    protected static ?string $modelLabel = '현장';

    protected static ?string $pluralModelLabel = '현장 목록';

    protected static string | \UnitEnum | null $navigationGroup = 'SMART COMPANY';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('현장 코드')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(60),
            TextInput::make('name')
                ->label('현장명')
                ->required()
                ->maxLength(255),
            TextInput::make('address')
                ->label('주소')
                ->maxLength(255),
            TextInput::make('timezone')
                ->label('타임존')
                ->default('America/Phoenix')
                ->required()
                ->maxLength(60),
            Select::make('status')
                ->label('상태')
                ->options([
                    'active' => 'Active',
                    'inactive' => 'Inactive',
                ])
                ->default('active')
                ->required(),
            Select::make('companies')
                ->label('관련 거래처 (Companies)')
                ->relationship('companies', 'name', fn ($query) => $query->select('companies.id', 'companies.name'))
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
                    ->label('현장 코드')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('name')
                    ->label('현장명')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('companies.name')
                    ->label('관련 거래처')
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
            'index' => ManageSites::route('/'),
        ];
    }
}
