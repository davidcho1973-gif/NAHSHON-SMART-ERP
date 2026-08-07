<?php

namespace App\Filament\Resources\Housing;

use App\Filament\Resources\Housing\Pages\ManageHousing;
use App\Models\Housing;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class HousingResource extends Resource
{
    protected static ?string $model = Housing::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-home-modern';

    protected static ?string $navigationLabel = '숙소 관리 (Housing)';

    protected static ?string $modelLabel = '숙소';

    protected static ?string $pluralModelLabel = '숙소 목록';

    protected static string | \UnitEnum | null $navigationGroup = 'SMART COMPANY';

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            'available' => '입주 가능 (Available)',
            'full' => '만실 (Full)',
            'maintenance' => '수리 필요 (Maintenance)',
            'inactive' => '미사용 (Inactive)',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('숙소 코드 (Code)')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(60),
            TextInput::make('name')
                ->label('숙소명 (Name)')
                ->required()
                ->maxLength(255),
            Select::make('company_id')
                ->label('원청사 (Company)')
                ->relationship('company', 'name')
                ->searchable()
                ->preload(),
            Select::make('site_id')
                ->label('현장 (Site)')
                ->relationship('site', 'code')
                ->searchable()
                ->preload(),
            TextInput::make('address')
                ->label('주소 (Address)')
                ->maxLength(255)
                ->columnSpanFull(),
            TextInput::make('beds')
                ->label('총 침대 수 (Beds)')
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->required(),
            TextInput::make('occupied')
                ->label('입주 인원 (Occupied)')
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->required(),
            TextInput::make('monthly_rent')
                ->label('월세 (Monthly rent)')
                ->numeric()
                ->prefix('$')
                ->minValue(0),
            Select::make('status')
                ->label('상태 (Status)')
                ->options(self::statusOptions())
                ->default('available')
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('숙소 코드')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('name')
                    ->label('숙소명')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('site.code')
                    ->label('현장')
                    ->badge()
                    ->placeholder('-'),
                TextColumn::make('company.name')
                    ->label('원청사')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('beds')
                    ->label('침대 수')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('occupied')
                    ->label('입주 인원')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('monthly_rent')
                    ->label('월세')
                    ->money('USD')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('상태')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::statusOptions()[$state] ?? (string) $state)
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('수정일')
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('상태')
                    ->options(self::statusOptions()),
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

    /**
     * Apply the access-scope row restriction (AGENTS.md §6-3) to every query the
     * panel runs for this resource.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(Auth::user());
    }

    protected static function isManager(): bool
    {
        return in_array(Auth::user()?->access_role, ['super_admin', 'admin', 'site_manager'], true);
    }

    public static function canCreate(): bool
    {
        return static::isManager();
    }

    public static function canEdit(Model $record): bool
    {
        return static::isManager();
    }

    public static function canDelete(Model $record): bool
    {
        return static::isManager();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageHousing::route('/'),
        ];
    }
}
