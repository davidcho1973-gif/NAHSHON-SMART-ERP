<?php

namespace App\Filament\Resources\MemberRegistrations;

use App\Filament\Resources\MemberRegistrations\Pages\ManageMemberRegistrations;
use App\Models\Company;
use App\Models\MemberRegistration;
use App\Models\Site;
use App\Models\Team;
use App\Services\GeminiBadgeAnalyzer;
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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class MemberRegistrationResource extends Resource
{
    protected static ?string $model = MemberRegistration::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-user-plus';

    protected static ?string $navigationLabel = '입사지원서 (Applicants)';

    protected static ?string $modelLabel = 'Applicant';

    protected static ?string $pluralModelLabel = 'Applicants';

    protected static string | \UnitEnum | null $navigationGroup = 'HUMAN RESOURCE';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('preferred_language')
                ->label('Application language')
                ->options(MemberRegistration::languageOptions())
                ->default('es')
                ->required(),
            Select::make('member_type')
                ->label('Member type')
                ->options([
                    'worker' => 'Worker',
                    'staff' => 'Staff',
                    'vendor' => 'Vendor',
                    'visitor' => 'Visitor',
                    'driver' => 'Driver',
                ])
                ->default('worker')
                ->required(),
            TextInput::make('last_name')
                ->label('Last name / 성')
                ->maxLength(120)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Set $set, Get $get): null => self::syncFullName($set, $get)),
            TextInput::make('first_name')
                ->label('First name / 이름')
                ->maxLength(120)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Set $set, Get $get): null => self::syncFullName($set, $get)),
            TextInput::make('full_name')
                ->label('Full name')
                ->required()
                ->maxLength(255),
            TextInput::make('preferred_name')->maxLength(255),
            TextInput::make('email')->email()->maxLength(255),
            TextInput::make('phone')->tel()->maxLength(80),
            DatePicker::make('date_of_birth')
                ->label('Date of birth'),
            TextInput::make('address')
                ->label('Address')
                ->maxLength(255)
                ->columnSpanFull(),
            TextInput::make('emergency_contact_name')
                ->label('Emergency contact')
                ->maxLength(255),
            TextInput::make('emergency_contact_phone')
                ->label('Emergency phone')
                ->tel()
                ->maxLength(80),
            TextInput::make('employee_number')->label('Employee ID')->maxLength(80),
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
            TextInput::make('nationality')->maxLength(80),
            Select::make('role')
                ->label('Position')
                ->options(MemberRegistration::roleOptions())
                ->searchable(),
            TextInput::make('trade')->maxLength(120),
            DatePicker::make('start_date'),
            DatePicker::make('end_date'),
            TextInput::make('visa_type')->maxLength(60),
            DatePicker::make('visa_expires_on'),
            DatePicker::make('safety_training_expires_on'),
            Select::make('identity_status')
                ->options([
                    'pending' => 'Pending',
                    'verified' => 'Verified',
                    'needs_review' => 'Needs review',
                ])
                ->default('pending')
                ->required(),
            Select::make('document_status')
                ->options([
                    'missing' => 'Missing',
                    'pending' => 'Pending',
                    'verified' => 'Verified',
                    'expired' => 'Expired',
                ])
                ->default('missing')
                ->required(),
            Select::make('interview_status')
                ->label('Interview')
                ->options([
                    'pending' => 'Pending',
                    'scheduled' => 'Scheduled',
                    'passed' => 'Passed',
                    'failed' => 'Failed',
                ])
                ->default('pending')
                ->required(),
            DatePicker::make('interviewed_at')
                ->label('Interview date'),
            Textarea::make('interview_notes')
                ->label('Interview notes')
                ->columnSpanFull(),
            Select::make('safety_training_status')
                ->label('Hoffman safety training')
                ->options([
                    'pending' => 'Pending',
                    'scheduled' => 'Scheduled',
                    'completed' => 'Completed',
                    'failed' => 'Failed',
                ])
                ->default('pending')
                ->required(),
            DatePicker::make('safety_training_completed_on')
                ->label('Safety completed on'),
            TextInput::make('nfc_raw_uid')
                ->label('NFC raw UID')
                ->helperText('Example: 90227842853E04 becomes N-842853E04.')
                ->maxLength(120)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Set $set, ?string $state): null => self::syncNfcId($set, $state)),
            TextInput::make('badge_number')
                ->label('ERP NFC ID')
                ->helperText('N- + last 9 characters from the raw UID.')
                ->maxLength(80),
            Select::make('badge_registration_status')
                ->label('Badge / NFC')
                ->options([
                    'pending' => 'Pending',
                    'photo_analyzed' => 'Photo analyzed',
                    'nfc_scanned' => 'NFC scanned',
                    'registered' => 'Registered',
                ])
                ->default('pending')
                ->required(),
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
                ->helperText('Take a badge photo on mobile or upload it. Gemini 3.5 Flash reads company, last name, first name, role, and issued date.')
                ->extraInputAttributes(['accept' => 'image/*', 'capture' => 'environment'], merge: true)
                ->afterStateUpdated(function (Set $set, Get $get, ?TemporaryUploadedFile $state): void {
                    if ($state instanceof TemporaryUploadedFile) {
                        self::analyzeBadgePhoto($state, $set, $get);
                    }
                })
                ->columnSpanFull(),
            Actions::make([
                Action::make('analyzeBadgePhoto')
                    ->label('Analyze Hoffman badge')
                    ->icon('heroicon-o-sparkles')
                    ->color('info')
                    ->action(fn (Set $set, Get $get): null => self::analyzeBadgePhoto($get('badge_photo_path'), $set, $get)),
            ])->columnSpanFull(),
            TextInput::make('badge_company_name')->label('Badge company')->maxLength(255),
            TextInput::make('badge_last_name')->label('Badge last name')->maxLength(120),
            TextInput::make('badge_first_name')->label('Badge first name')->maxLength(120),
            TextInput::make('badge_role')->label('Badge role')->maxLength(120),
            DatePicker::make('badge_issued_on')->label('Badge issued on'),
            Hidden::make('badge_analysis_model'),
            Hidden::make('badge_analyzed_at'),
            Select::make('onboarding_status')
                ->options([
                    'draft' => 'Draft',
                    'invited' => 'Invited',
                    'submitted' => 'Submitted',
                    'interview' => 'Interview',
                    'interview_passed' => 'Interview passed',
                    'safety_training' => 'Hoffman safety training',
                    'badge_pending' => 'Badge / NFC pending',
                    'screening' => 'Screening (legacy)',
                    'approved' => 'Approved (legacy)',
                    'active' => 'Active',
                    'rejected' => 'Rejected',
                    'archived' => 'Archived',
                ])
                ->default('draft')
                ->required(),
            Textarea::make('notes')->columnSpanFull(),
            KeyValue::make('badge_analysis_payload')
                ->label('Gemini badge analysis')
                ->keyLabel('Field')
                ->valueLabel('Value')
                ->disabled()
                ->dehydrated()
                ->visible(fn (Get $get): bool => filled($get('badge_analysis_payload')))
                ->columnSpanFull(),
            KeyValue::make('payload')
                ->keyLabel('Signal')
                ->valueLabel('Value')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
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
                TextColumn::make('badge_number')->label('NFC ID')->badge()->searchable()->toggleable(),
                TextColumn::make('risk_level')->badge()->sortable(),
                TextColumn::make('automation_score')->label('Auto %')->sortable(),
                TextColumn::make('document_status')->badge()->toggleable(),
                TextColumn::make('submitted_at')->since()->sortable()->toggleable(),
                TextColumn::make('approved_at')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('onboarding_status')->options([
                    'draft' => 'Draft',
                    'invited' => 'Invited',
                    'submitted' => 'Submitted',
                    'interview' => 'Interview',
                    'interview_passed' => 'Interview passed',
                    'safety_training' => 'Hoffman safety training',
                    'badge_pending' => 'Badge / NFC pending',
                    'screening' => 'Screening (legacy)',
                    'approved' => 'Approved (legacy)',
                    'active' => 'Active',
                    'rejected' => 'Rejected',
                    'archived' => 'Archived',
                ]),
                SelectFilter::make('interview_status')->options([
                    'pending' => 'Pending',
                    'scheduled' => 'Scheduled',
                    'passed' => 'Passed',
                    'failed' => 'Failed',
                ]),
                SelectFilter::make('safety_training_status')->options([
                    'pending' => 'Pending',
                    'scheduled' => 'Scheduled',
                    'completed' => 'Completed',
                    'failed' => 'Failed',
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
                    ->label('Open intake')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (MemberRegistration $record): string => $record->intakeUrl())
                    ->openUrlInNewTab(),
                Action::make('interviewPassed')
                    ->label('Interview pass')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (MemberRegistration $record): bool => ! in_array($record->onboarding_status, ['active', 'rejected', 'archived'], true))
                    ->action(function (MemberRegistration $record): void {
                        $record->markInterviewPassed(auth()->user());
                        Notification::make()
                            ->success()
                            ->title('Interview recorded')
                            ->body('Applicant moved to Hoffman safety training.')
                            ->send();
                    }),
                Action::make('safetyComplete')
                    ->label('Safety complete')
                    ->icon('heroicon-o-shield-check')
                    ->color('info')
                    ->requiresConfirmation()
                    ->visible(fn (MemberRegistration $record): bool => ! in_array($record->onboarding_status, ['active', 'rejected', 'archived'], true))
                    ->action(function (MemberRegistration $record): void {
                        $record->markSafetyTrainingCompleted();
                        Notification::make()
                            ->success()
                            ->title('Safety training completed')
                            ->body('Applicant is ready for badge photo and NFC registration.')
                            ->send();
                    }),
                Action::make('activateEmployee')
                    ->label('Activate employee')
                    ->icon('heroicon-o-identification')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (MemberRegistration $record): bool => $record->onboarding_status !== 'active')
                    ->action(function (MemberRegistration $record): void {
                        try {
                            $record->activateAsEmployee(auth()->user());
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->warning()
                                ->title('Employee activation blocked')
                                ->body(implode(' ', Arr::flatten($exception->errors())))
                                ->persistent()
                                ->send();

                            return;
                        }

                        self::notifySyncResult($record->fresh());
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
                EditAction::make(),
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
                ->body('Take a Hoffman badge photo or upload an image first.')
                ->send();

            return null;
        }

        try {
            $analysis = app(GeminiBadgeAnalyzer::class)->analyze($file['path'], $file['mime_type']);
            self::applyBadgeAnalysis($analysis, $set, $get);

            Notification::make()
                ->success()
                ->title('Badge analysis complete')
                ->body('Gemini filled the company, name, role, and issued date from the Hoffman badge.')
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
        self::setIfFilled($set, 'badge_company_name', $analysis['company_name'] ?? null);
        self::setIfFilled($set, 'badge_first_name', $analysis['first_name'] ?? null);
        self::setIfFilled($set, 'badge_last_name', $analysis['last_name'] ?? null);
        self::setIfFilled($set, 'badge_role', $analysis['role'] ?? null);
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

        if (blank($get('start_date'))) {
            self::setIfFilled($set, 'start_date', $analysis['issued_on'] ?? null);
        }

        if (blank($get('badge_number'))) {
            self::setIfFilled($set, 'badge_number', $analysis['badge_number'] ?? null);
        }

        $set('badge_registration_status', blank($get('badge_number')) ? 'photo_analyzed' : 'nfc_scanned');
        $set('badge_analysis_model', $analysis['model'] ?? config('services.gemini.model', 'gemini-3.5-flash'));
        $set('badge_analyzed_at', Carbon::now()->toDateTimeString());
        $set('badge_analysis_payload', Arr::except($analysis, ['raw']) + [
            'raw_json' => json_encode($analysis['raw'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        self::syncFullName($set, $get);
    }

    private static function syncNfcId(Set $set, ?string $state): null
    {
        $nfcId = MemberRegistration::normalizeNfcUid($state);

        if ($nfcId !== null) {
            $set('badge_number', $nfcId);
            $set('badge_registration_status', 'nfc_scanned');
        }

        return null;
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

    public static function getPages(): array
    {
        return [
            'index' => ManageMemberRegistrations::route('/'),
        ];
    }
}
