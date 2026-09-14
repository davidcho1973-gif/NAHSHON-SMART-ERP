<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\MemberRegistration;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 현장 QR 간편 등록 — 네 가지만 받는다.
 *
 * ── 왜 나눴나 ──────────────────────────────────────────────────────────
 * 등록 양식은 여섯 단짜리 입사지원서였다(기본정보·지원정보·신분증·자격증·경력·동의).
 * 현장 입구에서 장갑 낀 손으로 그것을 채우라고 하면 그 자리에서 포기하고, 등록 안 된
 * 사람이 현장에 들어간다 — 서류를 다 받으려다 아무것도 못 받는다.
 *
 * 그래서 QR 로 들어온 사람에게는 <b>이름 · 전화 · 직책 · 동의 서명</b> 네 가지만 묻는다.
 * 나머지는 <b>지운 것이 아니라 미룬 것</b>이다. 사무실이 나중에 받는다.
 *
 * ── 여기서 지키는 것 ───────────────────────────────────────────────────
 * 두 경로가 <b>서로 섞이면 안 된다.</b> 실제로 한 번 뒤집어 붙였다 — 간편 등록은 그대로
 * 무겁고, 전체 지원서가 네 칸만 받게 됐다. 화면만 보면 둘 다 «되는» 것처럼 보여서
 * 눈으로는 못 잡는다. 그래서 두 경로를 각각 시험으로 묶는다.
 */
class QuickSiteRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Site
    {
        $company = Company::query()->create([
            'code' => 'OWN', 'name' => 'ERP', 'legal_name' => 'ERP LLC',
            'company_type' => Company::TYPE_OWN, 'status' => 'active',
        ]);

        return Site::query()->create([
            'company_id' => $company->id, 'code' => 'S-1', 'name' => 'Test Site',
            'country' => 'US', 'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
    }

    /** @return array<string, string> 네 가지 — 이것이 간편 등록의 전부다. */
    private function fourThings(): array
    {
        return [
            'preferred_language' => 'es',
            'last_name' => 'Ramirez',
            'first_name' => 'Luis',
            'phone' => '+1 602 555 0123',
            'role' => 'Welder',
            'privacy_consent' => '1',
            'applicant_signature' => 'Luis Ramirez',
            'signed_on' => '2026-09-14',
        ];
    }

    public function test_four_things_are_enough_to_register(): void
    {
        $site = $this->site();

        $this->post("/member/site/{$site->id}/apply", $this->fourThings())->assertOk();

        $registration = MemberRegistration::query()->latest('id')->first();
        $this->assertNotNull($registration, '네 가지만으로도 등록이 남아야 한다.');
        $this->assertSame('+1 602 555 0123', $registration->phone);
        $this->assertSame('Welder', $registration->role);
        $this->assertNotEmpty($registration->applicant_code, '지원자 코드가 있어야 사무실이 찾을 수 있다.');

        // 미룬 칸들은 비어 있는 채로 저장된다 — 저장이 막히면 안 된다.
        $this->assertNull($registration->email);
        $this->assertNull($registration->emergency_contact_name);
    }

    public function test_the_quick_screen_asks_only_for_those_four(): void
    {
        $site = $this->site();

        $html = $this->get("/member/site/{$site->id}/apply?lang=ko")->assertOk()->getContent();

        // 묻는 것
        $this->assertStringContainsString('name="first_name"', $html);
        $this->assertStringContainsString('name="phone"', $html);
        $this->assertStringContainsString('name="role"', $html);
        $this->assertStringContainsString('name="applicant_signature"', $html);

        // 미루는 것 — 화면에 아예 없어야 한다. required 인 칸이 숨어 있으면
        // 브라우저가 «채우라» 며 제출을 막는데, 사람은 그 칸을 볼 수 없다.
        foreach (['name="email"', 'name="emergency_contact_name"', 'name="identity_front"',
            'name="certifications[]"', 'name="hoffman_experience"', 'name="date_of_birth"'] as $field) {
            $this->assertStringNotContainsString($field, $html, "간편 등록에 {$field} 가 남아 있다.");
        }
    }

    public function test_it_says_what_can_wait(): void
    {
        // 무엇을 «안 해도 되는지» 를 안 알려 주면, 빠진 칸을 찾느라 화면을 훑는다.
        $site = $this->site();

        $this->get("/member/site/{$site->id}/apply?lang=ko")->assertOk()
            ->assertSee('간편 등록', false)
            ->assertSee('나중에', false);

        $this->get("/member/site/{$site->id}/apply?lang=es")->assertOk()
            ->assertSee('Registro rápido', false)
            ->assertSee('después', false);

        $this->get("/member/site/{$site->id}/apply?lang=en")->assertOk()
            ->assertSee('Quick Registration', false)
            ->assertSee('later', false);
    }

    public function test_after_submitting_it_still_reads_as_quick_registration(): void
    {
        // 네 칸만 채운 사람에게 «입사지원서가 제출되었습니다» 가 뜨면, 자기가 무엇을
        // 낸 건지 헷갈린다. 저장 뒤 화면도 같은 모드여야 한다.
        $site = $this->site();

        $this->post("/member/site/{$site->id}/apply", $this->fourThings())
            ->assertOk()
            ->assertSee('Registro rápido', false)
            ->assertDontSee('Solicitud de empleo', false);
    }

    public function test_the_full_application_still_asks_for_everything(): void
    {
        // 간편 등록을 넣으면서 전체 지원서까지 가볍게 만들어 버리면, 진짜 채용할 때
        // 받아야 할 서류가 조용히 사라진다. 실제로 한 번 반대로 붙였던 자리다.
        $site = $this->site();
        $registration = MemberRegistration::query()->create([
            'company_id' => $site->company_id, 'site_id' => $site->id,
            'member_type' => 'worker', 'status' => 'pending',
            'full_name' => '', 'preferred_language' => 'ko',
        ]);

        $html = $this->get("/member/register/{$registration->invite_token}")->assertOk()->getContent();

        $this->assertStringContainsString('입사지원서', $html);
        foreach (['name="email"', 'name="emergency_contact_name"', 'name="identity_front"',
            'name="certifications[]"', 'name="hoffman_experience"'] as $field) {
            $this->assertStringContainsString($field, $html, "전체 지원서에서 {$field} 가 사라졌다.");
        }
    }

    public function test_the_full_application_still_refuses_a_four_field_submission(): void
    {
        // 규칙 쪽도 같이 지킨다. 화면만 무겁고 검사는 가벼우면 빈 서류가 통과한다.
        $site = $this->site();
        $registration = MemberRegistration::query()->create([
            'company_id' => $site->company_id, 'site_id' => $site->id,
            'member_type' => 'worker', 'status' => 'pending',
            'full_name' => '', 'preferred_language' => 'ko',
        ]);

        $this->post("/member/register/{$registration->invite_token}", $this->fourThings())
            ->assertSessionHasErrors(['email', 'emergency_contact_name']);
    }

    public function test_consent_and_signature_are_never_optional(): void
    {
        // 개인정보를 저장하려면 동의가 있어야 한다. 가볍게 만든다고 이것까지 빼면
        // 빠르기는 한데 남겨서는 안 될 것을 남기게 된다.
        $site = $this->site();

        $this->post("/member/site/{$site->id}/apply", array_merge($this->fourThings(), [
            'privacy_consent' => '', 'applicant_signature' => '',
        ]))->assertSessionHasErrors(['privacy_consent', 'applicant_signature']);

        $this->assertSame(0, MemberRegistration::query()->count());
    }

    public function test_name_phone_and_trade_are_still_required(): void
    {
        $site = $this->site();

        $this->post("/member/site/{$site->id}/apply", [
            'preferred_language' => 'es', 'privacy_consent' => '1',
            'applicant_signature' => 'X', 'signed_on' => '2026-09-14',
        ])->assertSessionHasErrors(['first_name', 'last_name', 'phone', 'role']);
    }
}
