<?php

namespace App\Filament\Resources\ProjectContracts;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\ProjectContracts\Pages\ManageProjectContractDocuments;
use App\Filament\Resources\ProjectContracts\Pages\ManageProjectContracts;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\ProjectContractDocument;
use App\Models\Site;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProjectContractResource extends Resource
{
    use AuthorizesResourceAccess;

    protected static ?string $model = ProjectContract::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-folder-open';

    protected static ?string $navigationLabel = '원청 계약·서류 (Contracts)';

    protected static ?string $modelLabel = '계약';

    protected static ?string $pluralModelLabel = '원청 계약·서류';

    protected static string|\UnitEnum|null $navigationGroup = 'SMART COMPANY';

    protected static ?int $navigationSort = 2;

    protected static function accessViewRoles(): array
    {
        return ['super_admin', 'admin', 'site_manager', 'payroll'];
    }

    protected static function accessManageRoles(): array
    {
        return ['super_admin', 'admin', 'site_manager'];
    }

    protected static function accessDeleteRoles(): array
    {
        return ['super_admin', 'admin'];
    }

    protected static function accessScopeColumns(): array
    {
        return [
            'company' => 'company_id',
            'site' => 'site_id',
            'team' => null,
            'self' => 'manager_employee_id',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('계약 통합 관리')
                ->tabs([
                    Tab::make('계약 기본')
                        ->icon('heroicon-o-document-text')
                        ->schema([
                            Section::make('① 계약 식별 및 PROJECT 연결')
                                ->description('사내 관리번호는 저장할 때 자동 생성됩니다. 원청사와 해당 PROJECT를 정확히 연결하세요.')
                                ->columns(['default' => 1, 'lg' => 3])
                                ->schema([
                                    TextInput::make('internal_reference')
                                        ->label('사내 계약번호')
                                        ->placeholder('CTR-YYYY-00000 자동 생성')
                                        ->disabled()
                                        ->dehydrated(false),
                                    TextInput::make('contract_number')
                                        ->label('원청 계약번호 / PO')
                                        ->maxLength(120),
                                    TextInput::make('title')
                                        ->label('계약명')
                                        ->required()
                                        ->maxLength(255),
                                    Select::make('project_id')
                                        ->label('PROJECT')
                                        ->options(fn (): array => self::projectOptions())
                                        ->searchable()
                                        ->preload()
                                        ->live()
                                        ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                            $project = filled($state) ? Project::query()->find($state) : null;

                                            if (! $project) {
                                                return;
                                            }

                                            $set('company_id', $project->company_id);
                                            $set('site_id', $project->site_id);
                                            $set('counterparty_company_id', $project->upper_contractor_company_id
                                                ?: $project->epc_company_id
                                                ?: $project->end_client_company_id);

                                            if (blank($get('title'))) {
                                                $set('title', $project->name.' 계약');
                                            }
                                        }),
                                    Select::make('site_id')
                                        ->label('현장')
                                        ->options(fn (): array => self::siteOptions())
                                        ->searchable()
                                        ->preload(),
                                    self::companySelect('company_id', '수행 회사 / 우리 계약 법인', true)
                                        ->required(),
                                    self::companySelect('counterparty_company_id', '계약 상대 원청사')
                                        ->required(),
                                    Select::make('counterparty_role')
                                        ->label('상대 회사 역할')
                                        ->options(ProjectContract::COUNTERPARTY_ROLE_OPTIONS)
                                        ->default('general_contractor')
                                        ->required()
                                        ->searchable(),
                                    Select::make('manager_employee_id')
                                        ->label('사내 계약 책임자')
                                        ->options(fn (): array => self::employeeOptions())
                                        ->searchable(),
                                    Select::make('direction')
                                        ->label('계약 방향')
                                        ->options(ProjectContract::DIRECTION_OPTIONS)
                                        ->default('receivable')
                                        ->required(),
                                    Select::make('contract_type')
                                        ->label('계약 유형')
                                        ->options(ProjectContract::TYPE_OPTIONS)
                                        ->default('prime_contract')
                                        ->required(),
                                    Select::make('status')
                                        ->label('계약 상태')
                                        ->options(ProjectContract::STATUS_OPTIONS)
                                        ->default('draft')
                                        ->required(),
                                    Select::make('risk_level')
                                        ->label('리스크 등급')
                                        ->options(ProjectContract::RISK_OPTIONS)
                                        ->default('low')
                                        ->required(),
                                    Textarea::make('scope_of_work')
                                        ->label('계약 공사 범위 (Scope of Work)')
                                        ->rows(5)
                                        ->columnSpanFull(),
                                ]),
                        ]),

                    Tab::make('금액·조건')
                        ->icon('heroicon-o-banknotes')
                        ->schema([
                            Section::make('② 계약 금액과 지급 조건')
                                ->description('원계약, 승인된 Change Order, 현재 계약금액을 분리해 관리합니다.')
                                ->columns(['default' => 1, 'lg' => 3])
                                ->schema([
                                    TextInput::make('original_amount')
                                        ->label('원계약 금액')
                                        ->numeric()
                                        ->prefix('$'),
                                    TextInput::make('approved_change_amount')
                                        ->label('승인 변경금액')
                                        ->numeric()
                                        ->default(0)
                                        ->prefix('$'),
                                    TextInput::make('current_amount')
                                        ->label('현재 계약금액')
                                        ->numeric()
                                        ->helperText('비워두면 원계약 + 승인 변경금액으로 자동 계산됩니다.')
                                        ->prefix('$'),
                                    TextInput::make('currency')
                                        ->label('통화')
                                        ->default('USD')
                                        ->required()
                                        ->maxLength(3),
                                    TextInput::make('retainage_percent')
                                        ->label('Retainage')
                                        ->numeric()
                                        ->suffix('%')
                                        ->minValue(0)
                                        ->maxValue(100),
                                    TextInput::make('payment_terms')
                                        ->label('Payment Terms')
                                        ->placeholder('예: Progress Billing, Net 30')
                                        ->maxLength(120),
                                ]),
                        ]),

                    Tab::make('일정·갱신')
                        ->icon('heroicon-o-calendar-days')
                        ->schema([
                            Section::make('③ 주요 계약일과 후속조치')
                                ->description('종료일과 갱신 통보기한을 기준으로 만료 예정 계약을 추적합니다.')
                                ->columns(['default' => 1, 'lg' => 3])
                                ->schema([
                                    DatePicker::make('executed_on')->label('서명일'),
                                    DatePicker::make('effective_on')->label('효력 발생일'),
                                    DatePicker::make('notice_to_proceed_on')->label('NTP 발행일'),
                                    DatePicker::make('starts_on')->label('계약 시작일'),
                                    DatePicker::make('ends_on')->label('계약 종료일'),
                                    TextInput::make('renewal_notice_days')
                                        ->label('갱신 사전알림')
                                        ->numeric()
                                        ->default(60)
                                        ->suffix('일')
                                        ->minValue(0),
                                    DatePicker::make('next_action_on')->label('다음 조치일'),
                                    Textarea::make('next_action_notes')
                                        ->label('다음 조치 내용')
                                        ->rows(3)
                                        ->columnSpan(['default' => 1, 'lg' => 2]),
                                ]),
                        ]),

                    Tab::make('준법·연락처')
                        ->icon('heroicon-o-shield-check')
                        ->schema([
                            Section::make('④ 필수 준법서류')
                                ->description('필수로 켠 항목은 서류 페이지에서 COI·Bond·임금 관련 문서를 등록하고 만료일을 관리하세요.')
                                ->columns(['default' => 1, 'lg' => 3])
                                ->schema([
                                    Toggle::make('insurance_required')->label('COI / 보험 필수'),
                                    Toggle::make('bond_required')->label('Bond / 보증서 필수'),
                                    Toggle::make('prevailing_wage_required')->label('Prevailing Wage'),
                                    Toggle::make('certified_payroll_required')->label('Certified Payroll'),
                                    Toggle::make('lien_notice_required')->label('Lien Notice / Waiver'),
                                ]),
                            Section::make('원청 담당자와 내부 메모')
                                ->columns(['default' => 1, 'lg' => 3])
                                ->schema([
                                    TextInput::make('counterparty_contact_name')
                                        ->label('원청 담당자')
                                        ->maxLength(120),
                                    TextInput::make('counterparty_contact_email')
                                        ->label('원청 이메일')
                                        ->email()
                                        ->maxLength(255),
                                    TextInput::make('counterparty_contact_phone')
                                        ->label('원청 전화')
                                        ->tel()
                                        ->maxLength(80),
                                    Textarea::make('notes')
                                        ->label('내부 계약 메모')
                                        ->rows(5)
                                        ->columnSpanFull(),
                                ]),
                        ]),
                ])
                ->persistTabInQueryString('contract-tab')
                ->columnSpanFull(),
        ]);
    }

    public static function documentForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('계약 서류 파일')
                ->description('계약 파일은 public 폴더가 아닌 사설 저장소에 보관되며, 권한이 있는 로그인 사용자만 다운로드할 수 있습니다.')
                ->columns(['default' => 1, 'lg' => 2])
                ->schema([
                    Select::make('document_type')
                        ->label('서류 유형')
                        ->options(ProjectContractDocument::TYPE_OPTIONS)
                        ->required()
                        ->searchable(),
                    TextInput::make('title')
                        ->label('서류명')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('document_number')
                        ->label('문서번호 / CO 번호')
                        ->maxLength(120),
                    TextInput::make('version')
                        ->label('버전')
                        ->default('1.0')
                        ->required()
                        ->maxLength(40),
                    Select::make('status')
                        ->label('검토 상태')
                        ->options(ProjectContractDocument::STATUS_OPTIONS)
                        ->default('draft')
                        ->required(),
                    FileUpload::make('file_path')
                        ->label('파일 업로드')
                        ->disk((string) config('document-intelligence.disk', 'local'))
                        ->directory('project-contract-documents')
                        ->visibility('private')
                        ->acceptedFileTypes([
                            'application/pdf',
                            'application/msword',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'image/jpeg',
                            'image/png',
                            'image/webp',
                        ])
                        ->maxSize(30720)
                        ->storeFileNamesIn('original_file_name')
                        ->previewable(false)
                        ->required(fn (?ProjectContractDocument $record): bool => $record === null)
                        ->helperText('PDF, Word, Excel, JPG/PNG/WebP · 최대 30MB')
                        ->columnSpanFull(),
                    DatePicker::make('issued_on')->label('발행일'),
                    DatePicker::make('effective_on')->label('효력일'),
                    DatePicker::make('expires_on')
                        ->label('만료일')
                        ->helperText('COI·Bond·라이선스처럼 만료되는 서류에 반드시 입력하세요.'),
                    Toggle::make('is_required')->label('필수 서류'),
                    Toggle::make('is_current')->label('현재 유효 버전')->default(true),
                    Toggle::make('is_confidential')->label('기밀 서류')->default(true),
                    Textarea::make('notes')
                        ->label('검토 메모')
                        ->rows(4)
                        ->columnSpanFull(),
                    Hidden::make('disk')->default(fn (): string => (string) config('document-intelligence.disk', 'local')),
                    Hidden::make('original_file_name'),
                    Hidden::make('uploaded_by')->default(fn (): ?int => auth()->id()),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('internal_reference')
                    ->label('사내 계약번호')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('contract_number')
                    ->label('원청 계약번호')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('title')
                    ->label('계약명')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('counterparty.name')
                    ->label('원청사')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('project.project_code')
                    ->label('PROJECT')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('site.code')
                    ->label('현장')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('상태')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ProjectContract::STATUS_OPTIONS[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        'active' => 'success',
                        'under_review' => 'warning',
                        'expired', 'terminated' => 'danger',
                        'suspended' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('risk_level')
                    ->label('리스크')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'critical', 'high' => 'danger',
                        'medium' => 'warning',
                        default => 'success',
                    })
                    ->sortable(),
                TextColumn::make('current_amount')
                    ->label('현재 계약금액')
                    ->formatStateUsing(fn (mixed $state, ProjectContract $record): string => $state === null
                        ? '-'
                        : $record->currency.' '.number_format((float) $state, 2))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('ends_on')
                    ->label('계약 종료일')
                    ->date()
                    ->color(fn (?ProjectContract $record): string => match (true) {
                        ! $record?->ends_on => 'gray',
                        $record->ends_on->isPast() => 'danger',
                        $record->ends_on->lte(today()->addDays(60)) => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('documents_count')
                    ->label('서류')
                    ->counts('documents')
                    ->badge()
                    ->sortable(),
                TextColumn::make('expiring_documents_count')
                    ->label('30일 내 만료')
                    ->counts('expiringDocuments')
                    ->badge()
                    ->color(fn (mixed $state): string => (int) $state > 0 ? 'danger' : 'gray')
                    ->sortable(),
                TextColumn::make('next_action_on')
                    ->label('다음 조치일')
                    ->date()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('계약 상태')
                    ->options(ProjectContract::STATUS_OPTIONS),
                SelectFilter::make('risk_level')
                    ->label('리스크')
                    ->options(ProjectContract::RISK_OPTIONS),
                SelectFilter::make('counterparty_company_id')
                    ->label('원청사')
                    ->options(fn (): array => self::companyOptions()),
                SelectFilter::make('site_id')
                    ->label('현장')
                    ->options(fn (): array => self::siteOptions()),
            ])
            ->recordActions([
                Action::make('documents')
                    ->label('계약서류')
                    ->icon('heroicon-o-folder-open')
                    ->color('info')
                    ->url(fn (ProjectContract $record): string => static::getUrl('documents', ['record' => $record])),
                EditAction::make()
                    ->label('계약 수정')
                    ->mutateDataUsing(fn (array $data): array => self::enforceWritableScope($data))
                    ->visible(fn (): bool => self::canManageDocuments()),
                DeleteAction::make()
                    ->visible(fn (): bool => self::canDeleteDocuments()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => self::canDeleteDocuments()),
                ]),
            ])
            ->recordUrl(fn (ProjectContract $record): string => static::getUrl('documents', ['record' => $record]));
    }

    public static function canManageDocuments(): bool
    {
        return static::canCreate();
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();

        if (! $user || ! static::currentUserHasRole(['super_admin', 'admin', 'site_manager'])) {
            return false;
        }

        if (in_array($user->access_role, ['super_admin', 'admin'], true) || $user->access_scope === 'all_sites') {
            return true;
        }

        return match ($user->access_scope) {
            'company' => filled($user->allowed_company_id),
            'site' => filled($user->allowed_site_id),
            default => false,
        };
    }

    public static function enforceWritableScope(array $data): array
    {
        $user = auth()->user();

        if (! $user || ! static::canCreate()) {
            throw ValidationException::withMessages([
                'company_id' => '현재 계정에는 계약을 저장할 수 있는 관리 범위가 없습니다.',
            ]);
        }

        if (in_array($user->access_role, ['super_admin', 'admin'], true) || $user->access_scope === 'all_sites') {
            return $data;
        }

        if ($user->access_scope === 'company') {
            $data['company_id'] = $user->allowed_company_id;

            if (filled($data['site_id'] ?? null) && ! Site::query()
                ->whereKey($data['site_id'])
                ->where('company_id', $user->allowed_company_id)
                ->exists()) {
                throw ValidationException::withMessages(['site_id' => '허용된 회사의 현장만 선택할 수 있습니다.']);
            }

            if (filled($data['project_id'] ?? null) && ! Project::query()
                ->whereKey($data['project_id'])
                ->where('company_id', $user->allowed_company_id)
                ->exists()) {
                throw ValidationException::withMessages(['project_id' => '허용된 회사의 PROJECT만 선택할 수 있습니다.']);
            }

            return $data;
        }

        if ($user->access_scope === 'site') {
            $site = Site::query()->find($user->allowed_site_id);

            if (! $site) {
                throw ValidationException::withMessages(['site_id' => '허용된 현장을 찾을 수 없습니다.']);
            }

            if (filled($data['project_id'] ?? null) && ! Project::query()
                ->whereKey($data['project_id'])
                ->where('site_id', $site->id)
                ->exists()) {
                throw ValidationException::withMessages(['project_id' => '허용된 현장의 PROJECT만 선택할 수 있습니다.']);
            }

            $data['site_id'] = $site->id;
            $data['company_id'] = $site->company_id;

            return $data;
        }

        throw ValidationException::withMessages([
            'company_id' => '현재 계정에는 계약을 저장할 수 있는 관리 범위가 없습니다.',
        ]);
    }

    public static function canDeleteDocuments(): bool
    {
        return static::currentUserHasRole(['super_admin', 'admin']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageProjectContracts::route('/'),
            'documents' => ManageProjectContractDocuments::route('/{record}/documents'),
        ];
    }

    private static function companyOptions(): array
    {
        return Company::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    private static function siteOptions(): array
    {
        $query = Site::query()->orderBy('code');
        $user = auth()->user();

        if ($user && ! in_array($user->access_role, ['super_admin', 'admin'], true) && $user->access_scope !== 'all_sites') {
            match ($user->access_scope) {
                'company' => $query->where('company_id', $user->allowed_company_id),
                'site' => $query->whereKey($user->allowed_site_id),
                default => $query->whereRaw('1 = 0'),
            };
        }

        return $query->get()->mapWithKeys(fn (Site $site): array => [
            $site->id => $site->code.' - '.$site->name,
        ])->all();
    }

    private static function projectOptions(): array
    {
        $query = Project::query()->orderBy('project_code');
        $user = auth()->user();

        if ($user && ! in_array($user->access_role, ['super_admin', 'admin'], true) && $user->access_scope !== 'all_sites') {
            match ($user->access_scope) {
                'company' => $query->where('company_id', $user->allowed_company_id),
                'site' => $query->where('site_id', $user->allowed_site_id),
                default => $query->whereRaw('1 = 0'),
            };
        }

        return $query->get()->mapWithKeys(fn (Project $project): array => [
            $project->id => $project->project_code.' - '.$project->name,
        ])->all();
    }

    private static function employeeOptions(): array
    {
        return Employee::query()->orderBy('name')->get()->mapWithKeys(fn (Employee $employee): array => [
            $employee->id => $employee->name.' ('.$employee->employee_number.')',
        ])->all();
    }

    private static function companySelect(string $field, string $label, bool $operatingCompany = false): Select
    {
        $select = Select::make($field)
            ->label($label)
            ->options(fn (): array => $operatingCompany ? self::operatingCompanyOptions() : self::companyOptions())
            ->searchable()
            ->preload();

        if ($operatingCompany) {
            return $select;
        }

        return $select->createOptionForm([
            TextInput::make('name')->label('회사명')->required()->maxLength(255),
            TextInput::make('code')->label('회사 코드')->helperText('비워두면 회사명 기준으로 자동 생성됩니다.')->maxLength(40),
            TextInput::make('legal_name')->label('법인명')->maxLength(255),
            Select::make('status')
                ->label('상태')
                ->options(['active' => 'Active', 'inactive' => 'Inactive'])
                ->default('active')
                ->required(),
        ])
            ->createOptionUsing(fn (array $data): int => Company::query()->create([
                'code' => self::uniqueCompanyCode((string) ($data['code'] ?? ''), (string) $data['name']),
                'name' => trim((string) $data['name']),
                'legal_name' => filled($data['legal_name'] ?? null) ? trim((string) $data['legal_name']) : null,
                'status' => (string) ($data['status'] ?? 'active'),
            ])->getKey())
            ->createOptionAction(fn ($action) => $action
                ->label('새 회사 추가')
                ->modalHeading('새 계약 회사 등록')
                ->modalSubmitActionLabel('등록'));
    }

    private static function operatingCompanyOptions(): array
    {
        $user = auth()->user();

        if (! $user || in_array($user->access_role, ['super_admin', 'admin'], true) || $user->access_scope === 'all_sites') {
            return self::companyOptions();
        }

        $companyId = match ($user->access_scope) {
            'company' => $user->allowed_company_id,
            'site' => Site::query()->whereKey($user->allowed_site_id)->value('company_id'),
            default => null,
        };

        return filled($companyId)
            ? Company::query()->whereKey($companyId)->pluck('name', 'id')->all()
            : [];
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
            $candidate = substr($base, 0, 35).'-'.$sequence;
            $sequence++;
        }

        return $candidate;
    }
}
