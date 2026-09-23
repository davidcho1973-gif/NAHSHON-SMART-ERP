<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 벽에 붙는 종이는 <b>데이터베이스 줄 번호</b>에 기대면 안 된다.
 *
 * 게이트·등록 QR 주소에는 현장 번호(id)가 들어간다. 그 번호는 환경마다 다르다 —
 * 같은 703K 현장이 개발 DB 에서는 780, 서버에서는 다른 번호일 수 있다. 그 번호로
 * 인쇄한 종이는 옮긴 환경에서 통째로 죽는다.
 *
 * 현장 코드(703K)는 사람이 정하고 바뀌지 않는다. 그래서 주소가 둘 다 받는다.
 */
class SiteUrlWorksByCodeTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::query()->create([
            'code' => 'OWN', 'name' => 'ERP', 'legal_name' => 'ERP LLC',
            'company_type' => Company::TYPE_OWN, 'status' => 'active',
        ]);
        $this->site = Site::query()->create([
            'company_id' => $company->id, 'code' => '703K', 'name' => '703K 주방 설비',
            'country' => 'US', 'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
    }

    public function test_the_gate_opens_by_site_code(): void
    {
        $this->get('/gate/703K')->assertOk()->assertSee('703K 주방 설비');
    }

    public function test_the_registration_form_opens_by_site_code(): void
    {
        $this->followingRedirects()->get('/join/w/703K')->assertOk()->assertSee('703K 주방 설비');
    }

    public function test_lower_case_on_the_paper_still_finds_the_site(): void
    {
        // 손으로 옮겨 적는 사람도 있다.
        $this->get('/gate/703k')->assertOk();
    }

    public function test_the_numbered_address_already_printed_keeps_working(): void
    {
        // 이미 벽에 붙은 종이를 죽이지 않는 것이 이 변경의 조건이다.
        $this->get('/gate/'.$this->site->id)->assertOk();
        $this->followingRedirects()->get('/join/w/'.$this->site->id)->assertOk();
    }

    public function test_a_site_number_wins_over_a_code_that_looks_like_a_number(): void
    {
        // 코드가 숫자인 현장이 있으면 남의 번호와 겹칠 수 있다. 번호를 먼저 본다 —
        // 이미 인쇄된 번호 주소가 엉뚱한 현장을 열면 그날 출퇴근이 통째로 틀어진다.
        $other = Site::query()->create([
            'company_id' => $this->site->company_id, 'code' => (string) $this->site->id,
            'name' => '숫자 코드 현장', 'country' => 'US', 'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);

        $this->get('/gate/'.$this->site->id)->assertOk()->assertSee('703K 주방 설비');
        $this->get('/gate/'.$other->code)->assertOk()->assertSee('703K 주방 설비');
    }

    public function test_a_code_nobody_has_is_still_not_found(): void
    {
        $this->get('/gate/NOPE')->assertNotFound();
    }
}
