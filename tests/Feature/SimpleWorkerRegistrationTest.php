<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\MemberRegistration;
use App\Models\Site;
use App\Models\WbsItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 간편 작업자 등록 — QR 스캔 → 최소 정보 입력 → 즉시 활성 Employee 등록.
 */
class SimpleWorkerRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_quick_qr_poster_renders_local_qr(): void
    {
        $site = Site::create(['code' => 'AZ-01', 'name' => 'Arizona Site', 'timezone' => 'America/Phoenix', 'status' => 'active']);

        $res = $this->get('/join/w/'.$site->id.'/qr');

        $res->assertStatus(200);
        $res->assertSee('data:image/svg+xml;base64,');
        $res->assertSee('/join/w/'.$site->id, false);
        $res->assertDontSee('api.qrserver.com');
    }

    public function test_form_lists_companies_and_trades(): void
    {
        $site = Site::create(['code' => 'AZ-01', 'name' => 'Arizona Site', 'timezone' => 'America/Phoenix', 'status' => 'active']);
        Company::create(['code' => 'C1', 'name' => '대한설비', 'status' => 'active', 'company_type' => Company::TYPE_PARTNER]);

        $res = $this->get('/join/w/'.$site->id);
        $res->assertStatus(200);
        $res->assertSee('대한설비');
        $res->assertSee('Electrician');
    }

    public function test_form_reflects_wbs_trades_and_requires_selection(): void
    {
        $site = Site::create(['code' => 'AZ-01', 'name' => 'Arizona Site', 'timezone' => 'America/Phoenix', 'status' => 'active']);
        Company::create(['code' => 'C1', 'name' => '대한설비', 'status' => 'active', 'company_type' => Company::TYPE_PARTNER]);
        WbsItem::create(['project_code' => 'P1', 'level' => 'subtask', 'wbs_code' => 'P1-A1', 'name' => '배선', 'trade' => 'ELEC', 'status' => '진행중', 'site_id' => $site->id]);
        WbsItem::create(['project_code' => 'P1', 'level' => 'subtask', 'wbs_code' => 'P1-A2', 'name' => '배관', 'trade' => 'MECH', 'status' => '진행중', 'site_id' => $site->id]);

        $res = $this->get('/join/w/'.$site->id);
        $res->assertStatus(200);
        $res->assertSee('공정관리(WBS)의 공종 목록');
        $res->assertSee('value="ELEC"', false);   // WBS 에서 추출
        $res->assertSee('value="MECH"', false);
        $res->assertSee('<select name="role"', false); // 목록 선택만 허용(자유 입력 금지)

        // 목록에 없는 공정(자유 입력)은 거부된다 — 인원체크 집계가 공정 단위로 묶여야 하기 때문.
        $company = Company::first();
        $this->post('/join/w/'.$site->id, [
            'full_name' => 'Kim', 'company_id' => $company->id, 'role' => '특수용접(수기입력)',
            'email' => 'kim@example.com', 'phone' => '480-555-0199',
        ])->assertSessionHasErrors(['role']);
        $this->assertNull(Employee::where('email', 'kim@example.com')->first());

        // 목록에 있는 공정은 정상 등록.
        $this->post('/join/w/'.$site->id, [
            'full_name' => 'Kim', 'company_id' => $company->id, 'role' => 'MECH',
            'email' => 'kim@example.com', 'phone' => '480-555-0199',
        ])->assertStatus(200);
        $this->assertSame('MECH', Employee::where('email', 'kim@example.com')->first()->role);
    }

    public function test_submit_creates_active_worker_immediately(): void
    {
        $site = Site::create(['code' => 'AZ-01', 'name' => 'Arizona Site', 'timezone' => 'America/Phoenix', 'status' => 'active']);
        $company = Company::create(['code' => 'C1', 'name' => '대한설비', 'status' => 'active', 'company_type' => Company::TYPE_PARTNER]);

        $res = $this->post('/join/w/'.$site->id, [
            'full_name' => 'HYUNSUK CHO',
            'company_id' => $company->id,
            'role' => 'Electrician',
            'email' => 'hyunsuk@example.com',
            'phone' => '480-555-0100',
        ]);

        $res->assertStatus(200);
        $res->assertSee('등록 완료');

        $emp = Employee::where('email', 'hyunsuk@example.com')->first();
        $this->assertNotNull($emp);
        $this->assertSame('active', $emp->employment_status);
        $this->assertSame($site->id, $emp->site_id);
        $this->assertSame($company->id, $emp->company_id);
        $this->assertSame('Electrician', $emp->role);
        $this->assertNotEmpty($emp->employee_number);

        $reg = MemberRegistration::where('email', 'hyunsuk@example.com')->first();
        $this->assertNotNull($reg->submitted_at);
    }

    public function test_submit_requires_all_fields(): void
    {
        $site = Site::create(['code' => 'AZ-01', 'name' => 'Arizona Site', 'timezone' => 'America/Phoenix', 'status' => 'active']);

        $this->post('/join/w/'.$site->id, ['full_name' => '', 'email' => 'bad'])
            ->assertSessionHasErrors(['full_name', 'company_id', 'role', 'email', 'phone']);
    }
}
