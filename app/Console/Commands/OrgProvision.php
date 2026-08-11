<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\OrgSetting;
use App\Models\User;
use App\Support\Org;
use Illuminate\Console\Command;

/**
 * 새 고객 배포를 세우는 명령.
 *
 * 이 명령이 있는 이유는 하나다 — 새 고객을 받을 때마다 사람이 기억에 의존해
 * 여러 화면을 돌면, 언젠가 한 단계를 빠뜨린다. 빠뜨린 단계는 대개 조용해서,
 * 고객이 쓰기 시작한 다음에야 드러난다(자사 회사가 없어서 직원 등록이 막히거나,
 * 최고 관리자가 없어서 아무도 설정을 못 고치거나).
 *
 * 여러 번 돌려도 안전하다. 이미 있는 것은 건드리지 않는다 — 두 번째 실행이
 * 위험하면 아무도 다시 안 돌리게 되고, 그러면 확인 수단이 없어진다.
 *
 *     php artisan org:provision --admin=admin@example.com
 *
 * 회사 이름은 코드가 아니라 환경변수(ORG_NAME)에서 온다. 이름을 인자로 받지
 * 않는 것은 일부러다 — 명령으로 넣은 이름은 재배포하면 사라진다.
 */
class OrgProvision extends Command
{
    protected $signature = 'org:provision
        {--admin= : 최고 관리자로 만들 구글 로그인 이메일}
        {--dry-run : 무엇을 할지만 보여주고 아무것도 바꾸지 않는다}';

    protected $description = '새 고객 배포를 세운다 (자사 회사 · 최고 관리자 · 설정 확인)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $this->line('');
        $this->line('  이 배포는 <options=bold>'.Org::name().'</> 의 것입니다.');
        $this->line('  자사 코드 '.Org::code().'   ·   주소 '.(config('app.url') ?: '(APP_URL 없음)'));
        $this->line('');

        if (! config('org.configured')) {
            // 기본값 그대로 서 있다는 뜻이다. 이 상태로 고객에게 열면 남의 회사
            // 이름이 화면·이메일·인쇄물에 그대로 나간다.
            $this->warn('  회사 이름이 기본값입니다. 환경변수 ORG_NAME 을 설정하세요.');
            $this->line('');
        }

        $steps = [];

        // ① 자사 회사 — 이게 없으면 직원 등록에서 소속 회사를 고를 수 없다.
        $own = Company::query()->where('code', Org::code())->first();
        if ($own) {
            $steps[] = ['자사 회사', '이미 있음', $own->name];
        } else {
            $steps[] = ['자사 회사', $dry ? '만들 예정' : '만듦', Org::name()];
            if (! $dry) {
                Company::query()->create([
                    'code' => Org::code(),
                    'name' => Org::name(),
                    'legal_name' => Org::legalName(),
                    'company_type' => Company::TYPE_OWN,
                    'status' => 'active',
                ]);
            }
        }

        // ② 최고 관리자 — 아무도 없으면 설정을 고칠 사람이 없다.
        $email = trim((string) $this->option('admin'));
        $existing = User::query()->where('access_role', 'super_admin')->count();

        if ($email !== '') {
            $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();
            if ($user) {
                $steps[] = ['최고 관리자', $dry ? '올릴 예정' : '올림', $user->email];
                if (! $dry) {
                    $user->forceFill([
                        'access_role' => 'super_admin',
                        'access_scope' => 'all_sites',
                        'account_status' => 'active',
                    ])->save();
                }
            } else {
                $steps[] = ['최고 관리자', $dry ? '만들 예정' : '만듦', $email];
                if (! $dry) {
                    User::query()->create([
                        'name' => strtok($email, '@') ?: $email,
                        'email' => mb_strtolower($email),
                        'password' => bcrypt(bin2hex(random_bytes(16))),
                        'access_role' => 'super_admin',
                        'access_scope' => 'all_sites',
                        'account_status' => 'active',
                    ]);
                }
            }
        } elseif ($existing === 0) {
            $steps[] = ['최고 관리자', '없음', '--admin=이메일 을 주세요'];
        } else {
            $steps[] = ['최고 관리자', '이미 있음', $existing.'명'];
        }

        // ③ 화면에서 고친 설정이 남아 있는지. 복제한 데이터베이스로 새 고객을
        //    세우면 옛 고객의 이름이 표에 남아 환경변수를 덮어쓴다 — 가장 조용한 사고다.
        $stored = OrgSetting::query()->pluck('value', 'key')->all();
        if ($stored !== []) {
            $steps[] = ['화면에서 고친 설정', count($stored).'건 있음', implode(', ', array_keys($stored))];
        } else {
            $steps[] = ['화면에서 고친 설정', '없음 (환경변수 사용)', ''];
        }

        $this->table(['항목', '상태', '값'], $steps);

        if ($dry) {
            $this->line('');
            $this->comment('  --dry-run 이라 아무것도 바꾸지 않았습니다.');
        }

        if ($stored !== []) {
            $this->line('');
            $this->warn('  화면에서 고친 설정이 표에 남아 있습니다. 다른 고객의 데이터베이스를');
            $this->warn('  복제해 왔다면 그 값이 환경변수보다 우선합니다 — 확인하세요.');
        }

        $this->line('');

        return self::SUCCESS;
    }
}
