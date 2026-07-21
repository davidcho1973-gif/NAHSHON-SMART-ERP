<?php

namespace App\Filament\Resources\Employees;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\Employees\Pages\ManageEmployees;
use App\Filament\Resources\UserAccesses\UserAccessResource;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Services\GeminiBadgeAnalyzer;
use App\Services\Hr\AccessAccountProvisioner;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class EmployeeResource extends Resource
{
    use AuthorizesResourceAccess;

    protected static function accessViewRoles(): array
    {
        return ['super_admin', 'admin', 'hr_manager', 'site_manager', 'payroll'];
    }

    protected static function accessManageRoles(): array
    {
        return ['super_admin', 'admin', 'hr_manager'];
    }

    protected static function accessScopeColumns(): array
    {
        return ['company' => 'company_id', 'site' => 'site_id', 'team' => 'team_id', 'self' => 'id'];
    }

    protected static ?string $model = Employee::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationLabel = 'Employees';

    protected static ?string $modelLabel = 'Employee';

    protected static ?string $pluralModelLabel = 'Employees';

    protected static string | \UnitEnum | null $navigationGroup = 'HUMAN RESOURCE';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('① 직원 기본정보')
                ->description('사번은 비워두면 자동 생성됩니다. 성과 이름을 입력하면 전체 이름도 자동으로 조합됩니다.')
                ->icon('heroicon-o-user')
                ->columns(['default' => 1, 'lg' => 2])
                ->schema([
                    TextInput::make('employee_number')
                        ->label('Employee ID / 사번')
                        ->unique(ignoreRecord: true)
                        ->placeholder('비워두면 자동 생성')
                        ->helperText('ERP가 중복되지 않는 사번을 자동 생성합니다.')
                        ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state))
                        ->maxLength(80),
                    TextInput::make('email')
                        ->label('구글 이메일')
                        ->email()
                        ->unique(ignoreRecord: true)
                        ->helperText('로그인 계정을 부여할 직원은 실제 구글 이메일을 정확히 입력하세요.')
                        ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state, lower: true))
                        ->maxLength(255),
                    TextInput::make('first_name')
                        ->label('First name / 이름')
                        ->maxLength(120)
                        ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state))
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, Get $get): null => self::syncFullName($set, $get)),
                    TextInput::make('last_name')
                        ->label('Last name / 성')
                        ->maxLength(120)
                        ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state))
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, Get $get): null => self::syncFullName($set, $get)),
                    TextInput::make('name')
                        ->label('Full name / 전체 이름')
                        ->helperText('성과 이름을 입력했다면 비워두어도 됩니다.')
                        ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state))
                        ->maxLength(255),
                    TextInput::make('nationality')
                        ->label('Nationality / 국적')
                        ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state))
                        ->maxLength(80),
                    DatePicker::make('start_date')
                        ->label('Hire date / 입사일'),
                    Select::make('employment_status')
                        ->label('재직 상태')
                        ->options([
                            'active' => 'Active / 재직',
                            'pending' => 'Pending / 대기',
                            'on_leave' => 'On leave / 휴직',
                            'inactive' => 'Inactive / 비활성',
                            'terminated' => 'Terminated / 퇴사',
                        ])
                        ->default('active')
                        ->required(),
                ]),

            Section::make('② 소속 및 업무 배정')
                ->description('회사 → 현장 → 팀 순서로 지정하고, 실제 담당 직종을 입력합니다.')
                ->icon('heroicon-o-building-office-2')
                ->columns(['default' => 1, 'lg' => 2])
                ->schema([
                    Select::make('company_id')
                        ->label('Company / 회사')
                        ->options(fn (): array => Company::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable(),
                    Select::make('site_id')
                        ->label('Site / 현장')
                        ->options(fn (): array => Site::query()->orderBy('code')->pluck('code', 'id')->all())
                        ->searchable(),
                    Select::make('team_id')
                        ->label('Team / 팀')
                        ->options(fn (): array => Team::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable(),
                    TextInput::make('role')
                        ->label('Role / Trade / 직종')
                        ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state))
                        ->maxLength(120),
                ]),

            Section::make('③ Badge · NFC')
                ->description('뱃지 사진을 먼저 올리면 AI가 이름·회사·직종·발급일을 판독합니다. NFC ID는 직접 확인해 입력합니다.')
                ->icon('heroicon-o-identification')
                ->columns(['default' => 1, 'lg' => 2])
                ->collapsible()
                ->schema([
                    TextInput::make('badge_number')
                        ->label('NFC ID')
                        ->helperText('예: N-842853E04. AI OCR은 이 값을 자동 입력하지 않습니다.')
                        ->unique(ignoreRecord: true)
                        ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state))
                        ->maxLength(80),
                    TextInput::make('badge_printed_number')
                        ->label('Badge printed number')
                        ->helperText('뱃지 인쇄번호 참고값이며 NFC ID와 다릅니다.')
                        ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state))
                        ->maxLength(120),
                    TextInput::make('badge_company_name')
                        ->label('Badge company name')
                        ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state))
                        ->maxLength(255),
                    DatePicker::make('badge_issued_on')
                        ->label('Badge issued on'),
                    FileUpload::make('badge_photo_path')
                        ->label('Badge photo / camera')
                        ->disk('public')
                        ->directory('employee-badges')
                        ->visibility('public')
                        ->image()
                        ->imagePreviewHeight('180')
                        ->maxSize(10240)
                        ->openable()
                        ->downloadable()
                        ->helperText('모바일 카메라로 촬영하거나 뱃지 이미지를 업로드하세요.')
                        ->extraInputAttributes(['accept' => 'image/*', 'capture' => 'environment'], merge: true)
                        ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                            if (! $state instanceof TemporaryUploadedFile) {
                                return;
                            }

                            self::analyzeBadgePhoto($state, $set, $get);
                        })
                        ->columnSpanFull(),
                    Actions::make([
                        Action::make('analyzeBadgePhoto')
                            ->label('뱃지 사진 AI 분석')
                            ->icon('heroicon-o-sparkles')
                            ->color('info')
                            ->action(fn (Set $set, Get $get): null => self::analyzeBadgePhoto($get('badge_photo_path'), $set, $get)),
                    ])->columnSpanFull(),
                    Hidden::make('badge_analysis_model'),
                    Hidden::make('badge_analyzed_at'),
                    Hidden::make('badge_analysis_payload')
                        ->formatStateUsing(fn (?Employee $record): ?string => self::encodeBadgeAnalysisPayload($record?->badge_analysis_payload))
                        ->dehydrateStateUsing(fn (mixed $state): ?array => self::normalizeBadgeAnalysisPayload($state)),
                    Textarea::make('badge_analysis_preview')
                        ->label('Gemini badge analysis')
                        ->formatStateUsing(fn (Get $get, ?Employee $record): ?string => self::formatBadgeAnalysisPayload($get('badge_analysis_payload') ?? $record?->badge_analysis_payload))
                        ->disabled()
                        ->dehydrated(false)
                        ->rows(10)
                        ->visible(fn (Get $get, ?Employee $record): bool => filled($get('badge_analysis_preview')) || filled($record?->badge_analysis_payload))
                        ->columnSpanFull(),
                ]),

            Section::make('④ 출퇴근 앱 권한')
                ->description('일반 작업자는 Worker + Self를 사용하고, 반장·현장 관리자만 담당 범위를 넓혀 주세요.')
                ->icon('heroicon-o-qr-code')
                ->columns(['default' => 1, 'lg' => 2])
                ->schema([
                    Select::make('attendance_app_role')
                        ->label('QR attendance role')
                        ->options([
                            'worker' => 'Worker - 본인 출퇴근만',
                            'foreman' => 'Foreman / Team lead',
                            'safety_manager' => 'Safety manager',
                            'attendance_admin' => 'Attendance admin',
                        ])
                        ->default('worker')
                        ->required(),
                    Select::make('attendance_app_scope')
                        ->label('QR attendance scope')
                        ->options([
                            'self' => 'Self only / 본인',
                            'team' => 'Assigned team / 담당 팀',
                            'site' => 'Assigned site / 담당 현장',
                            'all_sites' => 'All sites / 전체 현장',
                        ])
                        ->default('self')
                        ->required(),
                ]),

            Section::make('⑤ 만료일 · 추가정보')
                ->description('비자·안전교육 만료일과 시스템 확장값이 필요할 때만 입력합니다.')
                ->icon('heroicon-o-document-text')
                ->columns(['default' => 1, 'lg' => 2])
                ->collapsible()
                ->collapsed()
                ->schema([
                    DatePicker::make('visa_expires_on')
                        ->label('Visa expires'),
                    DatePicker::make('safety_training_expires_on')
                        ->label('Safety training expires'),
                    KeyValue::make('payload')
                        ->label('Extra data')
                        ->keyLabel('Field')
                        ->valueLabel('Value')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('badge_photo_path')
                    ->label('Badge')
                    ->disk('public')
                    ->height(40)
                    ->square()
                    ->toggleable(),
                TextColumn::make('employee_number')->label('Employee ID')->searchable()->sortable(),
                TextColumn::make('name')->label('Full name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->toggleable(),
                TextColumn::make('company.name')->label('Company')->searchable()->toggleable(),
                TextColumn::make('site.code')->label('Site')->badge()->sortable(),
                TextColumn::make('team.name')->label('Team')->searchable()->toggleable(),
                TextColumn::make('role')->label('Role')->searchable()->toggleable(),
                TextColumn::make('attendance_app_role')->label('QR role')->badge()->sortable()->toggleable(),
                TextColumn::make('start_date')->label('Hire date')->date()->sortable()->toggleable(),
                TextColumn::make('employment_status')->label('Status')->badge()->sortable(),
                TextColumn::make('user.access_role')
                    ->label('로그인 권한')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? (User::ROLE_OPTIONS[$state] ?? $state) : '계정 없음')
                    ->color(fn (?string $state): string => $state === null
                        ? 'gray'
                        : (in_array($state, User::ADMIN_PANEL_ROLES, true) ? 'success' : 'info'))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('badge_number')->label('NFC ID')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('badge_printed_number')->label('Badge printed')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('badge_company_name')->label('Badge company')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('badge_issued_on')->label('Badge issued')->date()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('visa_expires_on')->date()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('safety_training_expires_on')->date()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('employment_status')
                    ->label('Status')
                    ->options([
                        'active' => 'Active',
                        'pending' => 'Pending',
                        'on_leave' => 'On leave',
                        'inactive' => 'Inactive',
                        'terminated' => 'Terminated',
                    ]),
                SelectFilter::make('company_id')
                    ->label('Company')
                    ->options(fn (): array => Company::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('site_id')
                    ->label('Site')
                    ->options(fn (): array => Site::query()->orderBy('code')->pluck('code', 'id')->all()),
            ])
            ->recordActions([
                Action::make('grantAccount')
                    ->label('로그인 계정')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->modalHeading('로그인 계정 부여 / 권한 설정')
                    ->modalDescription(fn (Employee $record): string => $record->email
                        ? '로그인 이메일: ' . $record->email . ' — 이 구글 계정으로 로그인합니다. (틀리면 먼저 [수정]에서 이메일을 고치세요)'
                        : '⚠ 이 직원은 이메일이 없습니다. 먼저 [수정]에서 구글 이메일을 입력하세요.')
                    ->modalSubmitActionLabel('계정 부여')
                    ->visible(fn (): bool => in_array(auth()->user()?->access_role, ['super_admin', 'admin', 'hr_manager'], true))
                    ->fillForm(fn (Employee $record): array => [
                        'account_type' => ($record->user && ! in_array($record->user->access_role, ['worker', null], true)) ? 'admin' : 'worker',
                        'admin_role' => ($record->user && $record->user->access_role !== 'worker') ? $record->user->access_role : null,
                        'access_scope' => $record->user?->access_scope ?? 'self',
                    ])
                    ->form([
                        Select::make('account_type')
                            ->label('계정 유형')
                            ->options([
                                'worker' => '작업자 — 현장 출퇴근 앱 (/attendance-app)',
                                'admin' => '관리자 — 관리 패널 (/admin)',
                            ])
                            ->default('worker')
                            ->required()
                            ->live(),
                        Select::make('admin_role')
                            ->label('관리자 역할')
                            ->options(fn (): array => array_diff_key(UserAccessResource::assignableRoles(), ['worker' => '']))
                            ->visible(fn (Get $get): bool => $get('account_type') === 'admin')
                            ->required(fn (Get $get): bool => $get('account_type') === 'admin')
                            ->helperText('관리자(admin) 이상 권한은 슈퍼관리자만 부여할 수 있습니다.'),
                        Select::make('access_scope')
                            ->label('데이터 범위 (Scope)')
                            ->options(User::SCOPE_OPTIONS)
                            ->default('self')
                            ->required()
                            ->helperText('작업자는 보통 Self, 관리자는 담당 현장/회사/전체 범위를 선택하세요.'),
                    ])
                    ->action(function (Employee $record, array $data): void {
                        $role = $data['account_type'] === 'admin' ? ($data['admin_role'] ?? null) : 'worker';

                        if (! $role || ! array_key_exists($role, UserAccessResource::assignableRoles())) {
                            Notification::make()->danger()->title('권한 부족')
                                ->body('해당 역할을 부여할 권한이 없습니다.')->send();

                            return;
                        }

                        try {
                            app(AccessAccountProvisioner::class)->grant($record, $role, $data['access_scope'] ?? 'self');
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('계정 생성 실패')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->persistent()->title('로그인 계정 부여 완료')
                            ->body('안내: ' . $record->email . ' 구글 계정으로 ' . route('login')
                                . ' 에서 로그인하면 됩니다. (권한: ' . (User::ROLE_OPTIONS[$role] ?? $role) . ')')
                            ->send();
                    }),
                Action::make('badgeQr')
                    ->label('Badge QR')
                    ->icon('heroicon-o-qr-code')
                    ->url(fn (Employee $record): string => route('attendance-app.employee.badge-qr', ['employee' => $record]))
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
            'index' => ManageEmployees::route('/'),
        ];
    }

    private static function analyzeBadgePhoto(mixed $state, Set $set, Get $get): null
    {
        $file = self::resolveBadgePhoto($state);

        if ($file === null) {
            Notification::make()
                ->warning()
                ->title('Badge photo required')
                ->body('Take a badge photo or upload an image first.')
                ->send();

            return null;
        }

        try {
            $analysis = app(GeminiBadgeAnalyzer::class)->analyze($file['path'], $file['mime_type']);
            self::applyBadgeAnalysis($analysis, $set, $get);

            Notification::make()
                ->success()
                ->title('Badge analysis complete')
                ->body('Company, name, role, issue date, and badge fields were filled from the photo.')
                ->send();
        } catch (\Throwable $exception) {
            Notification::make()
                ->warning()
                ->title('Badge analysis skipped')
                ->body($exception->getMessage())
                ->send();
        }

        return null;
    }

    /**
     * @return array{path: string, mime_type: string}|null
     */
    private static function resolveBadgePhoto(mixed $state): ?array
    {
        if (is_array($state)) {
            $state = Arr::first($state);
        }

        if ($state instanceof TemporaryUploadedFile) {
            return [
                'path' => $state->getRealPath(),
                'mime_type' => $state->getMimeType() ?: 'image/jpeg',
            ];
        }

        if (is_string($state) && $state !== '' && Storage::disk('public')->exists($state)) {
            $path = Storage::disk('public')->path($state);

            return [
                'path' => $path,
                'mime_type' => mime_content_type($path) ?: 'image/jpeg',
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private static function applyBadgeAnalysis(array $analysis, Set $set, Get $get): void
    {
        self::setIfFilled($set, 'first_name', $analysis['first_name'] ?? null);
        self::setIfFilled($set, 'last_name', $analysis['last_name'] ?? null);
        self::setIfFilled($set, 'name', $analysis['full_name'] ?? null);
        self::setIfFilled($set, 'role', $analysis['role'] ?? null);
        self::setIfFilled($set, 'badge_company_name', $analysis['company_name'] ?? null);
        self::setIfFilled($set, 'badge_issued_on', $analysis['issued_on'] ?? null);
        self::setIfFilled($set, 'badge_printed_number', $analysis['printed_badge_number'] ?? null);

        if ($companyId = self::findCompanyId($analysis['company_name'] ?? null)) {
            $set('company_id', $companyId);
        }

        $set('badge_analysis_model', $analysis['model'] ?? config('services.gemini.model', 'gemini-3.5-flash'));
        $set('badge_analyzed_at', Carbon::now()->toDateTimeString());
        $payload = self::badgeAnalysisPayload($analysis);

        $set('badge_analysis_payload', self::encodeBadgeAnalysisPayload($payload));
        $set('badge_analysis_preview', self::formatBadgeAnalysisPayload($payload));

        self::syncFullName($set, $get);
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    private static function badgeAnalysisPayload(array $analysis): array
    {
        return Arr::except($analysis, ['raw']) + [
            'raw_json' => json_encode($analysis['raw'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        ];
    }

    private static function encodeBadgeAnalysisPayload(mixed $payload): ?string
    {
        if (blank($payload)) {
            return null;
        }

        if (is_string($payload)) {
            return $payload;
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function normalizeBadgeAnalysisPayload(mixed $payload): ?array
    {
        if (blank($payload)) {
            return null;
        }

        if (is_array($payload)) {
            return $payload;
        }

        if (is_string($payload)) {
            $decoded = json_decode($payload, true);

            return is_array($decoded) ? $decoded : ['raw' => $payload];
        }

        return ['value' => $payload];
    }

    private static function formatBadgeAnalysisPayload(mixed $payload): ?string
    {
        $payload = self::normalizeBadgeAnalysisPayload($payload);

        if ($payload === null) {
            return null;
        }

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
    }

    private static function setIfFilled(Set $set, string $field, mixed $value): void
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if (blank($value)) {
            return;
        }

        $set($field, $value);
    }

    private static function nullableText(mixed $state, bool $lower = false): ?string
    {
        if (! is_string($state)) {
            return null;
        }

        $state = trim($state);

        if ($state === '') {
            return null;
        }

        return $lower ? Str::lower($state) : $state;
    }

    private static function syncFullName(Set $set, Get $get): null
    {
        $firstName = trim((string) $get('first_name'));
        $lastName = trim((string) $get('last_name'));
        $fullName = trim(implode(' ', array_filter([$firstName, $lastName])));

        if ($fullName !== '') {
            $set('name', $fullName);
        }

        return null;
    }

    private static function findCompanyId(mixed $companyName): ?int
    {
        if (! is_string($companyName) || trim($companyName) === '') {
            return null;
        }

        $normalized = Str::lower(trim($companyName));

        return Company::query()
            ->where(fn ($query) => $query
                ->whereRaw('lower(name) = ?', [$normalized])
                ->orWhereRaw('lower(code) = ?', [$normalized]))
            ->value('id');
    }
}
