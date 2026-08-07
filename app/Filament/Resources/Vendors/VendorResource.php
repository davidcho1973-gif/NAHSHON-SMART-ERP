<?php

namespace App\Filament\Resources\Vendors;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\Vendors\Pages\ManageVendors;
use App\Models\Company;
use App\Models\Vendor;
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

class VendorResource extends Resource
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

    // 거래처는 회사 단위 데이터 — 회사 파티셔닝만 적용(현장/팀/개인 범위 없음).
    protected static function accessScopeColumns(): array
    {
        return ['company' => 'company_id', 'site' => null, 'team' => null, 'self' => null];
    }

    // company_id null = 전사 공통 거래처 (SPA의 applyVendorUserScope 규칙과 일치).
    protected static function accessIncludesSharedCompanyRows(): bool
    {
        return true;
    }

    protected static function accessCompanyWideVisibility(): bool
    {
        return true;
    }

    protected static ?string $model = Vendor::class;

    /** @var array<string, string> */
    public const STATUS_OPTIONS = [
        'active' => 'Active',
        'inactive' => 'Inactive',
    ];

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Vendors';

    protected static ?string $modelLabel = 'Vendor';

    protected static ?string $pluralModelLabel = 'Vendors';

    protected static string | \UnitEnum | null $navigationGroup = 'MASTER DATA';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('company_id')
                ->label('회사 (비우면 전사 공통)')
                ->options(fn (): array => Company::query()->orderBy('name')->pluck('name', 'id')->all())
                ->default(fn (): ?int => CurrentCompany::id())
                ->searchable(),
            TextInput::make('name')
                ->label('업체명')
                ->required()
                ->maxLength(255),
            TextInput::make('contact_name')
                ->label('담당자')
                ->maxLength(255),
            TextInput::make('phone')
                ->label('전화')
                ->maxLength(255),
            TextInput::make('email')
                ->label('이메일')
                ->email()
                ->maxLength(255),
            TextInput::make('address')
                ->label('주소')
                ->maxLength(255),
            TextInput::make('trade')
                ->label('업종 (Trade)')
                ->maxLength(120),
            Select::make('status')
                ->label('Status')
                ->options(self::STATUS_OPTIONS)
                ->default('active')
                ->required(),
            Textarea::make('notes')
                ->label('메모')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('업체명')->searchable()->sortable(),
                TextColumn::make('contact_name')->label('담당자')->searchable()->toggleable(),
                TextColumn::make('phone')->label('전화')->searchable()->toggleable(),
                TextColumn::make('trade')->label('업종')->searchable()->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::STATUS_OPTIONS[$state] ?? (string) $state)
                    ->sortable(),
                TextColumn::make('updated_at')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options(self::STATUS_OPTIONS),
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
            'index' => ManageVendors::route('/'),
        ];
    }
}
