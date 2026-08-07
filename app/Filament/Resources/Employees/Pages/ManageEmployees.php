<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\UserAccesses\UserAccessResource;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Services\Hr\AccessAccountProvisioner;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Utilities\Get;

class ManageEmployees extends ManageRecords
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // ① 원샷 직접 인사등록 — 직원 + 로그인 계정을 한 화면에서.
            Action::make('registerWithAccount')
                ->label('직접 인사등록 (+로그인)')
                ->icon('heroicon-o-user-plus')
                ->color('primary')
                ->modalHeading('직접 인사등록 — 직원 + 로그인 계정 한 번에')
                ->modalDescription('이름·구글 이메일·유형만 정하면 직원 기록과 로그인 계정을 함께 만듭니다. 로그인은 적어주신 구글 이메일로 이뤄집니다.')
                ->modalSubmitActionLabel('등록')
                ->visible(fn (): bool => in_array(auth()->user()?->access_role, ['super_admin', 'admin', 'hr_manager'], true))
                ->form([
                    TextInput::make('full_name')
                        ->label('이름')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('email')
                        ->label('구글 이메일')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->helperText('로그인 열쇠입니다. 본인 구글 계정과 글자까지 똑같아야 합니다. (gmail 등 아무 구글 계정 가능)'),
                    // ② 오타 방지 — 이메일 한 번 더 확인.
                    TextInput::make('email_confirm')
                        ->label('구글 이메일 다시 입력')
                        ->email()
                        ->required()
                        ->same('email')
                        ->dehydrated(false)
                        ->helperText('오타 방지용 — 위와 똑같이 입력하세요.'),
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
                        ->required(),
                    Select::make('company_id')
                        ->label('회사')
                        ->options(fn (): array => Company::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable(),
                    Select::make('site_id')
                        ->label('현장')
                        ->options(fn (): array => Site::query()->orderBy('code')->pluck('code', 'id')->all())
                        ->searchable(),
                    Select::make('team_id')
                        ->label('팀')
                        ->options(fn (): array => Team::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable(),
                ])
                ->action(function (array $data): void {
                    $role = $data['account_type'] === 'admin' ? ($data['admin_role'] ?? null) : 'worker';

                    if (! $role || ! array_key_exists($role, UserAccessResource::assignableRoles())) {
                        Notification::make()->danger()->title('권한 부족')
                            ->body('해당 역할을 부여할 권한이 없습니다.')->send();

                        return;
                    }

                    $employee = Employee::create([
                        'name' => $data['full_name'],
                        'email' => $data['email'],
                        'company_id' => $data['company_id'] ?? null,
                        'site_id' => $data['site_id'] ?? null,
                        'team_id' => $data['team_id'] ?? null,
                        'employment_status' => 'active',
                    ]);

                    try {
                        app(AccessAccountProvisioner::class)->grant($employee, $role, $data['access_scope'] ?? 'self');
                    } catch (\Throwable $e) {
                        Notification::make()->danger()->title('계정 생성 실패')->body($e->getMessage())->send();

                        return;
                    }

                    // ③ 등록 후 로그인 안내.
                    Notification::make()->success()->persistent()
                        ->title('등록 완료 — ' . $employee->name)
                        ->body('안내: ' . $data['email'] . ' 구글 계정으로 ' . route('login')
                            . ' 에서 로그인하면 됩니다. (권한: ' . (User::ROLE_OPTIONS[$role] ?? $role) . ')')
                        ->send();
                }),
            // 계정 없이 인사 기록만 만들고 싶을 때.
            CreateAction::make()->label('직원만 등록 (계정 없음)'),
        ];
    }
}
