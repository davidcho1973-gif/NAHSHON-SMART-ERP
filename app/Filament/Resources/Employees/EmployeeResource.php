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
            TextInput::make('employee_number')
                ->label('Employee ID')
                ->unique(ignoreRecord: true)
                ->placeholder('Auto-generated if blank')
                ->helperText('Leave blank to let the ERP create an Employee ID automatically.')
                ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state))
                ->maxLength(80),
            TextInput::make('badge_number')
                ->label('NFC ID')
                ->helperText('Use the ERP NFC ID format, such as N-842853E04. AI OCR does not fill this field.')
                ->unique(ignoreRecord: true)
                ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state))
                ->maxLength(80),
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
                ->helperText('Take a photo on mobile or upload a badge image. Gemini 3.5 Flash analyzes it after upload.')
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
                    ->label('Analyze badge photo')
                    ->icon('heroicon-o-sparkles')
                    ->color('info')
                    ->action(fn (Set $set, Get $get): null => self::analyzeBadgePhoto($get('badge_photo_path'), $set, $get)),
            ])->columnSpanFull(),
            TextInput::make('badge_printed_number')
                ->label('Badge printed number')
                ->helperText('OCR reference only. This is not used as the NFC ID.')
                ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state))
                ->maxLength(120),
            TextInput::make('first_name')
                ->label('First name')
                ->maxLength(120)
                ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state))
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Set $set, Get $get): null => self::syncFullName($set, $get)),
            TextInput::make('last_name')
                ->label('Last name')
                ->maxLength(120)
                ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state))
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Set $set, Get $get): null => self::syncFullName($set, $get)),
            TextInput::make('name')
                ->label('Full name')
                ->helperText('Leave blank if first and last name are filled; the ERP will combine them.')
                ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state))
                ->maxLength(255),
            TextInput::make('email')
                ->email()
                ->unique(ignoreRecord: true)
                ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state, lower: true))
                ->maxLength(255),
            TextInput::make('badge_company_name')
                ->label('Badge company name')
                ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state))
                ->maxLength(255),
            DatePicker::make('badge_issued_on')
                ->label('Badge issued on'),
            Select::make('company_id')
                ->label('Company')
                ->options(fn (): array => Company::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable(),
            Select::make('site_id')
                ->label('Site')
                ->options(fn (): array => Site::query()->orderBy('code')->pluck('code', 'id')->all())
                ->searchable(),
            Select::make('team_id')
                ->label('Team')
                ->options(fn (): array => Team::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable(),
            TextInput::make('role')
                ->label('Role / Trade')
                ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state))
                ->maxLength(120),
            TextInput::make('nationality')
                ->dehydrateStateUsing(fn (mixed $state): ?string => self::nullableText($state))
                ->maxLength(80),
            DatePicker::make('start_date')
                ->label('Hire date / 입사일'),
            Select::make('employment_status')
                ->label('Status')
                ->options([
                    'active' => 'Active',
                    'pending' => 'Pending',
                    'on_leave' => 'On leave',
                    'inactive' => 'Inactive',
                    'terminated' => 'Terminated',
                ])
                ->default('active')
                ->required(),
            Select::make('attendance_app_role')
                ->label('QR attendance role')
                ->options([
                    'worker' => 'Worker - self attendance only',
                    'foreman' => 'Foreman / Team lead',
                    'safety_manager' => 'Safety manager',
                    'attendance_admin' => 'Attendance admin',
                ])
                ->default('worker')
                ->required(),
            Select::make('attendance_app_scope')
                ->label('QR attendance scope')
                ->options([
                    'self' => 'Self only',
                    'team' => 'Assigned team',
                    'site' => 'Assigned site',
                    'all_sites' => 'All sites',
                ])
                ->default('self')
                ->required(),
            DatePicker::make('visa_expires_on')
                ->label('Visa expires'),
            DatePicker::make('safety_training_expires_on')
                ->label('Safety training expires'),
            KeyValue::make('payload')
                ->label('Extra data')
                ->keyLabel('Field')
                ->valueLabel('Value')
                ->columnSpanFull(),
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
                    ->modalDescription('이 직원에게 로그인 계정을 만들고 관리자 또는 작업자 권한을 부여합니다. 로그인은 직원 이메일(구글)로 이뤄집니다.')
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

                        Notification::make()->success()->title('로그인 계정 부여 완료')
                            ->body($record->email . ' · ' . (User::ROLE_OPTIONS[$role] ?? $role))->send();
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
