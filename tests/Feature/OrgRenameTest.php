<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Support\Org;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 이미 쓰고 있는 배포의 회사 이름을 바꾼다 — 데이터는 그대로 두고.
 *
 * 시험용으로 세운 배포가 그대로 진짜 고객 것이 되는 일이 있다. 그때 데이터를 지우고
 * 다시 세우는 것은 답이 아니다 — 이미 쌓인 출퇴근과 급여가 그 회사의 실제 기록이다.
 *
 * 화면 이름(조직 설정)만 바꾸면 절반만 바뀐다. companies 표의 자사 한 줄은 옛 이름
 * 그대로 남아, 직원 목록의 "소속 회사" 칸에 계속 옛 이름이 뜬다. 두 곳이 어긋난 채로
 * 굴러가면 나중에 어느 쪽이 맞는지 아무도 모른다.
 */
class OrgRenameTest extends TestCase
{
    use RefreshDatabase;

    private Company $own;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Org::forget();

        $this->own = Company::create([
            'code' => 'OLD', 'name' => '옛 이름', 'legal_name' => '옛 이름',
            'company_type' => Company::TYPE_OWN, 'status' => 'active',
        ]);
        $site = Site::create([
            'code' => 'S1', 'name' => 'Site One',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
        $this->employee = Employee::create([
            'company_id' => $this->own->id, 'site_id' => $site->id,
            'name' => 'Cristian rosas', 'employment_status' => 'active',
        ]);
        AttendanceLog::create([
            'employee_id' => $this->employee->id, 'site_id' => $site->id,
            'company_id' => $this->own->id, 'event_type' => 'clock_in',
            'event_at' => Carbon::now(), 'attendance_date' => Carbon::now()->toDateString(),
            'status' => 'approved', 'source' => 'web_portal',
        ]);

        config(['org.name' => 'KSR', 'org.code' => 'KSR', 'org.code_configured' => true, 'org.legal_name' => null]);
        Org::forget();
    }

    protected function tearDown(): void
    {
        Org::forget();
        parent::tearDown();
    }

    // ── 미리보기가 기본이다 ─────────────────────────────────────────────

    public function test_it_changes_nothing_without_force(): void
    {
        $this->artisan('org:rename')->assertSuccessful();

        $this->assertSame('옛 이름', $this->own->fresh()->name);
    }

    public function test_the_preview_shows_what_would_change(): void
    {
        $this->artisan('org:rename')
            ->expectsOutputToContain('아무것도 바꾸지 않았습니다')
            ->assertSuccessful();
    }

    // ── 이름을 바꾼다 ───────────────────────────────────────────────────

    public function test_it_renames_the_own_company(): void
    {
        $this->artisan('org:rename --force')->assertSuccessful();

        $own = $this->own->fresh();
        $this->assertSame('KSR', $own->name);
        $this->assertSame('KSR', $own->code);
        $this->assertSame('KSR', $own->legal_name);
    }

    public function test_the_records_survive_the_rename(): void
    {
        // 이 명령을 쓰는 이유 전부다. 지우고 다시 세우면 될 일이었다면 필요 없다.
        $this->artisan('org:rename --force')->assertSuccessful();

        $this->assertSame(1, Employee::count());
        $this->assertSame(1, AttendanceLog::count());
        $this->assertSame('Cristian rosas', $this->employee->fresh()->name);
        // 직원이 계속 같은 회사에 붙어 있어야 한다 — 회사 줄을 새로 만들지 않았다.
        $this->assertSame($this->own->id, $this->employee->fresh()->company_id);
    }

    public function test_running_it_twice_is_harmless(): void
    {
        $this->artisan('org:rename --force')->assertSuccessful();
        $this->artisan('org:rename --force')
            ->expectsOutputToContain('바꿀 것이 없습니다')
            ->assertSuccessful();

        $this->assertSame(1, Company::query()->where('code', 'KSR')->count());
    }

    public function test_it_leaves_the_code_alone_when_nobody_asked_for_it(): void
    {
        // 화면(조직 설정)에서 이름만 바꾸는 것이 가장 흔한 경우다. 그때 코드까지
        // 함께 덮이면 부탁하지 않은 일이 벌어진 것이고, 되돌리려면 또 손이 간다.
        config(['org.code_configured' => false, 'org.code' => 'OWN']);
        Org::forget();

        $this->artisan('org:rename --force')->assertSuccessful();

        $own = $this->own->fresh();
        $this->assertSame('KSR', $own->name);
        $this->assertSame('OLD', $own->code, '부탁하지 않은 코드까지 바뀌었습니다.');
    }

    // ── 헷갈릴 때는 사람에게 묻는다 ────────────────────────────────────

    public function test_it_refuses_when_no_company_is_marked_as_own(): void
    {
        // 아무거나 집으면 남의 회사 이름이 우리 이름으로 덮인다.
        $this->own->forceFill(['company_type' => Company::TYPE_PARTNER])->save();

        $this->artisan('org:rename --force')
            ->expectsOutputToContain('자사로 표시된 회사가 없습니다')
            ->assertFailed();
    }

    public function test_it_refuses_when_two_companies_claim_to_be_own(): void
    {
        Company::create([
            'code' => 'OTHER', 'name' => '또 다른 자사',
            'company_type' => Company::TYPE_OWN, 'status' => 'active',
        ]);

        $this->artisan('org:rename --force')->assertFailed();
        $this->assertSame('옛 이름', $this->own->fresh()->name);
    }

    public function test_a_named_company_can_be_chosen(): void
    {
        $partner = Company::create([
            'code' => 'KSA', 'name' => 'KSA',
            'company_type' => Company::TYPE_PARTNER, 'status' => 'active',
        ]);

        $this->artisan('org:rename --company=KSA --force')->assertSuccessful();

        $this->assertSame('KSR', $partner->fresh()->name);
        $this->assertSame(Company::TYPE_OWN, $partner->fresh()->company_type);
        // 자사는 하나뿐이어야 한다 — 옛 자사는 협력사로 내려간다.
        $this->assertSame(Company::TYPE_PARTNER, $this->own->fresh()->company_type);
    }

    public function test_it_refuses_when_the_new_code_is_already_taken(): void
    {
        // 코드가 겹치면 자사가 둘이 되고, 그때부터 코드로는 가릴 수 없다.
        Company::create([
            'code' => 'KSR', 'name' => '남의 KSR',
            'company_type' => Company::TYPE_PARTNER, 'status' => 'active',
        ]);

        $this->artisan('org:rename --force')
            ->expectsOutputToContain('이미 있습니다')
            ->assertFailed();

        $this->assertSame('옛 이름', $this->own->fresh()->name);
    }

    public function test_an_unknown_company_is_refused(): void
    {
        $this->artisan('org:rename --company=없는회사 --force')->assertFailed();
    }
}
