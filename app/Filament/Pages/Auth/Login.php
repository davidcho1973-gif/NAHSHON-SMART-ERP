<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Support\Htmlable;

class Login extends BaseLogin
{
    public function mount(): void
    {
        parent::mount();

        $this->form->fill([
            'email' => $this->getDefaultAdminEmail(),
            'remember' => true,
        ]);
    }

    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()
            ->label('관리자 이메일')
            ->default($this->getDefaultAdminEmail())
            ->helperText('staging 관리자 계정이 자동 입력됩니다.')
            ->prefixIcon('heroicon-o-envelope')
            ->autofocus(false);
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->label('비밀번호')
            ->placeholder('임시 비밀번호를 입력하세요.')
            ->prefixIcon('heroicon-o-lock-closed');
    }

    protected function getRememberFormComponent(): Component
    {
        return parent::getRememberFormComponent()
            ->label('이 장치에서 기억하기')
            ->default(true);
    }

    public function getTitle(): string | Htmlable
    {
        return '관리자 로그인 - SMART COMPANY';
    }

    public function getHeading(): string | Htmlable | null
    {
        return '관리자 콘솔 로그인';
    }

    public function getSubheading(): string | Htmlable | null
    {
        return 'NAHSHON MEP ERP staging environment';
    }

    public function hasLogo(): bool
    {
        return false;
    }

    private function getDefaultAdminEmail(): string
    {
        return 'davidcho1973@gmail.com';
    }
}
