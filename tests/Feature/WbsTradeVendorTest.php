<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Site;
use App\Models\WbsItem;
use App\Services\Wbs\ClaudeWbsAnalyzer;
use App\Services\Wbs\WbsService;
use App\Support\SmartCompanyData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 공종(trade) vs 협력사(company) 분리:
 * AI 는 공종만 분류하고, 협력사는 사람이 실제 계약사에서 배정한다.
 */
class WbsTradeVendorTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_sets_trade_and_leaves_company_unassigned(): void
    {
        config(['services.anthropic.api_key' => 'sk-ant-test']);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode(['stages' => [[
                        'stage_no' => '1', 'stage_name' => '설치',
                        'tasks' => [[
                            'task_no' => '1.1', 'task_name' => '배선',
                            'sub_tasks' => [
                                ['sub_no' => '1.1.1', 'sub_name' => '동력 케이블 포설', 'trade' => '전기', 'manhours' => 36, 'days' => 3, 'ehs' => 'high'],
                            ],
                        ]],
                    ]]]),
                ]],
            ]),
        ]);

        app(ClaudeWbsAnalyzer::class)->processManual('TRD-01');

        $sub = WbsItem::where('project_code', 'TRD-01')->where('level', 'subtask')->first();
        $this->assertNotNull($sub);
        $this->assertSame('전기', $sub->trade);          // AI가 공종 분류
        $this->assertNull($sub->company);                 // 협력사는 미배정 (사람이 나중에)
    }

    public function test_human_assigns_company_and_it_persists(): void
    {
        $sub = WbsItem::create([
            'project_code' => 'TRD-01', 'level' => 'subtask', 'wbs_code' => 'TRD-01-W-1.1.1',
            'node_no' => '1.1.1', 'name' => '동력 케이블 포설', 'trade' => '전기', 'company' => null, 'status' => '검수완료',
        ]);

        // 사람이 실제 계약사(company) + 공종(trade) 편집
        app(WbsService::class)->updateRow('TRD-01-W-1.1.1', ['담당사' => 'AI KOREA', '공종' => '전기설비']);

        $sub->refresh();
        $this->assertSame('AI KOREA', $sub->company);
        $this->assertSame('전기설비', $sub->trade);
    }

    public function test_wbs_company_options_lists_registered_companies(): void
    {
        Company::create(['code' => 'AIK', 'name' => 'AI KOREA', 'status' => 'active']);
        Company::create(['code' => 'MSL', 'name' => 'M-SOL', 'status' => 'active']);
        Company::create(['code' => 'OLD', 'name' => 'Retired Co', 'status' => 'inactive']);
        Site::create(['code' => 'ST-1', 'name' => 'Site 1', 'timezone' => 'America/Phoenix', 'status' => 'active']);

        $options = SmartCompanyData::wbsCompanyOptions('ST-1');

        $this->assertContains('AI KOREA', $options);
        $this->assertContains('M-SOL', $options);
        $this->assertNotContains('Retired Co', $options); // inactive 제외
    }
}
