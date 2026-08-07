<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 팀 소속사 이름 정규화(옵션 1): 정규화된 마스터(company_id → companies.name)를
 * 우선하고, 마스터 연결이 없을 때만 자유 입력 텍스트를 폴백으로 사용한다.
 */
class TeamCompanyNameTest extends TestCase
{
    use RefreshDatabase;

    private function makeSite(): Site
    {
        return Site::query()->create([
            'code' => 'ST-1',
            'name' => 'Site 1',
            'timezone' => 'America/Phoenix',
            'status' => 'active',
        ]);
    }

    public function test_master_link_takes_precedence_over_free_text(): void
    {
        $site = $this->makeSite();
        $company = Company::query()->create(['code' => 'AIK', 'name' => 'AI KOREA', 'status' => 'active']);

        $team = Team::query()->create([
            'site_id' => $site->id,
            'company_id' => $company->id,
            'code' => 'AI-PIPE',
            'name' => '배관팀',
            'contract_company_name' => 'NAH SHON MEP', // stale free text differs from master
            'status' => 'active',
        ]);

        // Master wins even when the text field says something else.
        $this->assertSame('AI KOREA', $team->effectiveCompanyName());
    }

    public function test_free_text_is_fallback_when_no_master_link(): void
    {
        $site = $this->makeSite();

        $team = Team::query()->create([
            'site_id' => $site->id,
            'company_id' => null,
            'code' => 'AI-ELEC',
            'name' => '전기팀',
            'contract_company_name' => 'NAH SHON MEP',
            'status' => 'active',
        ]);

        $this->assertSame('NAH SHON MEP', $team->effectiveCompanyName());
    }

    public function test_returns_null_when_neither_is_set(): void
    {
        $site = $this->makeSite();

        $team = Team::query()->create([
            'site_id' => $site->id,
            'company_id' => null,
            'code' => 'AI-GEN',
            'name' => '일반팀',
            'contract_company_name' => null,
            'status' => 'active',
        ]);

        $this->assertNull($team->effectiveCompanyName());
    }
}
