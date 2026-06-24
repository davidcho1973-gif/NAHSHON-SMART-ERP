<?php

namespace App\Filament\Resources\Sites;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\Sites\Pages\ManageSites;
use App\Models\Company;
use App\Models\Project;
use App\Models\Site;
use App\Models\SiteContractor;
use App\Models\Team;
use Filament\Actions\Action;
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
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

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

    protected static ?string $navigationLabel = '현장 / PROJECT 관리';

    protected static ?string $modelLabel = '현장 / PROJECT';

    protected static ?string $pluralModelLabel = '현장 / PROJECT 목록';

    protected static string | \UnitEnum | null $navigationGroup = 'SMART COMPANY';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('현장 통합 관리')
                ->tabs([
                    Tab::make('기본정보')
                        ->icon('heroicon-o-building-office-2')
                        ->schema([
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
                                    self::companySelect('company_id', '대표 관리 회사')
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
                        ]),

                    Tab::make('PROJECT')
                        ->icon('heroicon-o-clipboard-document-list')
                        ->schema([
                            Section::make('현장 PROJECT')
                                ->description('이 현장에서 진행되는 공사/계약 건을 추가합니다. 새 PROJECT는 자동으로 이 현장에 연결됩니다.')
                                ->schema([
                                    Repeater::make('projects')
                                        ->label('PROJECT 목록')
                                        ->relationship()
                                        ->schema([
                                            TextInput::make('project_code')
                                                ->label('프로젝트 코드')
                                                ->placeholder('자동 생성')
                                                ->helperText('비워두면 End Client / State / Year 기준으로 생성됩니다.')
                                                ->unique(ignoreRecord: true)
                                                ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state, upper: true))
                                                ->maxLength(80),
                                            TextInput::make('name')
                                                ->label('프로젝트명')
                                                ->required()
                                                ->maxLength(255),
                                            Select::make('construction_type')
                                                ->label('공사 유형')
                                                ->options(Project::CONSTRUCTION_TYPE_OPTIONS)
                                                ->required()
                                                ->searchable(),
                                            self::companySelect('end_client_company_id', 'End Client (최종 발주처)')
                                                ->required(),
                                            self::companySelect('company_id', '수행 회사 / 계약 법인')
                                                ->required(),
                                            Select::make('project_stage')
                                                ->label('프로젝트 단계')
                                                ->options(Project::STAGE_OPTIONS)
                                                ->default('estimate')
                                                ->required(),
                                            Select::make('vendor_tier')
                                                ->label('벤더 차수')
                                                ->options(Project::VENDOR_TIER_OPTIONS)
                                                ->searchable(),
                                            self::companySelect('upper_contractor_company_id', '상위 원청사'),
                                            self::companySelect('epc_company_id', 'EPC / 종합건설사'),
                                            Select::make('team_id')
                                                ->label('담당 팀')
                                                ->options(fn (): array => self::teamOptions())
                                                ->searchable(),
                                            TextInput::make('po_number')
                                                ->label('계약/PO 번호')
                                                ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state, upper: true))
                                                ->maxLength(120),
                                            Select::make('contract_type')
                                                ->label('계약 형태')
                                                ->options(Project::CONTRACT_TYPE_OPTIONS)
                                                ->searchable(),
                                            TextInput::make('state')
                                                ->label('State')
                                                ->placeholder('AZ, GA, TX, TN')
                                                ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state, upper: true))
                                                ->maxLength(20),
                                            TextInput::make('contract_amount')
                                                ->label('계약금액')
                                                ->numeric()
                                                ->prefix('$')
                                                ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state)),
                                            DatePicker::make('ntp_date')
                                                ->label('NTP'),
                                            DatePicker::make('mobilization_date')
                                                ->label('Mobilization'),
                                            DatePicker::make('planned_completion_date')
                                                ->label('준공 예정일'),
                                            Textarea::make('scope_of_work')
                                                ->label('공사 범위')
                                                ->rows(3)
                                                ->columnSpanFull(),
                                        ])
                                        ->columns([
                                            'default' => 1,
                                            'lg' => 3,
                                        ])
                                        ->addActionLabel('PROJECT 추가')
                                        ->defaultItems(0)
                                        ->reorderable(false)
                                        ->collapsible(),
                                ]),
                        ]),

                    Tab::make('계약 회사')
                        ->icon('heroicon-o-building-office')
                        ->schema([
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
                                            self::companySelect('company_id', '기존 회사 연결'),
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
                                ]),
                        ]),

                    Tab::make('팀 / 조직')
                        ->icon('heroicon-o-users')
                        ->schema([
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
                                            self::companySelect('company_id', '기존 회사 연결'),
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
                                ]),
                        ]),
                ])
                ->persistTabInQueryString('site-tab')
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
                TextColumn::make('projects_count')
                    ->label('PROJECT')
                    ->counts('projects')
                    ->badge()
                    ->sortable(),
                TextColumn::make('projects.name')
                    ->label('PROJECT명')
                    ->badge()
                    ->wrap()
                    ->toggleable(),
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
                Action::make('siteQr')
                    ->label('현장 QR')
                    ->icon('heroicon-o-qr-code')
                    ->url(fn (Site $record): string => route('member-registration.site.qr', ['site' => $record]))
                    ->openUrlInNewTab(),
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

    private static function teamOptions(): array
    {
        return Team::query()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Team $team): array => [
                $team->id => "{$team->name} ({$team->code})",
            ])
            ->all();
    }

    private static function companySelect(string $field, string $label): Select
    {
        return Select::make($field)
            ->label($label)
            ->options(fn (): array => self::companyOptions())
            ->searchable()
            ->preload()
            ->createOptionForm(self::companyCreateForm())
            ->createOptionUsing(fn (array $data): int => self::createCompany($data))
            ->createOptionAction(fn ($action) => $action
                ->label('새 회사 추가')
                ->modalHeading('새 회사 등록')
                ->modalSubmitActionLabel('등록'));
    }

    /**
     * @return array<int, mixed>
     */
    private static function companyCreateForm(): array
    {
        return [
            TextInput::make('name')
                ->label('회사명')
                ->required()
                ->maxLength(255),
            TextInput::make('code')
                ->label('회사 코드')
                ->helperText('비워두면 회사명 기준으로 자동 생성됩니다.')
                ->maxLength(40),
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
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function createCompany(array $data): int
    {
        return Company::query()->create([
            'code' => self::uniqueCompanyCode((string) ($data['code'] ?? ''), (string) $data['name']),
            'name' => trim((string) $data['name']),
            'legal_name' => self::nullableText($data['legal_name'] ?? null),
            'status' => (string) ($data['status'] ?? 'active'),
        ])->getKey();
    }

    private static function uniqueCompanyCode(string $code, string $name): string
    {
        $base = filled($code) ? $code : $name;
        $base = Str::ascii(Str::upper($base));
        $base = preg_replace('/[^A-Z0-9]+/', '-', $base) ?: 'COMPANY';
        $base = trim($base, '-') ?: 'COMPANY';
        $base = substr($base, 0, 32);
        $candidate = $base;
        $sequence = 2;

        while (Company::query()->where('code', $candidate)->exists()) {
            $candidate = substr($base, 0, 35) . '-' . $sequence;
            $sequence++;
        }

        return $candidate;
    }

    private static function nullableText(mixed $state, bool $upper = false): ?string
    {
        if (! filled($state)) {
            return null;
        }

        $value = trim((string) $state);

        if ($value === '') {
            return null;
        }

        return $upper ? strtoupper($value) : $value;
    }
}
