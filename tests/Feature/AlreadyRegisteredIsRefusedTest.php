<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 이미 등록된 사람은 다시 등록되지 않는다 — 이름이나 번호 <b>하나만</b> 같아도 막는다.
 *
 * 사장님 지시(2026-09-23). 예전에는 번호만 봤고, 그래서 같은 사람이 번호를 다르게
 * 적으면(오타·새 번호·회사 폰) 명단에 두 줄로 섰다. 두 줄이 되는 순간 출역 인원이
 * 부풀고 그 사람의 근무가 갈려, 급여를 뽑는 날 한쪽이 통째로 빠진다.
 */
class AlreadyRegisteredIsRefusedTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['code' => 'OWN', 'name' => 'ABC MEP', 'status' => 'active', 'company_type' => Company::TYPE_OWN]);
        $this->site = Site::create([
            'code' => 'AZ-01', 'name' => 'Arizona Site', 'company_id' => $company->id,
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);

        Employee::create([
            'company_id' => $company->id, 'site_id' => $this->site->id,
            'name' => '이대웅', 'phone' => '480-555-0142', 'employment_status' => 'active',
        ]);
    }

    private function register(string $name, string $phone): TestResponse
    {
        return $this->post('/join/w/'.$this->site->id, ['full_name' => $name, 'phone' => $phone]);
    }

    public function test_the_same_phone_is_refused_even_under_a_different_name(): void
    {
        $this->register('다른 사람', '(480) 555-0142')->assertSessionHasErrors('phone');

        $this->assertSame(1, Employee::query()->count());
    }

    public function test_the_same_name_is_refused_even_with_a_different_phone(): void
    {
        $this->register('이대웅', '480-555-0199')->assertSessionHasErrors('full_name');

        $this->assertSame(1, Employee::query()->count(), '한 사람이 두 줄로 서면 급여가 갈린다');
    }

    /** 표기가 달라도 같은 이름은 같은 이름이다 — 공백·대소문자로 빠져나갈 수 없다. */
    public function test_spacing_and_case_do_not_open_a_second_row(): void
    {
        Employee::query()->first()->update(['name' => 'Miguel Torres']);

        foreach (['  miguel   torres ', 'MIGUEL TORRES', 'Miguel  Torres'] as $variant) {
            $this->register($variant, '480-555-02'.random_int(10, 99))->assertSessionHasErrors('full_name');
        }

        $this->assertSame(1, Employee::query()->count());
    }

    /** 막을 때는 무엇 때문에 막혔는지 말해 준다 — 모르면 그 자리에서 포기한다. */
    public function test_the_refusal_says_what_to_do_next(): void
    {
        $this->register('이대웅', '480-555-0199')
            ->assertSessionHasErrorsIn('default', ['full_name'])
            ->assertSessionHas('errors', function ($errors) {
                $message = $errors->first('full_name');

                return str_contains($message, '뒷 4자리')
                    && str_contains($message, '인사담당자')
                    && str_contains($message, 'HR')
                    && str_contains($message, 'Recursos Humanos');
            });
    }

    public function test_a_genuinely_new_person_still_gets_in(): void
    {
        $this->register('박신규', '480-555-0177')->assertOk();

        $this->assertSame(2, Employee::query()->count());
    }
}
