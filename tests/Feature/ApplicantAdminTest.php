<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\MemberRegistration;
use App\Models\Site;
use App\Models\User;
use App\Services\Admin\ApplicantAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 입사지원 → 면접 → 안전교육 → 배지 → 활성화 — Filament MemberRegistrationResource 를 SPA 로.
 *
 * 이 화면은 폼이 아니라 줄(pipeline)이다. 그래서 "단계를 건너뛸 수 없는가" 와
 * "지금 어디서 막혀 있는지 정확히 말해주는가" 를 중심으로 검증한다.
 */
class ApplicantAdminTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->site = Site::create(['code' => 'LG_ESS_PH', 'name' => 'LG PHOENIX', 'timezone' => 'America/Phoenix', 'status' => 'active']);
        $this->company = Company::create(['code' => 'DP', 'name' => 'DASOL PRISM', 'status' => 'active']);
    }

    private function user(string $role, array $extra = []): User
    {
        return User::factory()->create(array_merge([
            'access_role' => $role, 'access_scope' => 'all_sites', 'account_status' => 'active',
        ], $extra));
    }

    private function svc(): ApplicantAdminService
    {
        return app(ApplicantAdminService::class);
    }

    /** 제출까지 끝난 지원자. 단계 검증의 출발점. */
    private function submitted(array $extra = []): MemberRegistration
    {
        return MemberRegistration::create(array_merge([
            'site_id' => $this->site->id, 'company_id' => $this->company->id,
            'full_name' => '홍길동', 'email' => 'hong@example.com', 'member_type' => 'worker',
            'onboarding_status' => 'submitted',
            'submitted_at' => Carbon::now(), 'privacy_consent_at' => Carbon::now(),
        ], $extra));
    }

    private function stageOf(int $id): string
    {
        return collect($this->svc()->list(['onlyOpen' => '0'])['rows'])->firstWhere('id', $id)['stage'];
    }

    // ── 접근 ────────────────────────────────────────────────────────────

    public function test_a_worker_cannot_read_applicants(): void
    {
        $this->actingAs($this->user('worker', ['access_scope' => 'self']));

        $this->assertFalse($this->svc()->list()['success']);
    }

    public function test_a_safety_manager_reads_and_may_record_training_but_not_manage(): void
    {
        $r = $this->submitted(['interview_status' => 'passed']);
        $this->actingAs($this->user('safety_manager'));

        $res = $this->svc()->list(['onlyOpen' => '0']);
        $this->assertTrue($res['success']);
        $this->assertFalse($res['canManage']);
        $this->assertTrue($res['canSafety'], '안전교육은 실제로 교육을 시키는 사람이 등록한다');

        $this->assertTrue($this->svc()->setSafetyTraining($r->id, '2026-08-01', null)['success']);
        $this->assertFalse($this->svc()->setInterview($r->id, 'passed')['success'], '면접 결과는 인사 담당의 일이다');
    }

    public function test_a_site_manager_only_sees_their_own_site(): void
    {
        $mine = $this->submitted();
        $other = Site::create(['code' => 'OTHER', 'name' => 'Other', 'timezone' => 'UTC', 'status' => 'active']);
        $theirs = $this->submitted(['site_id' => $other->id, 'full_name' => '남의 현장']);

        $this->actingAs($this->user('site_manager', ['access_scope' => 'site', 'allowed_site_id' => $this->site->id]));

        $ids = array_column($this->svc()->list(['onlyOpen' => '0'])['rows'], 'id');
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    // ── 단계 계산 ────────────────────────────────────────────────────────

    public function test_an_unsubmitted_applicant_is_waiting_on_the_applicant(): void
    {
        $r = MemberRegistration::create([
            'site_id' => $this->site->id, 'full_name' => '미제출', 'member_type' => 'worker',
            'onboarding_status' => 'invited',
        ]);
        $this->actingAs($this->user('hr_manager'));

        $row = collect($this->svc()->list(['onlyOpen' => '0'])['rows'])->firstWhere('id', $r->id);
        $this->assertSame('invited', $row['stage']);
        $this->assertTrue($row['waitingOnApplicant'], '우리가 할 일이 없는 건은 따로 구분돼야 한다');
        $this->assertNotNull($row['intakeUrl'], '재촉하려면 링크가 필요하다');
    }

    public function test_the_stage_advances_through_the_pipeline(): void
    {
        $r = $this->submitted();
        $this->actingAs($this->user('hr_manager'));

        $this->assertSame('submitted', $this->stageOf($r->id));

        $this->svc()->setInterview($r->id, 'passed');
        $this->assertSame('interview', $this->stageOf($r->id));

        $this->svc()->setSafetyTraining($r->id, '2026-08-01', null);
        $this->assertSame('safety', $this->stageOf($r->id));

        $this->svc()->registerBadge($r->id, ['nfcRawUid' => '04:A2:24:1B', 'badgeIssuedOn' => '2026-08-02']);
        $this->svc()->uploadBadgePhoto($r->id, UploadedFile::fake()->image('badge.jpg'), false);
        $this->assertSame('badge', $this->stageOf($r->id));
    }

    // ── 순서 강제 ────────────────────────────────────────────────────────

    public function test_safety_training_requires_a_passed_interview(): void
    {
        $r = $this->submitted();
        $this->actingAs($this->user('hr_manager'));

        $res = $this->svc()->setSafetyTraining($r->id, '2026-08-01', null);

        $this->assertFalse($res['success'], '순서를 건너뛰면 교육 대상이 아닌 사람이 명단에 오른다');
        $this->assertSame('pending', $r->fresh()->safety_training_status ?: 'pending');
    }

    public function test_badge_registration_requires_completed_safety_training(): void
    {
        $r = $this->submitted(['interview_status' => 'passed']);
        $this->actingAs($this->user('hr_manager'));

        $res = $this->svc()->registerBadge($r->id, ['nfcRawUid' => '04:A2', 'badgeIssuedOn' => '2026-08-02']);

        $this->assertFalse($res['success']);
        $this->assertStringContainsString('안전교육', $res['error']);
    }

    public function test_an_interview_cannot_be_recorded_before_submission(): void
    {
        $r = MemberRegistration::create([
            'site_id' => $this->site->id, 'full_name' => '미제출', 'member_type' => 'worker',
            'onboarding_status' => 'invited',
        ]);
        $this->actingAs($this->user('hr_manager'));

        $this->assertFalse($this->svc()->setInterview($r->id, 'passed')['success']);
    }

    public function test_a_failed_interview_rejects_the_application(): void
    {
        // 불합격도 기록해야 한다. 아무 표시가 없으면 "아직 안 봤나" 와 구분이 안 된다.
        $r = $this->submitted();
        $this->actingAs($this->user('hr_manager'));

        $this->svc()->setInterview($r->id, 'failed', '경력 불일치');

        $r->refresh();
        $this->assertSame('failed', $r->interview_status);
        $this->assertSame('rejected', $r->onboarding_status);
        $this->assertSame('경력 불일치', $r->interview_notes);
    }

    // ── 배지 ────────────────────────────────────────────────────────────

    public function test_the_raw_uid_is_normalised_into_a_badge_number(): void
    {
        $r = $this->submitted(['interview_status' => 'passed', 'safety_training_status' => 'completed']);
        $this->actingAs($this->user('hr_manager'));

        $res = $this->svc()->registerBadge($r->id, ['nfcRawUid' => '04:a2:24:1b:5c:6d:80', 'badgeIssuedOn' => '2026-08-02']);

        $this->assertTrue($res['success']);
        $this->assertSame(MemberRegistration::normalizeNfcUid('04:a2:24:1b:5c:6d:80'), $r->fresh()->badge_number);
    }

    public function test_the_same_badge_cannot_be_given_to_two_applicants(): void
    {
        $first = $this->submitted(['interview_status' => 'passed', 'safety_training_status' => 'completed']);
        $second = $this->submitted(['full_name' => '두번째', 'interview_status' => 'passed', 'safety_training_status' => 'completed']);
        $this->actingAs($this->user('hr_manager'));

        $this->svc()->registerBadge($first->id, ['nfcRawUid' => '04:A2:24:1B', 'badgeIssuedOn' => '2026-08-02']);
        $res = $this->svc()->registerBadge($second->id, ['nfcRawUid' => '04:A2:24:1B', 'badgeIssuedOn' => '2026-08-02']);

        $this->assertFalse($res['success'], '같은 배지를 두 사람에게 붙이면 게이트에서 누구인지 알 수 없다');
        $this->assertArrayHasKey('nfcRawUid', $res['errors']);
    }

    public function test_the_badge_issue_date_is_required_because_it_becomes_the_hire_date(): void
    {
        $r = $this->submitted(['interview_status' => 'passed', 'safety_training_status' => 'completed']);
        $this->actingAs($this->user('hr_manager'));

        $res = $this->svc()->registerBadge($r->id, ['nfcRawUid' => '04:A2:24:1B']);

        $this->assertFalse($res['success']);
        $this->assertArrayHasKey('badgeIssuedOn', $res['errors']);
    }

    public function test_a_failed_photo_analysis_still_keeps_the_photo(): void
    {
        // 판독은 편의 기능이고, 사진이 있어야 나중에 사람 대조가 된다.
        $r = $this->submitted(['interview_status' => 'passed', 'safety_training_status' => 'completed']);
        $this->actingAs($this->user('hr_manager'));
        config(['services.gemini.api_key' => '', 'services.anthropic.api_key' => '']);

        $res = $this->svc()->uploadBadgePhoto($r->id, UploadedFile::fake()->image('badge.jpg'), true);

        $this->assertTrue($res['success']);
        $this->assertTrue($res['analysisFailed']);
        $this->assertNotNull($r->fresh()->badge_photo_path);
        Storage::disk('public')->assertExists($r->fresh()->badge_photo_path);
    }

    public function test_an_oversized_photo_is_refused(): void
    {
        $r = $this->submitted(['interview_status' => 'passed', 'safety_training_status' => 'completed']);
        $this->actingAs($this->user('hr_manager'));

        $res = $this->svc()->uploadBadgePhoto($r->id,
            UploadedFile::fake()->create('huge.jpg', ApplicantAdminService::MAX_KB + 1024, 'image/jpeg'), false);

        $this->assertFalse($res['success']);
        $this->assertArrayHasKey('file', $res['errors']);
    }

    // ── 활성화 ──────────────────────────────────────────────────────────

    public function test_activation_lists_every_missing_thing_at_once(): void
    {
        // 하나씩 알려주면 왕복이 여러 번 생긴다.
        $r = $this->submitted();
        $this->actingAs($this->user('hr_manager'));

        $res = $this->svc()->activate($r->id);

        $this->assertFalse($res['success']);
        $this->assertGreaterThan(1, count($res['blockers']));
        $this->assertNotEmpty(array_filter($res['blockers'], fn ($b) => str_contains($b, 'Interview')));
    }

    public function test_a_fully_prepared_applicant_activates_into_an_employee(): void
    {
        $r = $this->submitted(['interview_status' => 'passed', 'safety_training_status' => 'completed']);
        $this->actingAs($this->user('hr_manager'));

        $this->svc()->registerBadge($r->id, ['nfcRawUid' => '04:A2:24:1B', 'badgeIssuedOn' => '2026-08-02']);
        $this->svc()->uploadBadgePhoto($r->id, UploadedFile::fake()->image('badge.jpg'), false);
        $r->documents()->create(['document_type' => 'id', 'title' => '신분증', 'file_path' => 'x.pdf', 'disk' => 'public']);

        $res = $this->svc()->activate($r->id);

        $this->assertTrue($res['success'], json_encode($res));
        $this->assertNotEmpty($res['employeeNumber']);
        $this->assertSame('active', $r->fresh()->onboarding_status);
        $this->assertNotNull($r->fresh()->employee_id, '활성화하면 직원 기록이 만들어져야 한다');
    }

    public function test_an_active_applicant_cannot_be_rejected(): void
    {
        $r = $this->submitted(['onboarding_status' => 'active']);
        $this->actingAs($this->user('hr_manager'));

        $res = $this->svc()->reject($r->id);

        $this->assertFalse($res['success']);
        $this->assertStringContainsString('직원 화면', $res['error']);
    }

    public function test_resync_only_applies_to_active_applicants(): void
    {
        $r = $this->submitted();
        $this->actingAs($this->user('hr_manager'));

        $this->assertFalse($this->svc()->resync($r->id)['success']);
    }

    // ── 초대 ────────────────────────────────────────────────────────────

    public function test_an_invitation_needs_a_site_and_returns_a_link(): void
    {
        $this->actingAs($this->user('hr_manager'));

        $this->assertArrayHasKey('siteId', $this->svc()->invite([])['errors']);

        $res = $this->svc()->invite(['siteId' => (string) $this->site->id, 'name' => '신규', 'language' => 'es']);
        $this->assertTrue($res['success']);
        $this->assertStringContainsString('http', $res['url']);
    }

    public function test_the_default_list_hides_finished_applicants(): void
    {
        $open = $this->submitted();
        $done = $this->submitted(['full_name' => '완료', 'onboarding_status' => 'active']);
        $this->actingAs($this->user('hr_manager'));

        $ids = array_column($this->svc()->list()['rows'], 'id');
        $this->assertContains($open->id, $ids);
        $this->assertNotContains($done->id, $ids, '끝난 사람이 섞이면 줄이 안 보인다');
    }

    public function test_the_api_exposes_the_screen_and_blocks_read_only_clients(): void
    {
        $this->actingAs($this->user('hr_manager'));
        $this->postJson('/smart-company-api/api_getApplicants', ['args' => [[]], 'siteId' => 'ALL'])
            ->assertOk()->assertJsonPath('success', true);

        $this->actingAs($this->user('client'));
        $this->postJson('/smart-company-api/api_activateApplicant', ['args' => [1], 'siteId' => 'ALL'])
            ->assertStatus(403);
    }
}
