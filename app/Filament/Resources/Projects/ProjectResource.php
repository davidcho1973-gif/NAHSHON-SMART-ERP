<?php

namespace App\Filament\Resources\Projects;

use App\Filament\Resources\Projects\Pages\ManageProjects;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Site;
use App\Models\Team;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'PROJECT 관리 (Projects)';

    protected static ?string $modelLabel = 'PROJECT';

    protected static ?string $pluralModelLabel = 'PROJECT 목록';

    protected static string | \UnitEnum | null $navigationGroup = 'SMART COMPANY';

    protected static ?int $navigationSort = 1;

    private const MANAGE_ROLES = [
        'super_admin',
        'admin',
        'site_manager',
        'payroll',
    ];

    private const VIEW_ROLES = [
        'super_admin',
        'admin',
        'hr_manager',
        'site_manager',
        'safety_manager',
        'payroll',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Wizard::make([
                Step::make('기본')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->columns([
                        'default' => 1,
                        'lg' => 3,
                    ])
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
                            ->placeholder('LG에너지솔루션 애리조나 1공장 모듈 설치')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),
                        Select::make('construction_type')
                            ->label('공사 유형')
                            ->options(Project::CONSTRUCTION_TYPE_OPTIONS)
                            ->required()
                            ->searchable(),
                        self::companySelect('end_client_company_id', 'End Client (최종 발주처)', required: true)
                            ->helperText('공장/플랜트의 최종 고객사입니다. 예: LGES, SK hynix, Hyundai Motor'),
                        Select::make('project_stage')
                            ->label('프로젝트 단계')
                            ->options(Project::STAGE_OPTIONS)
                            ->default('estimate')
                            ->required(),
                        self::companySelect('company_id', '수행 회사 / 계약 법인', required: true)
                            ->helperText('우리 회사 또는 이 프로젝트를 수행하는 계약 법인입니다. 접근제어 company scope 기준 컬럼입니다.'),
                        Select::make('site_id')
                            ->label('현장 (Site)')
                            ->options(fn (): array => Site::query()->orderBy('code')->pluck('code', 'id')->all())
                            ->searchable(),
                        Select::make('employee_id')
                            ->label('프로젝트 담당자 / PM')
                            ->options(fn (): array => self::employeeOptions())
                            ->searchable(),
                    ]),

                Step::make('계약')
                    ->icon('heroicon-o-building-office')
                    ->columns([
                        'default' => 1,
                        'lg' => 3,
                    ])
                    ->schema([
                        Select::make('vendor_tier')
                            ->label('벤더 차수 (Tier)')
                            ->options(Project::VENDOR_TIER_OPTIONS)
                            ->searchable(),
                        self::companySelect('upper_contractor_company_id', '상위 원청사'),
                        self::companySelect('epc_company_id', 'EPC / 종합건설사'),
                        TextInput::make('po_number')
                            ->label('계약/PO 번호')
                            ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state, upper: true))
                            ->maxLength(120),
                        Select::make('contract_type')
                            ->label('계약 형태')
                            ->options(Project::CONTRACT_TYPE_OPTIONS)
                            ->searchable(),
                        TextInput::make('change_order_code_prefix')
                            ->label('Change Order 번호 체계')
                            ->placeholder('CO-LGES-AZ-')
                            ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state, upper: true))
                            ->maxLength(80),
                        Textarea::make('scope_of_work')
                            ->label('공사 범위 (Scope of Work)')
                            ->rows(5)
                            ->columnSpanFull(),
                    ]),

                Step::make('현장 일정')
                    ->icon('heroicon-o-map-pin')
                    ->columns([
                        'default' => 1,
                        'lg' => 4,
                    ])
                    ->schema([
                        Select::make('team_id')
                            ->label('담당 팀 (Team)')
                            ->options(fn (): array => Team::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable(),
                        TextInput::make('state')
                            ->label('State')
                            ->placeholder('AZ, GA, TX, TN')
                            ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state, upper: true))
                            ->maxLength(20),
                        TextInput::make('jurisdiction')
                            ->label('Jurisdiction')
                            ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state))
                            ->maxLength(120),
                        TextInput::make('sales_use_tax_code')
                            ->label('Sales/Use Tax 코드')
                            ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state, upper: true))
                            ->maxLength(80),
                        TextInput::make('site_address')
                            ->label('현장 주소')
                            ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state))
                            ->maxLength(255)
                            ->columnSpan(3),
                        TextInput::make('sales_use_tax_rate')
                            ->label('Tax Rate')
                            ->numeric()
                            ->suffix('%')
                            ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state))
                            ->maxLength(10),
                        DatePicker::make('ntp_date')
                            ->label('NTP'),
                        DatePicker::make('mobilization_date')
                            ->label('Mobilization'),
                        DatePicker::make('planned_completion_date')
                            ->label('준공 예정일'),
                        DatePicker::make('actual_completion_date')
                            ->label('실제 준공일'),
                        KeyValue::make('milestone_plan')
                            ->label('주요 마일스톤')
                            ->keyLabel('Milestone')
                            ->valueLabel('Target / Status')
                            ->helperText('예: Equipment inbound, Setting, Commissioning support')
                            ->columnSpanFull(),
                    ]),

                Step::make('재무 WBS')
                    ->icon('heroicon-o-banknotes')
                    ->columns([
                        'default' => 1,
                        'lg' => 4,
                    ])
                    ->schema([
                        TextInput::make('contract_amount')
                            ->label('계약(수주)금액')
                            ->numeric()
                            ->prefix('$')
                            ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state)),
                        Select::make('currency')
                            ->label('통화')
                            ->options([
                                'USD' => 'USD',
                                'KRW' => 'KRW',
                            ])
                            ->default('USD')
                            ->required(),
                        TextInput::make('retainage_percent')
                            ->label('Retainage')
                            ->numeric()
                            ->suffix('%')
                            ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state)),
                        TextInput::make('payment_terms')
                            ->label('기성/청구 조건')
                            ->placeholder('Progress Billing, Net 30')
                            ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state))
                            ->maxLength(120),
                        TextInput::make('budget_labor_amount')
                            ->label('예산 - 노무')
                            ->numeric()
                            ->prefix('$')
                            ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state)),
                        TextInput::make('budget_material_amount')
                            ->label('예산 - 자재')
                            ->numeric()
                            ->prefix('$')
                            ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state)),
                        TextInput::make('budget_equipment_amount')
                            ->label('예산 - 장비')
                            ->numeric()
                            ->prefix('$')
                            ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state)),
                        TextInput::make('budget_expense_amount')
                            ->label('예산 - 경비')
                            ->numeric()
                            ->prefix('$')
                            ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state)),
                        TextInput::make('cost_code_system')
                            ->label('Cost Code 체계')
                            ->placeholder('CSI Division / Internal')
                            ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state))
                            ->maxLength(120)
                            ->columnSpan(2),
                        TextInput::make('wbs_code')
                            ->label('WBS / Cost Center')
                            ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state, upper: true))
                            ->maxLength(120)
                            ->columnSpan(2),
                    ]),

                Step::make('규정/리소스')
                    ->icon('heroicon-o-shield-check')
                    ->schema([
                        Grid::make([
                            'default' => 1,
                            'lg' => 4,
                        ])
                            ->schema([
                                Toggle::make('prevailing_wage_required')
                                    ->label('Prevailing Wage'),
                                Toggle::make('davis_bacon_required')
                                    ->label('Davis-Bacon'),
                                Toggle::make('certified_payroll_required')
                                    ->label('Certified Payroll'),
                                Toggle::make('bonding_required')
                                    ->label('Bonding required'),
                                Select::make('union_status')
                                    ->label('Union / Open Shop')
                                    ->options(Project::UNION_STATUS_OPTIONS),
                                Select::make('ocip_ccip_status')
                                    ->label('OCIP / CCIP')
                                    ->options(Project::OCIP_CCIP_OPTIONS),
                                Select::make('osha_plan_status')
                                    ->label('OSHA 안전계획')
                                    ->options(Project::OSHA_PLAN_OPTIONS),
                                Toggle::make('lien_notice_required')
                                    ->label('Lien / Preliminary Notice'),
                                DatePicker::make('preliminary_notice_due_on')
                                    ->label('Preliminary Notice Due')
                                    ->columnSpan(2),
                            ]),
                        Grid::make([
                            'default' => 1,
                            'lg' => 2,
                        ])
                            ->schema([
                                KeyValue::make('insurance_requirements')
                                    ->label('보험 요구사항')
                                    ->keyLabel('Insurance')
                                    ->valueLabel('Required / Status')
                                    ->helperText('예: GL, Workers Comp, Builder Risk, Auto'),
                                KeyValue::make('workforce_plan')
                                    ->label('현장 인력 계획')
                                    ->keyLabel('Role / Trade')
                                    ->valueLabel('Headcount / Notes')
                                    ->helperText('예: Foreman, Fitter, Welder, Rigger, Helper'),
                                KeyValue::make('korean_dispatch_plan')
                                    ->label('한국 파견 인력 / 비자')
                                    ->keyLabel('Name / Role')
                                    ->valueLabel('Visa / Status / Dates'),
                                KeyValue::make('equipment_plan')
                                    ->label('투입 장비 / 공구')
                                    ->keyLabel('Equipment / Tool')
                                    ->valueLabel('Owned / Rental / Vendor'),
                                KeyValue::make('subcontractor_plan')
                                    ->label('하도급 협력사 (Sub-sub)')
                                    ->keyLabel('Vendor')
                                    ->valueLabel('Scope / Contact / Status'),
                                Textarea::make('per_diem_policy')
                                    ->label('Per Diem / 출장비 기준')
                                    ->rows(4),
                                KeyValue::make('master_data_links')
                                    ->label('연계 마스터 데이터')
                                    ->keyLabel('Master')
                                    ->valueLabel('Reference'),
                                KeyValue::make('payload')
                                    ->label('Extra Data')
                                    ->keyLabel('Field')
                                    ->valueLabel('Value'),
                            ]),
                    ]),
            ])
                ->startOnStep(1)
                ->skippable()
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('project_code')
                    ->label('프로젝트 코드')
                    ->badge()
                    ->sortable()
                    ->searchable(),
                TextColumn::make('name')
                    ->label('프로젝트명')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('project_stage')
                    ->label('단계')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Project::STAGE_OPTIONS[$state] ?? (string) $state)
                    ->sortable(),
                TextColumn::make('construction_type')
                    ->label('공사 유형')
                    ->formatStateUsing(fn (?string $state): string => Project::CONSTRUCTION_TYPE_OPTIONS[$state] ?? (string) $state)
                    ->toggleable(),
                TextColumn::make('vendor_tier')
                    ->label('Tier')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Project::VENDOR_TIER_OPTIONS[$state] ?? (string) $state)
                    ->sortable(),
                TextColumn::make('endClient.name')
                    ->label('End Client')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('upperContractor.name')
                    ->label('상위 원청사')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('site.code')
                    ->label('Site')
                    ->badge()
                    ->sortable(),
                TextColumn::make('state')
                    ->label('State')
                    ->badge()
                    ->sortable(),
                TextColumn::make('contract_amount')
                    ->label('계약금액')
                    ->money('USD')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('planned_completion_date')
                    ->label('준공 예정')
                    ->date()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label('수정일')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('project_stage')
                    ->label('단계')
                    ->options(Project::STAGE_OPTIONS),
                SelectFilter::make('construction_type')
                    ->label('공사 유형')
                    ->options(Project::CONSTRUCTION_TYPE_OPTIONS),
                SelectFilter::make('vendor_tier')
                    ->label('Tier')
                    ->options(Project::VENDOR_TIER_OPTIONS),
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

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if (in_array($user->access_role, ['super_admin', 'admin'], true) || $user->access_scope === 'all_sites') {
            return $query;
        }

        return match ($user->access_scope) {
            'company' => filled($user->allowed_company_id)
                ? $query->where('company_id', $user->allowed_company_id)
                : $query->whereRaw('1 = 0'),
            'site' => filled($user->allowed_site_id)
                ? $query->where('site_id', $user->allowed_site_id)
                : $query->whereRaw('1 = 0'),
            'team' => filled($user->allowed_team_id)
                ? $query->where('team_id', $user->allowed_team_id)
                : $query->whereRaw('1 = 0'),
            'self' => filled($user->employee_id)
                ? $query->where('employee_id', $user->employee_id)
                : $query->whereRaw('1 = 0'),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public static function canViewAny(): bool
    {
        return self::currentUserHasRole(self::VIEW_ROLES);
    }

    public static function canCreate(): bool
    {
        return self::currentUserHasRole(self::MANAGE_ROLES);
    }

    public static function canEdit($record): bool
    {
        return self::currentUserHasRole(self::MANAGE_ROLES);
    }

    public static function canDelete($record): bool
    {
        return self::currentUserHasRole(['super_admin', 'admin']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageProjects::route('/'),
        ];
    }

    private static function companyOptions(): array
    {
        return Company::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private static function companySelect(string $field, string $label, bool $required = false): Select
    {
        $select = Select::make($field)
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

        if ($required) {
            $select->required();
        }

        return $select;
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

    private static function employeeOptions(): array
    {
        return Employee::query()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Employee $employee): array => [
                $employee->id => "{$employee->name} ({$employee->employee_number})",
            ])
            ->all();
    }

    private static function nullableText(mixed $state, bool $upper = false): ?string
    {
        if (! filled($state)) {
            return null;
        }

        $value = trim((string) $state);

        return $upper ? strtoupper($value) : $value;
    }

    private static function currentUserHasRole(array $roles): bool
    {
        $user = auth()->user();

        return $user && $user->account_status === 'active' && in_array($user->access_role, $roles, true);
    }
}
