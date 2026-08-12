<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\Org;
use Illuminate\Console\Command;

/**
 * 이미 쓰고 있는 배포의 회사 이름을 바꾼다 — 데이터는 그대로 두고.
 *
 * 처음 세울 때 정한 이름이 나중에 바뀐다. 사명이 바뀌기도 하고, 시험용으로 세운 것이
 * 그대로 진짜 고객 것이 되기도 한다. 그때 데이터를 지우고 다시 세우는 것은 답이 아니다 —
 * 이미 쌓인 출퇴근과 급여가 그 회사의 실제 기록이기 때문이다.
 *
 * 화면에 보이는 이름(조직 설정)만 바꾸면 절반만 바뀐다. companies 표의 자사 한 줄은
 * 옛 이름 그대로 남아, 직원 목록의 "소속 회사" 칸에 계속 옛 이름이 뜬다. 두 곳이
 * 어긋난 채로 굴러가면 나중에 어느 쪽이 맞는지 아무도 모른다.
 *
 *     php artisan org:rename            무엇이 어떻게 바뀌는지만 보여준다
 *     php artisan org:rename --force    실제로 바꾼다
 *
 * 자사가 무엇인지 헷갈릴 수 있어서, 고르기 전에 회사 목록을 통째로 보여 준다.
 */
class OrgRename extends Command
{
    protected $signature = 'org:rename
        {--company= : 자사로 삼을 회사(코드 또는 id). 안 주면 지금 자사로 표시된 회사}
        {--force : 실제로 바꾼다. 없으면 무엇이 바뀌는지만 보여준다}';

    protected $description = '자사 회사 이름을 설정값에 맞춘다 (데이터는 지우지 않는다)';

    public function handle(): int
    {
        $this->line('');
        $this->line('  설정에 적힌 이름:  <options=bold>'.Org::name().'</>   (코드 '.Org::code().')');
        $this->line('');

        $companies = Company::query()->orderBy('id')->get();
        if ($companies->isEmpty()) {
            $this->error('  회사가 하나도 없습니다. php artisan org:provision 을 먼저 돌리세요.');

            return self::FAILURE;
        }

        $this->line('  <options=bold>지금 들어 있는 회사</>');
        $this->table(
            ['id', '코드', '이름', '구분', '직원'],
            $companies->map(fn (Company $c): array => [
                $c->id,
                $c->code,
                $c->name,
                Company::COMPANY_TYPES[$c->company_type] ?? $c->company_type,
                $c->employees()->count(),
            ])->all()
        );

        $target = $this->pickCompany($companies);
        if (! $target) {
            return self::FAILURE;
        }

        $changes = array_filter([
            '이름' => $target->name !== Org::name() ? [$target->name, Org::name()] : null,
            '코드' => $target->code !== Org::code() ? [$target->code, Org::code()] : null,
            '법인명' => $target->legal_name !== Org::legalName() ? [$target->legal_name, Org::legalName()] : null,
            '구분' => $target->company_type !== Company::TYPE_OWN
                ? [Company::COMPANY_TYPES[$target->company_type] ?? $target->company_type, '자사 (직접고용)']
                : null,
        ]);

        if ($changes === []) {
            $this->info('  이미 맞습니다. 바꿀 것이 없습니다.');
            $this->line('');

            return self::SUCCESS;
        }

        $this->line('  <options=bold>#'.$target->id.' 을(를) 이렇게 바꿉니다</>');
        $this->table(
            ['항목', '지금', '바뀔 값'],
            array_map(fn (string $k, array $v): array => [$k, $v[0] ?: '(없음)', $v[1]], array_keys($changes), $changes)
        );

        // 코드가 겹치면 자사가 둘이 된다. 그때부터 어느 쪽이 자사인지 코드로는 못 가린다.
        $clash = Company::query()->where('code', Org::code())->whereKeyNot($target->id)->first();
        if ($clash) {
            $this->error('  코드 '.Org::code().' 를 쓰는 회사가 이미 있습니다 (#'.$clash->id.' '.$clash->name.').');
            $this->line('  ORG_CODE 를 다른 값으로 두거나, 그 회사를 먼저 정리하세요.');

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->warn('  미리보기입니다. 아무것도 바꾸지 않았습니다.');
            $this->line('  실제로 바꾸려면 --force 를 붙이세요. 직원·출퇴근·급여는 그대로 있습니다.');
            $this->line('');

            return self::SUCCESS;
        }

        $target->forceFill([
            'name' => Org::name(),
            'code' => Org::code(),
            'legal_name' => Org::legalName(),
            'company_type' => Company::TYPE_OWN,
        ])->save();

        // 자사가 둘이면 직원의 고용 구분이 회사에 따라 갈릴 때 어느 쪽을 볼지 모른다.
        $others = Company::query()->where('company_type', Company::TYPE_OWN)->whereKeyNot($target->id)->get();
        foreach ($others as $other) {
            $this->warn('  #'.$other->id.' '.$other->name.' 도 자사로 표시돼 있습니다 — 협력사로 내렸습니다.');
            $other->forceFill(['company_type' => Company::TYPE_PARTNER])->save();
        }

        $this->line('');
        $this->info('  바꿨습니다. 직원·출퇴근·급여는 그대로입니다.');
        $this->line('  화면 이름은 새로고침하면 바뀝니다.');
        $this->line('');

        return self::SUCCESS;
    }

    private function pickCompany(\Illuminate\Support\Collection $companies): ?Company
    {
        $wanted = trim((string) $this->option('company'));

        if ($wanted !== '') {
            $found = $companies->first(fn (Company $c): bool => (string) $c->id === $wanted
                || mb_strtolower((string) $c->code) === mb_strtolower($wanted));

            if (! $found) {
                $this->error('  그런 회사가 없습니다: '.$wanted);

                return null;
            }

            return $found;
        }

        $own = $companies->where('company_type', Company::TYPE_OWN);

        if ($own->count() === 1) {
            return $own->first();
        }

        // 자사가 없거나 여럿이면 사람이 골라야 한다. 여기서 아무거나 집으면
        // 남의 회사 이름이 우리 회사 이름으로 덮인다.
        $this->error($own->isEmpty()
            ? '  자사로 표시된 회사가 없습니다.'
            : '  자사로 표시된 회사가 '.$own->count().'개입니다.');
        $this->line('  위 목록에서 하나를 골라 주세요:  php artisan org:rename --company=<코드 또는 id>');

        return null;
    }
}
