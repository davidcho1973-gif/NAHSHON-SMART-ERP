<?php

namespace App\Filament\Resources\ItemCategories;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\ItemCategories\Pages\ManageItemCategories;
use App\Models\ItemCategory;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ItemCategoryResource extends Resource
{
    use AuthorizesResourceAccess;

    protected static function accessViewRoles(): array
    {
        return ['super_admin', 'admin', 'payroll', 'site_manager'];
    }

    protected static function accessManageRoles(): array
    {
        return ['super_admin', 'admin', 'payroll'];
    }

    // 전사 공통 참조 데이터 — company_id null 행이 파티셔닝에 걸러지면 안 된다.
    protected static function accessRowScoped(): bool
    {
        return false;
    }

    protected static ?string $model = ItemCategory::class;

    /** @var array<string, string> */
    public const STATUS_OPTIONS = [
        'active' => 'Active',
        'inactive' => 'Inactive',
    ];

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationLabel = 'Item Categories';

    protected static ?string $modelLabel = 'Item Category';

    protected static ?string $pluralModelLabel = 'Item Categories';

    protected static string | \UnitEnum | null $navigationGroup = 'MASTER DATA';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('parent_id')
                ->label('상위 분류')
                ->options(fn (?ItemCategory $record): array => ItemCategory::query()
                    ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                    ->orderBy('sort')
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->helperText('비워두면 1단계(대분류)입니다.'),
            TextInput::make('name')
                ->label('분류명')
                ->required()
                ->maxLength(255),
            TextInput::make('code')
                ->label('Code')
                ->maxLength(60),
            TextInput::make('sort')
                ->label('정렬 순서')
                ->numeric()
                ->default(0),
            Select::make('status')
                ->label('Status')
                ->options(self::STATUS_OPTIONS)
                ->default('active')
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('분류명')->searchable()->sortable(),
                TextColumn::make('parent.name')->label('상위 분류')->searchable()->sortable(),
                TextColumn::make('code')->label('Code')->searchable()->toggleable(),
                TextColumn::make('sort')->label('정렬')->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::STATUS_OPTIONS[$state] ?? (string) $state)
                    ->sortable(),
                TextColumn::make('updated_at')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('parent_id')
                    ->label('상위 분류')
                    ->options(fn (): array => ItemCategory::query()
                        ->whereNull('parent_id')
                        ->orderBy('sort')
                        ->pluck('name', 'id')
                        ->all()),
                SelectFilter::make('status')->label('Status')->options(self::STATUS_OPTIONS),
            ])
            ->defaultSort('sort')
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
            'index' => ManageItemCategories::route('/'),
        ];
    }
}
