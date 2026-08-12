<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\W9Form;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * W-9 작성 — 간편 등록에 이어 서명된 링크로 작성한다(1099 지급 전제조건).
 * TIN 은 암호화 저장되고 화면에는 뒤 4자리만 보인다.
 */
class W9FormTest extends TestCase
{
    use RefreshDatabase;

    private Employee $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->emp = Employee::create(['name' => 'Carlos Ramirez', 'employee_number' => 'E-001', 'employment_status' => 'active']);
    }

    private function validPayload(): array
    {
        return [
            'legal_name' => 'Carlos Ramirez',
            'tax_classification' => 'individual',
            'address' => '1234 W Main St',
            'city_state_zip' => 'Phoenix, AZ 85001',
            'tin_type' => 'ssn',
            'tin' => '123-45-6789',
            'signature_name' => 'Carlos Ramirez',
            'certify' => '1',
        ];
    }

    public function test_unsigned_url_is_rejected(): void
    {
        $this->get('/w9/'.$this->emp->id)->assertStatus(403);
        $this->post('/w9/'.$this->emp->id, $this->validPayload())->assertStatus(403);
    }

    public function test_signed_link_opens_form_with_prefilled_name(): void
    {
        $res = $this->get(URL::signedRoute('w9.show', ['employee' => $this->emp->id]));

        $res->assertStatus(200);
        $res->assertSee('Form W-9');
        $res->assertSee('Carlos Ramirez');
        $res->assertSee('Certification');
    }

    public function test_submit_stores_encrypted_tin_and_shows_only_last4(): void
    {
        $res = $this->post(URL::signedRoute('w9.store', ['employee' => $this->emp->id]), $this->validPayload());

        $res->assertRedirect();

        $form = W9Form::firstOrFail();
        $this->assertSame($this->emp->id, $form->employee_id);
        $this->assertSame('123456789', $form->tin, '복호화하면 서식 문자 없는 9자리가 나와야 한다');
        $this->assertSame('6789', $form->tin_last4);
        $this->assertNotNull($form->certified_at);

        // DB 원문에는 평문 TIN 이 없어야 한다.
        $raw = (string) DB::table('w9_forms')->value('tin');
        $this->assertStringNotContainsString('123456789', $raw);

        // 완료 화면 — 마스킹된 TIN 만 노출.
        $done = $this->get($res->headers->get('Location'));
        $done->assertStatus(200)->assertSee('***-**-6789')->assertDontSee('123456789');
    }

    public function test_tin_must_be_nine_digits_and_certification_is_required(): void
    {
        $this->post(URL::signedRoute('w9.store', ['employee' => $this->emp->id]), array_merge($this->validPayload(), ['tin' => '123-45']))
            ->assertSessionHasErrors(['tin']);

        $this->post(URL::signedRoute('w9.store', ['employee' => $this->emp->id]), array_merge($this->validPayload(), ['certify' => null]))
            ->assertSessionHasErrors(['certify']);

        $this->assertSame(0, W9Form::count());
    }

    public function test_resubmission_replaces_the_previous_form(): void
    {
        $url = URL::signedRoute('w9.store', ['employee' => $this->emp->id]);
        $this->post($url, $this->validPayload());
        $this->post($url, array_merge($this->validPayload(), ['tin' => '987-65-4321', 'tin_type' => 'ein']));

        $this->assertSame(1, W9Form::count(), '직원당 유효 W-9 는 한 장 — 재제출은 덮어쓴다');
        $this->assertSame('4321', W9Form::firstOrFail()->tin_last4);
    }

    public function test_quick_registration_done_screen_links_to_w9(): void
    {
        $site = Site::create(['code' => 'AZ-01', 'name' => 'Arizona Site', 'timezone' => 'America/Phoenix', 'status' => 'active']);
        $company = Company::create(['code' => 'C1', 'name' => '대한설비', 'status' => 'active', 'company_type' => Company::TYPE_PARTNER]);

        $res = $this->post('/join/w/'.$site->id, [
            'full_name' => 'Carlos Ramirez', 'company_id' => $company->id, 'role' => 'Electrician',
            'email' => 'carlos@example.com', 'phone' => '480-555-0100',
        ]);

        $res->assertStatus(200);
        $res->assertSee('/w9/', false);      // 등록 완료 화면에서 바로 W-9 로 이어진다
        $res->assertSee('signature=', false); // 서명된 링크여야 한다
    }
}
