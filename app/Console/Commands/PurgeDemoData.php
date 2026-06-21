<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\MemberRegistration;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurgeDemoData extends Command
{
    protected $signature = 'smart:purge-demo
        {--prefix=MR-DEMO : 삭제 대상 registration_number 접두사}
        {--force : 실제로 삭제(미지정 시 dry-run으로 목록만 표시)}';

    protected $description = '데모/가짜 회원등록과 그로부터 파생된 직원·계정·서류를 안전하게 삭제한다. 기본은 dry-run.';

    public function handle(): int
    {
        $prefix = (string) $this->option('prefix');
        $force = (bool) $this->option('force');

        $registrations = MemberRegistration::query()
            ->where('registration_number', 'like', $prefix.'%')
            ->get();

        if ($registrations->isEmpty()) {
            $this->info("접두사 '{$prefix}' 에 해당하는 데모 회원등록이 없습니다.");

            return self::SUCCESS;
        }

        $rows = $registrations->map(function (MemberRegistration $r): array {
            $email = $r->email ? Str::lower($r->email) : null;

            return [
                $r->registration_number,
                $r->full_name,
                $r->employee_id ? "#{$r->employee_id}" : '-',
                $email && User::query()->where('email', $email)->exists() ? $email : '-',
                $r->documents()->count(),
            ];
        })->all();

        $this->table(
            ['Registration', 'Full name', 'Employee', 'Access account', 'Docs'],
            $rows
        );

        if (! $force) {
            $this->warn("DRY-RUN: {$registrations->count()}건이 삭제 대상입니다. 실제 삭제하려면 --force 를 붙이세요.");
            $this->line('예) php artisan smart:purge-demo --force');

            return self::SUCCESS;
        }

        $deleted = ['registrations' => 0, 'employees' => 0, 'users' => 0];

        DB::transaction(function () use ($registrations, &$deleted): void {
            foreach ($registrations as $registration) {
                if ($registration->email) {
                    $deleted['users'] += User::query()
                        ->where('email', Str::lower($registration->email))
                        ->delete();
                }

                if ($registration->employee_id) {
                    $deleted['employees'] += Employee::query()
                        ->whereKey($registration->employee_id)
                        ->delete();
                }

                // member_documents 는 FK cascadeOnDelete 로 함께 삭제된다.
                $registration->delete();
                $deleted['registrations']++;
            }
        });

        $this->info(sprintf(
            '삭제 완료 — 회원등록 %d, 직원 %d, 계정 %d (서류는 연쇄 삭제).',
            $deleted['registrations'],
            $deleted['employees'],
            $deleted['users'],
        ));

        return self::SUCCESS;
    }
}
