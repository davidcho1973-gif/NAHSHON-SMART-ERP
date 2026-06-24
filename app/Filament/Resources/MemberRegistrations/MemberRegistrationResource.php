<?php

namespace App\Filament\Resources\MemberRegistrations;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\MemberRegistrations\Pages\ManageMemberRegistrations;
use App\Models\Company;
use App\Models\MemberRegistration;
use App\Models\Site;
use App\Models\Team;
use App\Services\ApplicantInvitationService;
use App\Services\GeminiBadgeAnalyzer;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
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
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class MemberRegistrationResource extends Resource
{
    use AuthorizesResourceAccess;

    protected static function accessViewRoles(): array
    {
        return ['super_admin', 'admin', 'hr_manager', 'site_manager'];
    }

    protected static function accessManageRoles(): array
    {
        return ['super_admin', 'admin', 'hr_manager'];
    }

    protected static ?string $model = MemberRegistration::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-user-plus';

    protected static ?string $navigationLabel = '입사지원서 (Applicants)';

    protected static ?string $modelLabel = 'Applicant';

    protected static ?string $pluralModelLabel = 'Applicants';

    protected static string | \UnitEnum | null $navigationGroup = 'HUMAN RESOURCE';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('지원 현황')
                ->description('지원자 코드와 현재 온보딩 단계를 한눈에 확인합니다.')
                ->columns(3)
                ->schema([
                    TextInput::make('applicant_code')
                        ->label('Applicant code / 지원자 코드')
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('Created only after the applicant submits the public application.'),
                    Select::make('onboarding_status')
                        ->label('Onboarding status / 진행 상태')
                        ->options(MemberRegistration::onboardingStatusOptions())
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('우측 액션 버튼(제출·인터뷰·안전교육·뱃지/NFC·활성화)으로만 변경됩니다.'),
                    TextInput::make('employee_number')
                        ->label('Employee ID')
                        ->placeholder('Not synced')
                        ->disabled()
                        ->dehydrated(false)
                        ->maxLength(80),
                ]),

            Section::make('① 지원자 제출 정보')
                ->description('지원자가 공개 지원서에서 직접 제출한 값입니다. 여기서는 수정할 수 없습니다.')
                ->collapsible()
                ->collapsed()
                ->columns(2)
                ->schema([
                    Select::make('preferred_language')
                        ->label('Application language')
                        ->options(MemberRegistration::languageOptions())
                        ->disabled()
                        ->dehydrated(false),
                    Select::make('member_type')
                        ->label('Member type')
                        ->options([
                            'worker' => 'Worker',
                            'staff' => 'Staff',
                            'vendor' => 'Vendor',
                            'visitor' => 'Visitor',
                            'driver' => 'Driver',
                        ])
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('last_name')->label('Last name / 성')->disabled()->dehydrated(false)->maxLength(120),
                    TextInput::make('first_name')->label('First name / 이름')->disabled()->dehydrated(false)->maxLength(120),
                    TextInput::make('full_name')->label('Full name')->disabled()->dehydrated(false)->maxLength(255),
                    TextInput::make('preferred_name')->label('Preferred name')->disabled()->dehydrated(false)->maxLength(255),
                    TextInput::make('email')->label('Email')->email()->disabled()->dehydrated(false)->maxLength(255),
                    TextInput::make('phone')->label('Phone')->tel()->disabled()->dehydrated(false)->maxLength(80),
                    DatePicker::make('date_of_birth')->label('Date of birth')->disabled()->dehydrated(false),
                    TextInput::make('nationality')->label('Nationality')->disabled()->dehydrated(false)->maxLength(80),
                    TextInput::make('address')
                        ->label('Address')
                        ->disabled()
                        ->dehydrated(false)
                        ->maxLength(255)
                        ->columnSpanFull(),
                    TextInput::make('emergency_contact_name')->label('Emergency contact')->disabled()->dehydrated(false)->maxLength(255),
                    TextInput::make('emergency_contact_phone')->label('Emergency phone')->tel()->disabled()->dehydrated(false)->maxLength(80),
                    Select::make('role')
                        ->label('Position')
                        ->options(MemberRegistration::roleOptions())
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('trade')->label('Trade')->disabled()->dehydrated(false)->maxLength(120),
                    DatePicker::make('start_date')->label('Desired start date')->disabled()->dehydrated(false),
                    DatePicker::make('end_date')->label('End date')->disabled()->dehydrated(false),
                    TextInput::make('visa_type')->label('Visa type')->disabled()->dehydrated(false)->maxLength(60),
                    DatePicker::make('visa_expires_on')->label('Visa expires')->disabled()->dehydrated(false),
                ]),

            Section::make('② HR 서류 검증')
                ->description('제출 서류의 신원·문서 상태를 HR이 직접 판단합니다. 업로드 원본은 Member Documents에서 확인하세요.')
                ->columns(2)
                ->schema([
                    Select::make('identity_status')
                        ->label('Identity status')
                        ->options([
                            'pending' => 'Pending',
                            'verified' => 'Verified',
                            'needs_review' => 'Needs review',
                        ])
                        ->default('pending')
                        ->required(),
                    Select::make('document_status')
                        ->label('Document status')
                        ->options([
                            'missing' => 'Missing',
                            'pending' => 'Pending',
                            'verified' => 'Verified',
                            'expired' => 'Expired',
                        ])
                        ->default('missing')
                        ->required(),
                ]),

            Section::make('③ 현장 배정')
                ->description('직원 활성화 시 생성될 Employee에 적용되는 회사·현장·팀 배정값입니다.')
                ->columns(3)
                ->schema([
                    Select::make('company_id')
                        ->label('Company')
                        ->options(fn (): array => Company::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->helperText('Admin assignment for the Employee that will be created at activation.'),
                    Select::make('site_id')
                        ->label('Site')
                        ->options(fn (): array => Site::query()->orderBy('code')->pluck('code', 'id')->all())
                        ->searchable(),
                    Select::make('team_id')
                        ->label('Team')
                        ->options(fn (): array => Team::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable(),
                ]),

            Section::make('④ 온보딩 진행 상태')
                ->description('아래 값은 우측 액션 버튼으로만 변경됩니다(가드 검증 통과). 여기서는 표시 전용입니다.')
                ->columns(2)
                ->schema([
                    Select::make('interview_status')
                        ->label('Interview')
                        ->options([
                            'pending' => 'Pending',
                            'scheduled' => 'Scheduled',
                            'passed' => 'Passed',
                            'failed' => 'Failed',
                        ])
                        ->disabled()
                        ->dehydrated(false),
                    DatePicker::make('interviewed_at')->label('Interview date')->disabled()->dehydrated(false),
                    Textarea::make('interview_notes')->label('Interview notes')->disabled()->dehydrated(false)->columnSpanFull(),
                    Select::make('safety_training_status')
                        ->label('Hoffman safety training')
                        ->options([
                            'pending' => 'Pending',
                            'completed' => 'Completed',
                            'expired' => 'Expired',
                        ])
                        ->disabled()
                        ->dehydrated(false),
                    DatePicker::make('safety_training_completed_on')->label('Safety training completed')->disabled()->dehydrated(false),
                    DatePicker::make('safety_training_expires_on')->label('Safety training expires')->disabled()->dehydrated(false),
                    Select::make('badge_registration_status')
                        ->label('Badge / NFC registration')
                        ->options([
                            'pending' => 'Pending',
                            'registered' => 'Registered',
                        ])
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('nfc_raw_uid')->label('Raw NFC UID')->disabled()->dehydrated(false)->maxLength(120),
                    TextInput::make('badge_number')->label('NFC ID')->disabled()->dehydrated(false)->maxLength(80),
                    FileUpload::make('badge_photo_path')
                        ->label('Hoffman badge photo')
                        ->disk('public')
                        ->directory('member-badges')
                        ->visibility('public')
                        ->image()
                        ->imagePreviewHeight('180')
                        ->openable()
                        ->downloadable()
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpanFull(),
                    TextInput::make('badge_company_name')->label('Badge company')->disabled()->dehydrated(false)->maxLength(255),
                    TextInput::make('badge_last_name')->label('Badge last name')->disabled()->dehydrated(false)->maxLength(120),
                    TextInput::make('badge_first_name')->label('Badge first name')->disabled()->dehydrated(false)->maxLength(120),
                    TextInput::make('badge_role')->label('Badge role')->disabled()->dehydrated(false)->maxLength(120),
                    DatePicker::make('badge_issued_on')->label('Badge issued on / hire date')->disabled()->dehydrated(false),
                ]),

            Section::make('⑤ 관리자 메모')
                ->schema([
                    Textarea::make('notes')
                        ->label('Admin review notes')
                        ->columnSpanFull(),
                ]),

            Section::make('⑥ 시스템 · AI 분석')
                ->description('Gemini 뱃지 분석 결과와 자동화 신호입니다.')
                ->collapsible()
                ->collapsed()
                ->schema([
                    Textarea::make('badge_analysis_preview')
                        ->label('Gemini badge analysis')
                        ->formatStateUsing(fn (?MemberRegistration $record): ?string => self::formatBadgeAnalysisPayload($record?->badge_analysis_payload))
                        ->disabled()
                        ->dehydrated(false)
                        ->rows(10)
                        ->visible(fn (?MemberRegistration $record): bool => filled($record?->badge_analysis_payload))
                        ->columnSpanFull(),
                    Textarea::make('payload_preview')
                        ->label('Automation signals')
                        ->formatStateUsing(fn (?MemberRegistration $record): ?string => self::formatBadgeAnalysisPayload($record?->payload))
                        ->disabled()
                        ->dehydrated(false)
                        ->rows(8)
                        ->visible(fn (?MemberRegistration $record): bool => filled($record?->payload))
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
                TextColumn::make('applicant_code')
                    ->label('Applicant code')
                    ->badge()
                    ->placeholder('Not submitted')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('registration_number')->label('Registration')->searchable()->sortable(),
                TextColumn::make('employee.employee_number')
                    ->label('Employee')
                    ->badge()
                    ->placeholder('Not synced')
                    ->toggleable(),
                TextColumn::make('full_name')->searchable()->sortable(),
                TextColumn::make('company.name')->label('Company')->toggleable(),
                TextColumn::make('site.code')->label('Site')->badge(),
                TextColumn::make('member_type')->badge()->sortable(),
                TextColumn::make('onboarding_status')->label('Status')->badge()->sortable(),
                TextColumn::make('interview_status')->label('Interview')->badge()->sortable()->toggleable(),
                TextColumn::make('safety_training_status')->label('Safety')->badge()->sortable()->toggleable(),
                TextColumn::make('badge_registration_status')->label('Badge status')->badge()->sortable()->toggleable(),
                TextColumn::make('badge_number')->label('NFC ID')->badge()->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('risk_level')->badge()->sortable(),
                TextColumn::make('automation_score')->label('Auto %')->sortable(),
                TextColumn::make('document_status')->badge()->toggleable(),
                TextColumn::make('submitted_at')->since()->sortable()->toggleable(),
                TextColumn::make('approved_at')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('onboarding_status')->options(MemberRegistration::onboardingStatusOptions()),
                SelectFilter::make('interview_status')->options([
                    'pending' => 'Pending',
                    'scheduled' => 'Scheduled',
                    'passed' => 'Passed',
                    'failed' => 'Failed',
                ]),
                SelectFilter::make('safety_training_status')->options([
                    'pending' => 'Pending',
                    'completed' => 'Completed',
                    'expired' => 'Expired',
                ]),
                SelectFilter::make('badge_registration_status')->options([
                    'pending' => 'Pending',
                    'registered' => 'Registered',
                ]),
                SelectFilter::make('risk_level')->options([
                    'low' => 'Low',
                    'medium' => 'Medium',
                    'high' => 'High',
                ]),
                SelectFilter::make('member_type')->options([
                    'worker' => 'Worker',
                    'staff' => 'Staff',
                    'vendor' => 'Vendor',
                    'visitor' => 'Visitor',
                    'driver' => 'Driver',
                ]),
            ])
            ->recordActions([
                Action::make('intake')
                    ->label('지원서 작성 링크 열기')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (MemberRegistration $record): string => $record->intakeUrl())
                    ->openUrlInNewTab()
                    ->visible(fn (MemberRegistration $record): bool => blank($record->submitted_at)),
                Action::make('sendIntakeLink')
                    ->label('지원서 링크 보내기')
                    ->icon('heroicon-o-envelope')
                    ->color('success')
                    ->visible(fn (MemberRegistration $record): bool => blank($record->submitted_at))
                    ->form([
                        TextInput::make('email')
                            ->label('지원자 이메일')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->default(fn (MemberRegistration $record): ?string => $record->email),
                    ])
                    ->modalHeading('지원서 링크 이메일 발송')
                    ->modalSubmitActionLabel('이메일 보내기')
                    ->action(function (MemberRegistration $record, array $data, Action $action): void {
                        $record->forceFill(['email' => $data['email']])->save();
                        $service = app(ApplicantInvitationService::class);
                        $email = (string) $data['email'];

                        if (! $service->hasRealMailerConfigured()) {
                            Notification::make()
                                ->success()
                                ->title('지원서 링크 준비 완료')
                                ->body('서버 메일 설정이 없어 메일 작성창을 열었습니다. 작성창에서 보내기를 누르면 전달됩니다.')
                                ->persistent()
                                ->send();

                            $action->redirect($service->mailtoUrl($record, $email));

                            return;
                        }

                        try {
                            $service->sendEmail($record, $email);
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->warning()
                                ->title('메일 작성창으로 전환')
                                ->body('서버 메일 발송이 완료되지 않아 메일 작성창을 열었습니다. 작성창에서 보내기를 누르면 전달됩니다.')
                                ->persistent()
                                ->send();

                            report($exception);

                            $action->redirect($service->mailtoUrl($record, $email));

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('지원서 링크 발송 완료')
                            ->body((string) $data['email'] . ' 로 입사지원서 링크를 보냈습니다.')
                            ->send();
                    }),
                Action::make('qr')
                    ->label('QR 코드')
                    ->icon('heroicon-o-qr-code')
                    ->color('gray')
                    ->url(fn (MemberRegistration $record): string => $record->qrUrl())
                    ->openUrlInNewTab()
                    ->visible(fn (MemberRegistration $record): bool => blank($record->submitted_at)),
                Action::make('markInterviewPassed')
                    ->label('인터뷰 합격')
                    ->icon('heroicon-o-user-circle')
                    ->color('info')
                    ->visible(fn (MemberRegistration $record): bool => filled($record->submitted_at)
                        && $record->interview_status !== 'passed'
                        && ! in_array($record->onboarding_status, ['active', 'rejected', 'archived'], true))
                    ->form([
                        DatePicker::make('interviewed_at')
                            ->label('Interview date')
                            ->default(fn (): string => now()->toDateString()),
                        Textarea::make('interview_notes')
                            ->label('Interview notes')
                            ->columnSpanFull(),
                    ])
                    ->modalHeading('인터뷰 합격 처리')
                    ->modalSubmitActionLabel('인터뷰 합격')
                    ->action(function (MemberRegistration $record, array $data): void {
                        $record->fill([
                            'interviewed_at' => $data['interviewed_at'] ?? $record->interviewed_at,
                            'interview_notes' => $data['interview_notes'] ?? $record->interview_notes,
                        ]);
                        $record->markInterviewPassed(auth()->user());

                        Notification::make()
                            ->success()
                            ->title('인터뷰 합격 처리 완료')
                            ->body('다음 단계로 Hoffman 안전교육 완료를 기록할 수 있습니다.')
                            ->send();
                    }),
                Action::make('markSafetyTrainingCompleted')
                    ->label('안전교육 완료')
                    ->icon('heroicon-o-shield-check')
                    ->color('warning')
                    ->visible(fn (MemberRegistration $record): bool => $record->interview_status === 'passed'
                        && $record->safety_training_status !== 'completed'
                        && ! in_array($record->onboarding_status, ['active', 'rejected', 'archived'], true))
                    ->form([
                        DatePicker::make('safety_training_completed_on')
                            ->label('Completed on')
                            ->default(fn (): string => now()->toDateString())
                            ->required(),
                        DatePicker::make('safety_training_expires_on')
                            ->label('Expires on'),
                    ])
                    ->modalHeading('Hoffman 안전교육 완료')
                    ->modalSubmitActionLabel('안전교육 완료 저장')
                    ->action(function (MemberRegistration $record, array $data): void {
                        $record->fill([
                            'safety_training_completed_on' => $data['safety_training_completed_on'] ?? now()->toDateString(),
                            'safety_training_expires_on' => $data['safety_training_expires_on'] ?? $record->safety_training_expires_on,
                        ]);
                        $record->markSafetyTrainingCompleted();

                        Notification::make()
                            ->success()
                            ->title('안전교육 완료 저장')
                            ->body('다음 단계로 Hoffman Badge/NFC를 등록할 수 있습니다.')
                            ->send();
                    }),
                Action::make('registerBadgeNfc')
                    ->label('Badge/NFC 등록')
                    ->icon('heroicon-o-identification')
                    ->color('info')
                    ->visible(fn (MemberRegistration $record): bool => $record->safety_training_status === 'completed'
                        && ! in_array($record->onboarding_status, ['active', 'rejected', 'archived'], true))
                    ->fillForm(fn (MemberRegistration $record): array => [
                        'nfc_raw_uid' => $record->nfc_raw_uid,
                        'badge_number' => $record->badge_number,
                        'badge_photo_path' => $record->badge_photo_path,
                        'badge_company_name' => $record->badge_company_name,
                        'badge_last_name' => $record->badge_last_name,
                        'badge_first_name' => $record->badge_first_name,
                        'badge_role' => $record->badge_role,
                        'badge_issued_on' => $record->badge_issued_on?->toDateString(),
                        'badge_analysis_model' => $record->badge_analysis_model,
                        'badge_analyzed_at' => $record->badge_analyzed_at?->toDateTimeString(),
                        'badge_analysis_payload' => self::encodeBadgeAnalysisPayload($record->badge_analysis_payload),
                        'badge_analysis_preview' => self::formatBadgeAnalysisPayload($record->badge_analysis_payload),
                    ])
                    ->form([
                        TextInput::make('nfc_raw_uid')
                            ->label('Raw NFC UID')
                            ->helperText('Example: 90227842853E04. ERP stores N- plus the last 9 characters.')
                            ->required()
                            ->maxLength(120)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, mixed $state): void {
                                if (filled($state)) {
                                    $set('badge_number', MemberRegistration::normalizeNfcUid((string) $state));
                                }
                            }),
                        TextInput::make('badge_number')
                            ->label('NFC ID')
                            ->disabled()
                            ->dehydrated()
                            ->maxLength(80),
                        FileUpload::make('badge_photo_path')
                            ->label('Hoffman badge photo / camera')
                            ->disk('public')
                            ->directory('member-badges')
                            ->visibility('public')
                            ->image()
                            ->imagePreviewHeight('180')
                            ->maxSize(10240)
                            ->openable()
                            ->downloadable()
                            ->required()
                            ->extraInputAttributes(['accept' => 'image/*', 'capture' => 'environment'], merge: true)
                            ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                if (! $state instanceof TemporaryUploadedFile) {
                                    return;
                                }

                                self::analyzeBadgePhoto($state, $set, $get);
                            })
                            ->columnSpanFull(),
                        Actions::make([
                            Action::make('analyzeBadgePhotoForApplicant')
                                ->label('Analyze badge photo')
                                ->icon('heroicon-o-sparkles')
                                ->color('info')
                                ->action(fn (Set $set, Get $get): null => self::analyzeBadgePhoto($get('badge_photo_path'), $set, $get)),
                        ])->columnSpanFull(),
                        TextInput::make('badge_company_name')->label('Badge company')->maxLength(255),
                        TextInput::make('badge_last_name')->label('Badge last name')->maxLength(120),
                        TextInput::make('badge_first_name')->label('Badge first name')->maxLength(120),
                        TextInput::make('badge_role')->label('Badge role')->maxLength(120),
                        DatePicker::make('badge_issued_on')
                            ->label('Badge issued on / hire date')
                            ->required(),
                        Hidden::make('badge_analysis_model'),
                        Hidden::make('badge_analyzed_at'),
                        Hidden::make('badge_analysis_payload')
                            ->dehydrateStateUsing(fn (mixed $state): ?array => self::normalizeBadgeAnalysisPayload($state)),
                        Textarea::make('badge_analysis_preview')
                            ->label('Gemini badge analysis')
                            ->disabled()
                            ->dehydrated(false)
                            ->rows(10)
                            ->visible(fn (Get $get): bool => filled($get('badge_analysis_preview')))
                            ->columnSpanFull(),
                    ])
                    ->modalHeading('Hoffman Badge/NFC 등록')
                    ->modalSubmitActionLabel('Badge/NFC 저장')
                    ->action(function (MemberRegistration $record, array $data): void {
                        $badgePhotoPath = is_array($data['badge_photo_path'] ?? null)
                            ? Arr::first($data['badge_photo_path'])
                            : ($data['badge_photo_path'] ?? null);

                        $record->fill([
                            'nfc_raw_uid' => $data['nfc_raw_uid'] ?? null,
                            'badge_number' => filled($data['nfc_raw_uid'] ?? null)
                                ? MemberRegistration::normalizeNfcUid((string) $data['nfc_raw_uid'])
                                : ($data['badge_number'] ?? null),
                            'badge_photo_path' => $badgePhotoPath,
                            'badge_company_name' => self::nullableText($data['badge_company_name'] ?? null),
                            'badge_last_name' => self::nullableText($data['badge_last_name'] ?? null),
                            'badge_first_name' => self::nullableText($data['badge_first_name'] ?? null),
                            'badge_role' => self::nullableText($data['badge_role'] ?? null),
                            'badge_issued_on' => $data['badge_issued_on'] ?? null,
                            'badge_analysis_model' => $data['badge_analysis_model'] ?? null,
                            'badge_analyzed_at' => $data['badge_analyzed_at'] ?? null,
                            'badge_analysis_payload' => self::normalizeBadgeAnalysisPayload($data['badge_analysis_payload'] ?? null),
                            'badge_registration_status' => 'registered',
                            'onboarding_status' => 'badge_pending',
                        ])->save();

                        Notification::make()
                            ->success()
                            ->title('Badge/NFC 저장 완료')
                            ->body('조건이 모두 맞으면 직원 활성화를 진행할 수 있습니다.')
                            ->send();
                    }),
                Action::make('activateEmployee')
                    ->label('직원 활성화')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (MemberRegistration $record): bool => $record->onboarding_status === 'badge_pending')
                    ->action(function (MemberRegistration $record): void {
                        try {
                            $record->activateAsEmployee(auth()->user());
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->warning()
                                ->title('직원 활성화 불가')
                                ->body(implode(' ', Arr::flatten($exception->errors())))
                                ->persistent()
                                ->send();

                            return;
                        }

                        self::notifySyncResult($record->fresh());
                    }),
                Action::make('openEmployee')
                    ->label('Employees 열기')
                    ->icon('heroicon-o-identification')
                    ->color('gray')
                    ->visible(fn (MemberRegistration $record): bool => filled($record->employee_id))
                    ->url(fn (): string => EmployeeResource::getUrl('index')),
                Action::make('rejectApplication')
                    ->label('불합격')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (MemberRegistration $record): bool => ! in_array($record->onboarding_status, ['active', 'rejected', 'archived'], true))
                    ->action(function (MemberRegistration $record): void {
                        $record->rejectApplication(auth()->user());
                        Notification::make()->success()->title('불합격 처리 완료')->send();
                    }),
                Action::make('resync')
                    ->label('Re-sync')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (MemberRegistration $record): bool => $record->onboarding_status === 'active')
                    ->action(function (MemberRegistration $record): void {
                        $record->syncDownstream();
                        self::notifySyncResult($record);
                    }),
                EditAction::make()
                    ->label('지원서 검토')
                    ->icon('heroicon-o-eye'),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    protected static function notifySyncResult(MemberRegistration $record): void
    {
        if ($record->hasAccessAccount()) {
            Notification::make()
                ->success()
                ->title('연동 완료')
                ->body('직원(Employees) · 계정(Access Control) · 서류(Member Documents)에 반영되었습니다.')
                ->send();

            return;
        }

        Notification::make()
            ->warning()
            ->title('계정(Access Control) 미생성 — 이메일 없음')
            ->body('직원·서류는 반영됐지만, 이메일이 없어 로그인 계정은 만들어지지 않았습니다. 이메일을 입력 후 Re-sync 하세요.')
            ->persistent()
            ->send();
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
                ->body('Company, name, role, and issue date were filled from the photo.')
                ->send();
        } catch (Throwable $exception) {
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
        self::setIfFilled($set, 'badge_first_name', $analysis['first_name'] ?? null);
        self::setIfFilled($set, 'badge_last_name', $analysis['last_name'] ?? null);
        self::setIfFilled($set, 'badge_role', $analysis['role'] ?? null);
        self::setIfFilled($set, 'badge_company_name', $analysis['company_name'] ?? null);
        self::setIfFilled($set, 'badge_issued_on', $analysis['issued_on'] ?? null);

        if (blank($get('first_name'))) {
            self::setIfFilled($set, 'first_name', $analysis['first_name'] ?? null);
        }

        if (blank($get('last_name'))) {
            self::setIfFilled($set, 'last_name', $analysis['last_name'] ?? null);
        }

        if (blank($get('role'))) {
            self::setIfFilled($set, 'role', $analysis['role'] ?? null);
        }

        if (blank($get('badge_number'))) {
            self::setIfFilled($set, 'badge_number', $analysis['badge_number'] ?? null);
        }

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

    private static function syncFullName(Set $set, Get $get): null
    {
        $firstName = trim((string) $get('first_name'));
        $lastName = trim((string) $get('last_name'));
        $fullName = trim(implode(' ', array_filter([$firstName, $lastName])));

        if ($fullName !== '') {
            $set('full_name', $fullName);
        }

        return null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMemberRegistrations::route('/'),
        ];
    }
}
