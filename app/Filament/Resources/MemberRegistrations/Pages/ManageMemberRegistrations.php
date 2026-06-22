<?php

namespace App\Filament\Resources\MemberRegistrations\Pages;

use App\Filament\Resources\MemberRegistrations\MemberRegistrationResource;
use App\Models\Company;
use App\Models\MemberRegistration;
use App\Models\Site;
use App\Services\ApplicantInvitationService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
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
            CreateAction::make()
                ->label('지원서 작성')
                ->icon('heroicon-o-document-plus')
                ->modalHeading('지원서 초대 생성')
                ->modalSubmitActionLabel('지원서 링크 만들기'),
            Action::make('sendApplicationLink')
                ->label('지원서 링크로 보내기')
                ->icon('heroicon-o-envelope')
                ->color('success')
                ->form(self::inviteForm(emailRequired: true))
                ->modalHeading('지원서 링크 이메일 발송')
                ->modalSubmitActionLabel('이메일 보내기')
                ->action(function (array $data): void {
                    $registration = app(ApplicantInvitationService::class)
                        ->createInvitation($data, 'email', auth()->id());

                    try {
                        app(ApplicantInvitationService::class)->sendEmail($registration, (string) $data['email']);
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->danger()
                            ->title('이메일 발송 실패')
                            ->body($exception->getMessage() . ' 지원서 링크는 생성됐습니다: ' . $registration->intakeUrl())
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
