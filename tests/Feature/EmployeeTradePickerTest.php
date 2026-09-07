<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\WbsItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeTradePickerTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_picker_preserves_manual_input_and_ignores_stale_site_responses(): void
    {
        $process = proc_open(['node', base_path('tests/js/employee-join-trades.test.cjs')], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        array_map('fclose', $pipes);
        $this->assertSame(0, proc_close($process), $output);
    }

    public function test_site_selection_fetches_only_that_sites_actual_trade_names(): void
    {
        $a = Site::create(['code' => 'A', 'name' => 'Existing A', 'status' => 'active']);
        $b = Site::create(['code' => 'B', 'name' => 'Existing B', 'status' => 'active']);
        foreach ([[$a, '배관'], [$b, '전기']] as [$site, $trade]) {
            WbsItem::create(['project_code' => $site->code, 'level' => 'subtask', 'wbs_code' => $site->code.'-01', 'name' => 'Private task detail', 'trade' => $trade, 'site_id' => $site->id]);
        }
        $this->get(route('employee-join.entry'))->assertOk()->assertSee('공무지원')
            ->assertDontSee('Private task detail')->assertDontSee('value="배관"', false);
        $this->getJson(route('employee-join.trades', $a))->assertOk()->assertExactJson(['trades' => ['배관', '공무지원']]);
        $this->getJson(route('employee-join.trades', $b))->assertOk()->assertExactJson(['trades' => ['전기', '공무지원']]);
        WbsItem::where('site_id', $a->id)->update(['trade' => '설비']);
        $this->getJson(route('employee-join.trades', $a))->assertOk()->assertExactJson(['trades' => ['설비', '공무지원']]);
    }

    public function test_empty_sites_have_support_and_defaults_but_inactive_sites_are_rejected(): void
    {
        $site = Site::create(['code' => 'EMPTY', 'name' => 'Empty site', 'status' => 'active']);
        $trades = $this->getJson(route('employee-join.trades', $site))->assertOk()->json('trades');
        $this->assertContains('공무지원', $trades);
        $this->assertContains('Electrician', $trades);
        $site->update(['status' => 'inactive']);
        $this->getJson(route('employee-join.trades', $site))->assertNotFound();
        $this->getJson('/join/999999/trades')->assertNotFound();
    }

    public function test_shared_signup_saves_support_or_manual_trade_without_creating_wbs_tasks(): void
    {
        $company = Company::create(['code' => 'OWN', 'name' => 'Own', 'status' => 'active', 'company_type' => Company::TYPE_OWN]);
        $site = Site::create(['code' => 'SITE', 'name' => 'Site', 'status' => 'active']);
        foreach (['공무지원', '특수 배관 보온'] as $i => $trade) {
            $this->post(route('employee-join.entry-store'), [
                'registration_site' => (string) $site->id, 'full_name' => 'Test Worker '.$i,
                'company_id' => $company->id, 'position' => 'worker', 'role' => ' '.$trade.' ', 'phone' => '+1480555011'.$i,
            ])->assertOk();
            $this->assertDatabaseHas('employees', ['name' => 'Test Worker '.$i, 'role' => $trade, 'site_id' => $site->id]);
        }
        $this->post(route('employee-join.entry-store'), [
            'registration_site' => 'global', 'full_name' => 'Test Global', 'company_id' => $company->id,
            'position' => 'general_manager', 'role' => '공무지원', 'email' => 'test-global@example.com', 'phone' => '+14805550119',
        ])->assertOk();
        $this->assertNull(Employee::where('name', 'Test Global')->sole()->site_id);
        $this->assertDatabaseCount('wbs_items', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_manual_trade_is_required_and_length_limited(): void
    {
        $site = Site::create(['code' => 'S', 'name' => 'Site', 'status' => 'active']);
        foreach (['   ', str_repeat('가', 61)] as $trade) {
            $this->post(route('employee-join.entry-store'), ['registration_site' => (string) $site->id, 'role' => $trade])
                ->assertSessionHasErrors('role');
        }
        $this->assertDatabaseCount('employees', 0);
    }
}
