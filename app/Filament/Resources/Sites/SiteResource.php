<?php

namespace App\Filament\Resources\Sites;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\Sites\Pages\ManageSites;
use App\Models\Company;
use App\Models\Site;
use App\Models\SiteContractor;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SiteResource extends Resource
{
    use AuthorizesResourceAccess;

    // Site directory is reference data: broadly viewable, only admins manage it.
    protected static function accessViewRoles(): array
    {
        return ['super_admin', 'admin', 'hr_manager', 'site_manager', 'safety_manager', 'payroll'];
    }

    protected static function accessRowScoped(): bool
    {
        return false;
    }

    protected static ?string $model = Site::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = '현장 관리 (Sites)';

    protected static ?string $modelLabel = '현장';

    protected static ?string $pluralModelLabel = '현장 목록';

    protected static string | \UnitEnum | null $navigationGroup = 'SMART COMPANY';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('현장 기본정보')
                ->columns([
                    'default' => 1,
                    'lg' => 2,
                ])
                ->schema([
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
                        ->label('현장 주소')
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Select::make('company_id')
                        ->label('대표 관리 회사')
                        ->options(fn (): array => self::companyOptions())
                        ->searchable()
                        ->helperText('접근제어와 현장 QR 기본 회사로 사용할 대표 회사를 선택합니다.'),
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
                ]),

            Section::make('계약 회사')
                ->description('이 현장에서 우리와 계약했거나 함께 공사를 진행하는 회사를 추가합니다.')
                ->schema([
                    Repeater::make('contractors')
                        ->label('계약 회사 목록')
                        ->relationship()
                        ->schema([
                            TextInput::make('company_name')
                                ->label('계약 회사명')
                                ->required()
                                ->maxLength(255),
                            Select::make('company_id')
                                ->label('기존 회사 연결')
                                ->options(fn (): array => self::companyOptions())
                                ->searchable(),
                            Select::make('contract_role')
                                ->label('회사 역할')
                                ->options(SiteContractor::ROLE_OPTIONS)
                                ->searchable(),
                            TextInput::make('contract_number')
                                ->label('계약/PO 번호')
                                ->maxLength(120),
                            DatePicker::make('starts_on')
                                ->label('계약 시작일'),
                            DatePicker::make('ends_on')
                                ->label('계약 종료일'),
                            TextInput::make('primary_contact_name')
                                ->label('담당자')
                                ->maxLength(120),
                            TextInput::make('primary_contact_phone')
                                ->label('담당자 전화')
                                ->tel()
                                ->maxLength(80),
                            Select::make('status')
                                ->label('상태')
                                ->options(SiteContractor::STATUS_OPTIONS)
                                ->default('active')
                                ->required(),
                            Textarea::make('scope_of_work')
                                ->label('공사 범위')
                                ->rows(3)
                                ->columnSpanFull(),
                            Textarea::make('notes')
                                ->label('메모')
                                ->rows(2)
                                ->columnSpanFull(),
                        ])
                        ->columns([
                            'default' => 1,
                            'lg' => 3,
                        ])
                        ->addActionLabel('계약 회사 추가')
                        ->defaultItems(0)
                        ->reorderable(false)
                        ->collapsible(),
                ])
                ->columnSpanFull(),

            Section::make('팀 / 조직')
                ->description('계약 회사별 전기팀, 배관팀, 리깅팀 등 현장 운영 조직을 관리합니다.')
                ->schema([
                    Repeater::make('teams')
                        ->label('팀 목록')
                        ->relationship()
                        ->schema([
                            TextInput::make('code')
                                ->label('팀 코드')
                                ->required()
                                ->maxLength(60),
                            TextInput::make('name')
                                ->label('팀명')
                                ->required()
                                ->maxLength(255),
                            TextInput::make('contract_company_name')
                                ->label('소속 계약 회사명')
                                ->placeholder('예: A Company')
                                ->maxLength(255),
                            Select::make('company_id')
                                ->label('기존 회사 연결')
                                ->options(fn (): array => self::companyOptions())
                                ->searchable(),
                            Select::make('trade_type')
                                ->label('공종')
                                ->options([
                                    'electrical' => '전기팀',
                                    'piping' => '배관팀',
                                    'mechanical' => '기계설치팀',
                                    'rigging' => '리깅팀',
                                    'welding' => '용접팀',
                                    'safety' => '안전팀',
                                    'general' => '일반/기타',
                                ])
                                ->searchable(),
                            TextInput::make('planned_headcount')
                                ->label('예상 인원')
                                ->numeric()
                                ->minValue(0),
                            TextInput::make('foreman_name')
                                ->label('반장')
                                ->maxLength(120),
                            TextInput::make('responsible_manager_name')
                                ->label('책임자')
                                ->maxLength(120),
                            TextInput::make('supervisor_name')
                                ->label('현장 Supervisor')
                                ->maxLength(120),
                            TextInput::make('supervisor_phone')
                                ->label('Supervisor 전화')
                                ->tel()
                                ->maxLength(80),
                            Select::make('status')
                                ->label('상태')
                                ->options([
                                    'active' => 'Active',
                                    'inactive' => 'Inactive',
                                ])
                                ->default('active')
                                ->required(),
                            Textarea::make('notes')
                                ->label('팀 메모')
                                ->rows(2)
                                ->columnSpanFull(),
                        ])
                        ->columns([
                            'default' => 1,
                            'lg' => 3,
                        ])
                        ->addActionLabel('팀 추가')
                        ->defaultItems(0)
                        ->reorderable(false)
                        ->collapsible(),
                ])
                ->columnSpanFull(),
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
                TextColumn::make('address')
                    ->label('주소')
                    ->limit(36)
                    ->toggleable(),
                TextColumn::make('contractors.company_name')
                    ->label('계약 회사')
                    ->badge()
                    ->wrap(),
                TextColumn::make('teams.name')
                    ->label('팀')
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

    private static function companyOptions(): array
    {
        return Company::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
