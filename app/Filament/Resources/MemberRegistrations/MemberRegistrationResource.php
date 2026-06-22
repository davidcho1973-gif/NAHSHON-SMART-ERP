<?php

namespace App\Filament\Resources\MemberRegistrations;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\MemberRegistrations\Pages\ManageMemberRegistrations;
use App\Models\Company;
use App\Models\MemberRegistration;
use App\Models\Site;
use App\Models\Team;
use App\Services\ApplicantInvitationService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Throwable;

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
            TextInput::make('applicant_code')
                ->label('Applicant code / 지원자 코드')
                ->disabled()
                ->dehydrated(),
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
            Select::make('onboarding_status')
                ->options([
                    'draft' => 'Draft',
                    'invited' => 'Invited',
                    'submitted' => 'Submitted',
                    'under_review' => 'Under review',
                    'employee_registration' => 'Employee registration',
                    'interview' => 'Interview (legacy)',
                    'interview_passed' => 'Interview passed (legacy)',
                    'safety_training' => 'Hoffman safety training (legacy)',
                    'badge_pending' => 'Badge / NFC pending (legacy)',
                    'screening' => 'Screening (legacy)',
                    'approved' => 'Approved (legacy)',
                    'active' => 'Active',
                    'rejected' => 'Rejected',
                    'archived' => 'Archived',
                ])
                ->default('draft')
                ->required(),
            Textarea::make('notes')->columnSpanFull(),
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
                    'under_review' => 'Under review',
                    'employee_registration' => 'Employee registration',
                    'interview' => 'Interview (legacy)',
                    'interview_passed' => 'Interview passed (legacy)',
                    'safety_training' => 'Hoffman safety training (legacy)',
                    'badge_pending' => 'Badge / NFC pending (legacy)',
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
                    ->openUrlInNewTab(),
                Action::make('sendIntakeLink')
                    ->label('지원서 링크 보내기')
                    ->icon('heroicon-o-envelope')
                    ->color('success')
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
                    ->action(function (MemberRegistration $record, array $data): void {
                        $record->forceFill(['email' => $data['email']])->save();

                        try {
                            app(ApplicantInvitationService::class)->sendEmail($record, (string) $data['email']);
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->danger()
                                ->title('이메일 발송 실패')
                                ->body($exception->getMessage() . ' 지원서 링크는 유지됩니다: ' . $record->intakeUrl())
                                ->persistent()
                                ->send();

                            report($exception);

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
                    ->openUrlInNewTab(),
                Action::make('passApplication')
                    ->label('합격 처리')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (MemberRegistration $record): bool => ! in_array($record->onboarding_status, ['employee_registration', 'active', 'rejected', 'archived'], true))
                    ->action(function (MemberRegistration $record): void {
                        try {
                            $employee = $record->passApplication(auth()->user());
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->warning()
                                ->title('합격 처리 불가')
                                ->body(implode(' ', Arr::flatten($exception->errors())))
                                ->persistent()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('합격 처리 완료')
                            ->body("Employees 등록 초안이 생성되었습니다: {$employee->employee_number}")
                            ->send();
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
                        $record->forceFill(['onboarding_status' => 'rejected'])->save();
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
