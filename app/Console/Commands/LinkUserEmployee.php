<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class LinkUserEmployee extends Command
{
    protected $signature = 'attendance:link-self
        {email? : 연동할 사용자 이메일(미지정 시 employee_id가 없는 모든 사용자 대상)}
        {--force : dry-run 없이 실제로 생성·연동}';

    protected $description = '로그인 계정(User)을 본인 Employee 레코드에 연동한다. Employee가 없으면 생성한다. 출퇴근(내 출퇴근) 기록 전제 조건. 기본은 dry-run.';

    public function handle(): int
    {
        $email = $this->argument('email');
        $force = (bool) $this->option('force');

        $query = User::query()->whereNull('employee_id');
        if ($email) {
            $query->where('email', $email);
        }
        $users = $query->get();

        if ($users->isEmpty()) {
            $this->info($email
                ? "이메일 '{$email}' 사용자가 없거나 이미 직원과 연동되어 있습니다."
                : '연동이 필요한(employee_id 없는) 사용자가 없습니다.');

            return self::SUCCESS;
        }

        if (! $force) {
            $this->warn('[DRY-RUN] 아래 사용자에 대해 Employee 생성·연동을 수행합니다. 실제 적용하려면 --force 를 붙이세요.');
        }

        foreach ($users as $user) {
            $employeeNumber = 'EMP-U'.$user->id;
            $name = $user->name ?: ('User '.$user->id);

            $this->line("- #{$user->id} {$name} <{$user->email}> → employee_number={$employeeNumber}");

            if (! $force) {
                continue;
            }

            // 멱등: 같은 employee_number가 있으면 재사용한다.
            $employee = Employee::firstOrCreate(
                ['employee_number' => $employeeNumber],
                [
                    'name' => $name,
                    'email' => $user->email,
                    'employment_status' => 'active',
                    'company_id' => $user->allowed_company_id,
                    'site_id' => $user->allowed_site_id,
                    'team_id' => $user->allowed_team_id,
                    'start_date' => now()->toDateString(),
                    'role' => Str::headline($user->access_role ?: 'user'),
                ]
            );

            $user->forceFill(['employee_id' => $employee->id])->save();
            $this->info("  ✓ 연동 완료 (employee_id={$employee->id})");
        }

        if (! $force) {
            $this->newLine();
            $this->warn('실제 적용: php artisan attendance:link-self '.($email ? $email.' ' : '').'--force');
        } else {
            $this->newLine();
            $this->info('완료. 이제 해당 계정으로 "내 출퇴근" 출근/퇴근 기록이 가능합니다.');
        }

        return self::SUCCESS;
    }
}
