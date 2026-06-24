<?php

namespace App\Filament\Resources\MemberRegistrations\Pages;

use App\Filament\Resources\MemberRegistrations\MemberRegistrationResource;
use App\Models\Company;
use App\Models\MemberRegistration;
use App\Models\Site;
use App\Services\ApplicantInvitationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Throwable;

class ManageMemberRegistrations extends ManageRecords
{
    protected static string $resource = MemberRegistrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createApplicationInvitation')
                ->label('지원서 링크 만들기')
                ->icon('heroicon-o-link')
                ->color('primary')
                ->form(self::inviteForm())
                ->modalHeading('지원자가 작성할 링크 생성')
                ->modalDescription('관리자는 지원서를 대신 작성하지 않습니다. 링크 또는 QR을 만든 뒤 지원자가 직접 작성해서 제출합니다.')
                ->modalSubmitActionLabel('링크 만들기')
                ->action(function (array $data): void {
                    $registration = app(ApplicantInvitationService::class)
                        ->createInvitation($data, 'admin-link', auth()->id());

                    Notification::make()
                        ->success()
                        ->title('지원서 링크 생성 완료')
                        ->body($registration->intakeUrl())
                        ->persistent()
                        ->send();
                }),
            Action::make('sendApplicationLink')
                ->label('지원서 링크로 보내기')
                ->icon('heroicon-o-envelope')
                ->color('success')
                ->form(self::inviteForm(emailRequired: true))
                ->modalHeading('지원서 링크 이메일 발송')
                ->modalSubmitActionLabel('이메일 보내기')
                ->action(function (array $data, Action $action): void {
                    $service = app(ApplicantInvitationService::class);
                    $registration = $service->createInvitation($data, 'email', auth()->id());
                    $email = (string) $data['email'];

                    if (! $service->hasRealMailerConfigured()) {
                        Notification::make()
                            ->success()
                            ->title('지원서 링크 생성 완료')
                            ->body('서버 메일 설정이 없어 메일 작성창을 열었습니다. 작성창에서 보내기를 누르면 전달됩니다.')
                            ->persistent()
                            ->send();

                        $action->redirect($service->mailtoUrl($registration, $email));

                        return;
                    }

                    try {
                        $service->sendEmail($registration, $email);
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->warning()
                            ->title('메일 작성창으로 전환')
                            ->body('서버 메일 발송이 완료되지 않아 메일 작성창을 열었습니다. 작성창에서 보내기를 누르면 전달됩니다.')
                            ->persistent()
                            ->send();

                        report($exception);

                        $action->redirect($service->mailtoUrl($registration, $email));

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title('지원서 링크 발송 완료')
                        ->body((string) $data['email'] . ' 로 입사지원서 링크를 보냈습니다.')
                        ->send();
                }),
            Action::make('openApplicationQr')
                ->label('QR 코드로 열기')
                ->icon('heroicon-o-qr-code')
                ->color('gray')
                ->form(self::inviteForm())
                ->modalHeading('QR 입사지원서 만들기')
                ->modalSubmitActionLabel('QR 코드 열기')
                ->action(function (array $data, Action $action): void {
                    $registration = app(ApplicantInvitationService::class)
                        ->createInvitation($data, 'qr', auth()->id());

                    $action->redirect($registration->qrUrl());
                }),
            Action::make('openSiteApplicationQr')
                ->label('현장 공용 QR')
                ->icon('heroicon-o-qr-code')
                ->color('warning')
                ->form([
                    Select::make('site_id')
                        ->label('현장명 선택')
                        ->options(fn (): array => Site::query()
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn (Site $site): array => [
                                $site->id => $site->address
                                    ? "{$site->code} - {$site->name} ({$site->address})"
                                    : "{$site->code} - {$site->name}",
                            ])
                            ->all())
                        ->placeholder('현장 관리에 등록된 현장을 선택하세요')
                        ->helperText('현장 주소는 선택한 현장 정보에서 자동으로 안내문에 표시됩니다.')
                        ->required()
                        ->searchable(),
                    Select::make('preferred_language')
                        ->label('기본 지원서 언어')
                        ->options(MemberRegistration::languageOptions())
                        ->default('es')
                        ->required(),
                ])
                ->modalHeading('현장에서 바로 쓰는 공용 QR')
                ->modalDescription('현장 관리에 등록된 현장을 선택해 프린트용 공용 QR 안내문을 엽니다. 지원자 정보는 QR을 스캔한 사람이 제출할 때 생성됩니다.')
                ->modalSubmitActionLabel('현장 QR 열기')
                ->action(function (array $data, Action $action): void {
                    $action->redirect(route('member-registration.site.qr', [
                        'site' => $data['site_id'],
                        'lang' => $data['preferred_language'],
                    ]));
                }),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private static function inviteForm(bool $emailRequired = false): array
    {
        return [
            TextInput::make('full_name')
                ->label('지원자 이름')
                ->placeholder('비워두면 이메일 또는 QR Applicant로 표시됩니다.')
                ->maxLength(255),
            TextInput::make('email')
                ->label('지원자 이메일')
                ->email()
                ->required($emailRequired)
                ->maxLength(255),
            TextInput::make('phone')
                ->label('전화번호')
                ->tel()
                ->maxLength(80),
            Select::make('preferred_language')
                ->label('지원서 언어')
                ->options(MemberRegistration::languageOptions())
                ->default('es')
                ->required(),
            Select::make('company_id')
                ->label('회사')
                ->options(fn (): array => Company::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable(),
            Select::make('site_id')
                ->label('희망 현장')
                ->options(fn (): array => Site::query()->orderBy('code')->pluck('code', 'id')->all())
                ->searchable(),
        ];
    }
}
