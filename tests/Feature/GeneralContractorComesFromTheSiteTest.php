<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\MemberRegistration;
use App\Models\Site;
use App\Services\GeminiBadgeAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * 원청사는 <b>현장 속성</b>이지 코드에 적을 것이 아니다.
 *
 * ── 무엇이 잘못돼 있었나 ───────────────────────────────────────────────
 * 한 원청사의 이름이 소스에 글자 그대로 박혀 있었다. 세 자리다.
 *   · 등록 양식의 「그 회사 현장 근무 경험 여부」 — <b>필수</b> 질문
 *   · 작업자가 서명하는 개인정보 수집·이용 동의문
 *   · 배지 사진 판독 지시문 («그 회사 로고 아래 빨간 글씨가 회사 이름이다»)
 *
 * 문제는 둘이다.
 *   ① <b>내용이 틀린다.</b> 다른 원청사 현장에 등록하는 사람도 평생 가 본 적 없는
 *      회사의 경험을 필수로 답해야 했고, 그 회사 이름이 적힌 동의문에 서명했다.
 *      배지 판독도 그 회사 배지 생김새를 기준으로 삼아 다른 배지에서는 엉뚱한
 *      칸을 회사 이름으로 읽는다.
 *   ② <b>공개 저장소에 거래처 이름이 남는다.</b> 검색만 하면 이 회사가 어느
 *      원청사 현장에서 일하는지 그대로 보인다.
 *
 * ── 어디서 와야 하나 ───────────────────────────────────────────────────
 * 이미 자리가 있다 — sites.client_company_id (그 현장의 발주처/원청). 현장에
 * 적힌 것을 읽고, 안 적혀 있으면 이름 없이 «원청사» 라고만 쓴다.
 */
class GeneralContractorComesFromTheSiteTest extends TestCase
{
    use RefreshDatabase;

    private Company $own;

    protected function setUp(): void
    {
        parent::setUp();

        $this->own = Company::query()->create([
            'code' => 'OWN', 'name' => 'ERP', 'legal_name' => 'ERP LLC',
            'company_type' => Company::TYPE_OWN, 'status' => 'active',
        ]);
    }

    private function site(?string $generalContractor): Site
    {
        $client = $generalContractor === null ? null : Company::query()->create([
            'code' => 'GC-'.mt_rand(1000, 9999), 'name' => $generalContractor,
            'legal_name' => $generalContractor, 'company_type' => Company::TYPE_CLIENT,
            'status' => 'active',
        ]);

        return Site::query()->create([
            'company_id' => $this->own->id,
            'client_company_id' => $client?->id,
            'code' => 'S-'.mt_rand(1000, 9999), 'name' => 'Test Site',
            'country' => 'US', 'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
    }

    private function draft(Site $site, string $lang = 'ko'): MemberRegistration
    {
        return MemberRegistration::query()->create([
            'company_id' => $this->own->id, 'site_id' => $site->id,
            'member_type' => 'worker', 'status' => 'pending',
            'full_name' => '', 'preferred_language' => $lang,
        ]);
    }

    public function test_the_question_names_the_general_contractor_of_that_site(): void
    {
        $registration = $this->draft($this->site('LAYTON CONSTRUCTION'));

        $this->get("/member/register/{$registration->invite_token}")->assertOk()
            ->assertSee('LAYTON CONSTRUCTION 현장 근무 경험 여부', false);
    }

    public function test_a_different_site_asks_about_a_different_general_contractor(): void
    {
        // 이것이 이 작업 전체의 이유다. 두 현장이 같은 질문을 받으면 하드코딩과 같다.
        $a = $this->draft($this->site('LAYTON CONSTRUCTION'));
        $b = $this->draft($this->site('OKLAND CONSTRUCTION'));

        $this->get("/member/register/{$a->invite_token}")->assertOk()
            ->assertSee('LAYTON CONSTRUCTION 현장 근무 경험', false)
            ->assertDontSee('OKLAND', false);

        $this->get("/member/register/{$b->invite_token}")->assertOk()
            ->assertSee('OKLAND CONSTRUCTION 현장 근무 경험', false)
            ->assertDontSee('LAYTON', false);
    }

    public function test_a_site_with_no_general_contractor_still_asks_a_sensible_question(): void
    {
        // 원청사를 아직 안 넣은 현장이 대부분이다. 빈칸이 남아 「 현장 근무 경험 여부」
        // 가 되면, 읽는 사람은 무엇을 묻는지 모른 채 필수 답을 골라야 한다.
        $registration = $this->draft($this->site(null));

        $this->get("/member/register/{$registration->invite_token}")->assertOk()
            ->assertSee('원청사 현장 근무 경험 여부', false);
    }

    public function test_the_consent_text_names_that_sites_general_contractor_too(): void
    {
        // 동의문은 서명받는 법적 문구다. 남의 회사 이름이 적힌 문서에 서명하게 두면,
        // 나중에 «무엇에 동의한 것인가» 를 아무도 설명하지 못한다.
        $registration = $this->draft($this->site('LAYTON CONSTRUCTION'));

        $this->get("/member/register/{$registration->invite_token}")->assertOk()
            ->assertSee('LAYTON CONSTRUCTION 안전교육 진행', false)
            ->assertSee('LAYTON CONSTRUCTION 안전교육 및 현장 출입 베지 정보', false);
    }

    public function test_the_consent_text_reads_plainly_when_the_site_has_no_general_contractor(): void
    {
        $registration = $this->draft($this->site(null));

        $this->get("/member/register/{$registration->invite_token}")->assertOk()
            ->assertSee('원청사 안전교육 진행', false);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function languages(): array
    {
        return [
            'en' => ['en', 'Previous experience on a LAYTON CONSTRUCTION site', 'the general contractor'],
            'es' => ['es', 'Experiencia previa en sitio de LAYTON CONSTRUCTION', 'el contratista general'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('languages')]
    public function test_the_other_two_languages_follow(string $lang, string $named, string $unnamed): void
    {
        // 한국어만 고치면 영어·스페인어 화면에 옛 회사 이름이 그대로 남는다.
        $named_ = $this->draft($this->site('LAYTON CONSTRUCTION'), $lang);
        $this->get("/member/register/{$named_->invite_token}")->assertOk()->assertSee($named, false);

        $plain = $this->draft($this->site(null), $lang);
        $this->get("/member/register/{$plain->invite_token}")->assertOk()->assertSee($unnamed, false);
    }

    public function test_the_answer_is_saved_under_a_name_that_belongs_to_nobody(): void
    {
        $site = $this->site('LAYTON CONSTRUCTION');
        $registration = $this->draft($site);

        $this->post("/member/register/{$registration->invite_token}", [
            'preferred_language' => 'ko',
            'first_name' => 'Luis', 'last_name' => 'Ramirez',
            'email' => 'l@example.test', 'phone' => '+1 602 555 0123',
            'emergency_contact_name' => 'M', 'emergency_contact_phone' => '+1 602 555 0124',
            'available_languages' => ['Spanish'], 'role' => 'Welder',
            'gc_experience' => 'yes',
            'identity_document_type' => 'driver_license',
            'identity_front' => UploadedFile::fake()->image('id.jpg'),
            'privacy_consent' => '1', 'applicant_signature' => 'Luis Ramirez',
            'signed_on' => '2026-09-17',
        ])->assertOk();

        $registration->refresh();
        $this->assertSame('yes', data_get($registration->payload, 'application.gc_experience'));
    }

    // ── 배지 판독 ──────────────────────────────────────────────────────

    /** 판독 지시문을 꺼내 본다 — 사람이 아니라 AI 에게 가는 글이라 화면에서는 안 보인다. */
    private function badgePrompt(?string $gc): string
    {
        $method = new \ReflectionMethod(GeminiBadgeAnalyzer::class, 'prompt');
        $method->setAccessible(true);

        return (string) $method->invoke(app(GeminiBadgeAnalyzer::class), $gc);
    }

    public function test_the_badge_reader_is_told_which_logo_to_ignore(): void
    {
        $prompt = $this->badgePrompt('LAYTON CONSTRUCTION');

        $this->assertStringContainsString('the LAYTON CONSTRUCTION logo', $prompt);
        $this->assertStringContainsString('Do not use', $prompt,
            '맨 위 큰 로고는 원청사다. 그걸 회사 이름으로 읽으면 전원이 같은 회사 소속이 된다.');
    }

    public function test_the_badge_reader_still_works_without_a_name(): void
    {
        // 원청사를 모르는 현장의 배지도 읽어야 한다. 이름 자리에 빈칸이 들어가
        // «the  logo» 같은 문장이 되면 AI 가 무엇을 무시해야 하는지 알 수 없다.
        $prompt = $this->badgePrompt(null);

        $this->assertStringContainsString('the general contractor logo at the top of the badge', $prompt);
        $this->assertStringNotContainsString('the  logo', $prompt);
    }
}
