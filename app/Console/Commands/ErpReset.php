<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Support\Org;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 시험하며 쌓인 데이터를 비우고 처음부터 다시 시작한다.
 *
 * 시험 단계에서는 "지우고 다시" 가 자주 필요하다. 그때마다 사람이 화면을 돌며 하나씩
 * 지우면 반드시 뭔가 남는다 — 남은 것은 대개 조용해서, 다음 시험의 결과를 오염시킨
 * 뒤에야 드러난다. 한 번에, 빠짐없이, 같은 방식으로 지우는 길이 있어야 한다.
 *
 * 두 가지 깊이가 있다.
 *
 *   기본  업무 데이터만 — 출퇴근·직원·등록·문서·경비·급여. 현장·회사·프로젝트·품목과
 *         관리자 로그인은 남는다. 현장을 다시 만들지 않아도 되니 대개 이쪽이다.
 *   --all 전부 — 표를 통째로 다시 만든다(migrate:fresh). 현장부터 다시 만들어야 한다.
 *
 * 지울 목록은 "남길 것"만 적어서 만든다. 새 표가 생겼을 때 목록에 적어 두는 것을
 * 잊어도 지워지는 쪽이 안전하다 — 반대로 하면 새 표만 옛 데이터를 안고 남는다.
 */
class ErpReset extends Command
{
    protected $signature = 'erp:reset
        {--only= : 한 갈래만 지운다 (attendance)}
        {--all : 표를 통째로 다시 만든다(현장·회사까지 전부 사라진다)}
        {--force : 실제로 지운다. 없으면 무엇이 지워지는지만 보여준다}
        {--admin= : 초기화 뒤 최고 관리자로 둘 이메일(기본: 지금 있는 최고 관리자 그대로)}
        {--yes-i-am-sure-this-is-production : 운영 환경에서도 돌린다}';

    protected $description = '시험 데이터를 비우고 처음부터 다시 시작한다 (기본은 미리보기)';

    /**
     * 한 갈래만 지울 때 쓰는 목록.
     *
     * 여기만은 "지울 것" 을 적는다. 갈래를 고른 사람은 그 갈래만 사라지길 바라는데,
     * 새 표가 생겼다고 함께 지워지면 그건 고른 것과 다른 일이 벌어지는 것이다.
     *
     * 출퇴근에는 기록 본체만이 아니라 거기서 계산되는 것들이 딸려 있다. 근무시간을
     * 남겨 두면 기록은 없는데 급여에는 시간이 잡혀 있는 상태가 되고, GPS 재실 상태를
     * 남겨 두면 이미 퇴근한 사람이 아직 현장에 있는 것으로 남는다.
     *
     * @var array<string, list<string>>
     */
    private const GROUPS = [
        'attendance' => [
            'attendance_logs',        // 출퇴근 기록 본체
            'attendance_sessions',    // GPS 재실 상태(들어옴·나감)
            'attendance_geo_events',  // GPS 신호 원본
            'payroll_timesheets',     // 출퇴근에서 계산된 근무시간
        ],
    ];

    /**
     * 업무 데이터를 지울 때 남기는 표.
     *
     * 현장·회사·프로젝트·품목은 다시 만들기 번거롭고, 시험을 다시 하는 데 방해가
     * 되지 않는다. 세션을 남기는 것은 지금 로그인한 사람이 쫓겨나지 않게 하기 위해서다.
     *
     * @var list<string>
     */
    private const KEEP = [
        'migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches',
        'failed_jobs', 'password_reset_tokens', 'system_heartbeats',
        'org_settings', 'companies', 'sites', 'teams', 'projects',
        'item_categories', 'items', 'company_site',
    ];

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('yes-i-am-sure-this-is-production')) {
            $this->error('  운영 환경입니다. 정말 지우려면 --yes-i-am-sure-this-is-production 을 붙이세요.');

            return self::FAILURE;
        }

        $all = (bool) $this->option('all');
        $force = (bool) $this->option('force');
        $only = trim((string) $this->option('only'));

        if ($only !== '' && ! array_key_exists($only, self::GROUPS)) {
            $this->error('  모르는 갈래입니다: '.$only);
            $this->line('  쓸 수 있는 것: '.implode(', ', array_keys(self::GROUPS)));

            return self::FAILURE;
        }
        if ($only !== '' && $all) {
            $this->error('  --only 와 --all 은 같이 쓸 수 없습니다. 한 갈래만 지우려면 --only 만 쓰세요.');

            return self::FAILURE;
        }

        $this->line('');
        $this->line('  <options=bold>'.Org::name().'</> · '.app()->environment().' · '.(config('app.url') ?: '주소 없음'));
        $this->line('');

        $tables = $only !== '' ? $this->groupTables($only) : $this->tablesToClear($all);
        $counts = $this->rowCounts($tables);
        $total = array_sum($counts);

        $this->table(
            ['지울 표', '줄'],
            collect($counts)->filter()->map(fn (int $n, string $t): array => [$t, number_format($n)])->values()->all()
                ?: [['(비어 있음)', '0']]
        );

        $this->line('  모두 <options=bold>'.number_format($total).'</> 줄'
            .match (true) {
                $only !== '' => ' — 이 갈래만 지웁니다. 직원·현장·계정은 손대지 않습니다.',
                $all => ' — 표까지 다시 만듭니다(현장·회사도 사라집니다).',
                default => ' — 현장·회사·품목과 관리자 로그인은 남습니다.',
            });
        $this->line('');

        if (! $force) {
            $this->warn('  미리보기입니다. 아무것도 지우지 않았습니다.');
            $this->line('  실제로 지우려면: php artisan erp:reset'
                .($only !== '' ? ' --only='.$only : ($all ? ' --all' : '')).' --force');
            $this->line('');

            return self::SUCCESS;
        }

        // 지우기 전에 최고 관리자를 적어 둔다. 다 지운 뒤 아무도 못 들어오면
        // 그 배포는 화면으로는 되살릴 방법이 없다.
        $admins = User::query()->where('access_role', 'super_admin')
            ->get(['name', 'email'])->map(fn (User $u): array => ['name' => $u->name, 'email' => $u->email])->all();

        if ($only !== '') {
            // 계정도 회사도 건드리지 않았으므로 되살릴 것이 없다.
            $this->clear($tables);
            $this->line('');
            $this->info('  '.$only.' 기록을 비웠습니다. 직원·현장·계정은 그대로입니다.');
            $this->line('');

            return self::SUCCESS;
        }

        if ($all) {
            $this->call('migrate:fresh', ['--force' => true]);
        } else {
            $this->clear($tables);
        }

        $this->restoreAdmins($admins);
        $this->ensureOwnCompany();

        $this->line('');
        $this->info('  비웠습니다. 관리자 '.count($admins).'명은 그대로 로그인할 수 있습니다.');
        $this->line('  올려 둔 파일(배지 사진·문서)은 저장소에 남아 있습니다 — 표에서만 사라졌습니다.');
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * 갈래에 적힌 표 중 실제로 있는 것만.
     *
     * @return list<string>
     */
    private function groupTables(string $group): array
    {
        return collect(self::GROUPS[$group])
            ->filter(fn (string $t): bool => Schema::hasTable($t))
            ->values()->all();
    }

    /**
     * @return list<string>
     */
    private function tablesToClear(bool $all): array
    {
        $tables = collect(Schema::getTableListing())
            ->map(fn (string $t): string => str_contains($t, '.') ? substr($t, (int) strrpos($t, '.') + 1) : $t)
            ->unique();

        return $tables
            ->reject(fn (string $t): bool => $t === 'migrations' || (! $all && in_array($t, self::KEEP, true)))
            ->values()->all();
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, int>
     */
    private function rowCounts(array $tables): array
    {
        $out = [];
        foreach ($tables as $t) {
            try {
                $out[$t] = (int) DB::table($t)->count();
            } catch (\Throwable) {
                $out[$t] = 0;
            }
        }
        arsort($out);

        return $out;
    }

    /**
     * @param  list<string>  $tables
     */
    private function clear(array $tables): void
    {
        if ($tables === []) {
            return;
        }

        // TRUNCATE ... CASCADE 를 쓰지 않는다.
        //
        // 처음에는 그렇게 짰다가 현장이 통째로 사라졌다. sites 가 employees 를
        // 참조하고 있어서, employees 를 비우면 CASCADE 가 sites 까지 따라간다 —
        // 참조하는 줄이 하나도 없어도 표를 통째로 비운다. "현장은 남습니다" 라고
        // 말해 놓고 사라지는 것이 가장 나쁜 결과다.
        //
        // 그래서 DELETE 로 지운다. 서로 참조하는 표는 순서가 맞아야 지워지는데,
        // 그 순서를 미리 계산하는 대신 여러 번 돌린다 — 이번에 막힌 표는 다음
        // 바퀴에 자식이 비워진 뒤 지워진다. 더 이상 줄어들지 않으면 멈춘다.
        $remaining = $tables;

        for ($pass = 0; $pass < 12 && $remaining !== []; $pass++) {
            $stuck = [];
            foreach ($remaining as $table) {
                try {
                    DB::table($table)->delete();
                } catch (\Throwable) {
                    $stuck[] = $table;
                }
            }

            if (count($stuck) === count($remaining)) {
                break;  // 한 바퀴 돌았는데 하나도 못 지웠다 — 더 해도 같다
            }
            $remaining = $stuck;
        }

        // 조용히 남기지 않는다. 남은 표가 있으면 다음 시험이 옛 데이터 위에서 돌아간다.
        if ($remaining !== []) {
            $this->warn('  지우지 못한 표: '.implode(', ', $remaining));
        }
    }

    /**
     * @param  list<array{name: string, email: string}>  $admins
     */
    private function restoreAdmins(array $admins): void
    {
        $wanted = trim((string) $this->option('admin'));
        if ($wanted !== '') {
            $admins[] = ['name' => strtok($wanted, '@') ?: $wanted, 'email' => $wanted];
        }

        foreach ($admins as $admin) {
            User::query()->updateOrCreate(
                ['email' => mb_strtolower($admin['email'])],
                [
                    'name' => $admin['name'],
                    'password' => bcrypt(bin2hex(random_bytes(16))),
                    'access_role' => 'super_admin',
                    'access_scope' => 'all_sites',
                    'account_status' => 'active',
                    'email_verified_at' => now(),
                ]
            );
        }
    }

    /** 자사 회사가 없으면 직원 등록에서 소속 회사를 고를 수 없다. */
    private function ensureOwnCompany(): void
    {
        Company::query()->updateOrCreate(
            ['code' => Org::code()],
            [
                'name' => Org::name(),
                'legal_name' => Org::legalName(),
                'company_type' => Company::TYPE_OWN,
                'status' => 'active',
            ]
        );
    }
}
