<?php

namespace App\Filament\Resources\Items;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\ItemCategories\ItemCategoryResource;
use App\Filament\Resources\Items\Pages\ManageItems;
use App\Models\Company;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Support\CurrentCompany;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ItemResource extends Resource
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

    // 품목은 회사 단위 데이터 — 회사 파티셔닝만 적용(현장/팀/개인 범위 없음).
    protected static function accessScopeColumns(): array
    {
        return ['company' => 'company_id', 'site' => null, 'team' => null, 'self' => null];
    }

    // company_id null = 전사 공통 품목.
    protected static function accessIncludesSharedCompanyRows(): bool
    {
        return true;
    }

    protected static function accessCompanyWideVisibility(): bool
    {
        return true;
    }

    protected static ?string $model = Item::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationLabel = 'Items';

    protected static ?string $modelLabel = 'Item';

    protected static ?string $pluralModelLabel = 'Items';

    protected static string | \UnitEnum | null $navigationGroup = 'MASTER DATA';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('company_id')
                ->label('회사 (비우면 전사 공통)')
                ->options(fn (): array => Company::query()->orderBy('name')->pluck('name', 'id')->all())
                ->default(fn (): ?int => CurrentCompany::id())
                ->searchable(),
            Select::make('item_category_id')
                ->label('분류')
                ->options(fn (): array => ItemCategory::query()
                    ->orderBy('sort')
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable(),
            TextInput::make('code')
                ->label('Code (SKU)')
                ->maxLength(80),
            TextInput::make('name')
                ->label('품목명')
                ->required()
                ->maxLength(255),
            TextInput::make('unit')
                ->label('단위')
                ->default('EA')
                ->maxLength(20),
            TextInput::make('standard_cost')
                ->label('표준 단가')
                ->numeric()
                ->prefix('$'),
            Textarea::make('description')
                ->label('설명')
                ->columnSpanFull(),
            Select::make('status')
                ->label('Status')
                ->options(ItemCategoryResource::STATUS_OPTIONS)
                ->default('active')
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Code')->searchable()->sortable(),
                TextColumn::make('name')->label('품목명')->searchable()->sortable(),
                TextColumn::make('category.name')->label('분류')->searchable()->sortable(),
                TextColumn::make('unit')->label('단위')->toggleable(),
                TextColumn::make('standard_cost')->label('표준 단가')->money('USD')->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ItemCategoryResource::STATUS_OPTIONS[$state] ?? (string) $state)
                    ->sortable(),
                TextColumn::make('updated_at')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('item_category_id')
                    ->label('분류')
                    ->options(fn (): array => ItemCategory::query()
                        ->orderBy('sort')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),
                SelectFilter::make('status')->label('Status')->options(ItemCategoryResource::STATUS_OPTIONS),
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
            'index' => ManageItems::route('/'),
        ];
    }
}
