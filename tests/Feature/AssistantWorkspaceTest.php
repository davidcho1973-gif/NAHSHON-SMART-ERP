<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Site;
use App\Models\User;
use App\Models\WbsItem;
use App\Services\Assistant\AssistantCheckService;
use App\Services\Assistant\AssistantReportService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Tests\TestCase;

class AssistantWorkspaceTest extends TestCase
{
    use DatabaseMigrations;

    private Company $company;

    private Site $site;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->company = Company::create(['code' => 'AI-A', 'name' => 'Company A', 'status' => 'active']);
        $this->site = Site::create(['company_id' => $this->company->id, 'code' => 'AI-SITE', 'name' => 'Site A', 'status' => 'active', 'timezone' => 'America/New_York']);
        $this->owner = User::factory()->create(['access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active', 'allowed_company_id' => $this->company->id]);
    }

    private function row(string $code, ?Site $site = null): WbsItem
    {
        $site ??= $this->site;

        return WbsItem::create(['company_id' => $site->company_id, 'site_id' => $site->id, 'wbs_code' => $code, 'name' => '=HYPERLINK("https://invalid.example","test")',
            'project_code' => 'AI-PROJECT', 'level' => 'task', 'progress' => 10, 'planned_end' => now()->subDays(2)->toDateString()]);
    }

    private function input(string $dataset = 'wbs_items'): array
    {
        return ['dataset' => $dataset, 'company_id' => $this->company->id, 'site_id' => $this->site->id];
    }

    public function test_reports_are_scoped_grounded_and_do_not_call_ai(): void
    {
        $mine = $this->row('AI-WBS');
        $otherCompany = Company::create(['code' => 'AI-B', 'name' => 'B', 'status' => 'active']);
        $other = Site::create(['company_id' => $otherCompany->id, 'code' => 'AI-OTHER', 'name' => 'Other', 'status' => 'active']);
        $this->row('AI-SECRET', $other);
        $report = app(AssistantReportService::class)->report($this->owner, $this->input());
        $this->assertSame([$mine->id], array_column($report['records'], 'id'));
        $this->assertSame([['dataset' => 'wbs_items', 'record_id' => $mine->id]], $report['sources']);
        $this->assertFalse($report['truncated']);
        $this->assertArrayNotHasKey('planned_cost', $report['records'][0]);
        Http::assertNothingSent();
    }

    public function test_report_and_export_require_current_strong_login(): void
    {
        $this->actingAs($this->owner)->postJson('/ask-api/workspace/report', $this->input())->assertForbidden();
        $this->actingAsPurchaseUser($this->owner)->postJson('/ask-api/workspace/report', $this->input())->assertOk();
        $this->withSession(['worker_device_only' => true])->getJson('/ask-api/workspace/export?'.http_build_query($this->input()))->assertForbidden();
    }

    public function test_unknown_datasets_or_client_roles_cannot_expand_access(): void
    {
        $this->actingAsPurchaseUser($this->owner)->postJson('/ask-api/workspace/report', $this->input('users'))->assertUnprocessable();
        $this->owner->update(['access_role' => 'worker', 'access_scope' => 'self']);
        $this->owner->companies()->sync([$this->company->id]);
        $this->postJson('/ask-api/workspace/report', $this->input() + ['role' => 'super_admin'])->assertForbidden();
    }

    public function test_financial_report_does_not_bypass_ai_money_policy(): void
    {
        $this->owner->update(['access_role' => 'site_manager', 'access_scope' => 'site', 'allowed_site_id' => $this->site->id]);
        $this->owner->companies()->sync([$this->company->id]);
        $this->actingAsPurchaseUser($this->owner)->postJson('/ask-api/workspace/report', $this->input('pay_applications'))->assertForbidden();
        $keys = array_column(app(AssistantReportService::class)->options($this->owner)['datasets'], 'key');
        $this->assertNotContains('items', $keys);
        $this->assertNotContains('pay_applications', $keys);
    }

    public function test_excel_has_provenance_and_stores_untrusted_text_as_strings(): void
    {
        $this->row('AI-XLSX');
        $service = app(AssistantReportService::class);
        $report = $service->report($this->owner, $this->input(), true);
        $book = $service->workbook($report);
        $col = array_search('name', $report['columns'], true) + 1;
        $cell = $book->getSheet(1)->getCell([$col, 2]);
        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
        $this->assertStringStartsWith('=HYPERLINK', $cell->getValue());
        $this->assertSame('wbs_items', $book->getSheet(0)->getCell('B4')->getValue());
        $book->disconnectWorksheets();
    }

    public function test_bounded_report_pagination_is_explicit(): void
    {
        for ($i = 0; $i < 101; $i++) {
            $this->row('AI-PAGE-'.$i);
        }
        $first = app(AssistantReportService::class)->report($this->owner, $this->input());
        $this->assertCount(100, $first['records']);
        $this->assertTrue($first['truncated']);
        $second = app(AssistantReportService::class)->report($this->owner, $this->input() + ['after_id' => $first['next_after_id']]);
        $this->assertCount(1, $second['records']);
        $this->assertFalse($second['truncated']);
    }

    public function test_checks_start_disabled_and_need_both_server_gate_and_explicit_approval(): void
    {
        $service = app(AssistantCheckService::class);
        $check = $service->save($this->owner, ['site_id' => $this->site->id, 'kind' => 'overdue_wbs', 'interval_hours' => 24]);
        $this->assertFalse($check->enabled);
        $this->assertSame(0, $service->runDue());
        $this->actingAsPurchaseUser($this->owner)->postJson('/ask-api/workspace/checks/'.$check->id.'/activate', ['confirmed' => true, 'version' => $check->approval_version])->assertConflict();
        config(['ai_assistant.checks_enabled' => true]);
        $this->postJson('/ask-api/workspace/checks/'.$check->id.'/activate', ['confirmed' => false, 'version' => $check->approval_version])->assertUnprocessable();
        $this->assertFalse($check->fresh()->enabled);
        $this->postJson('/ask-api/workspace/checks/'.$check->id.'/activate', ['confirmed' => true, 'version' => $check->approval_version])->assertOk();
        $this->assertTrue($check->fresh()->enabled);
    }

    public function test_checks_are_idempotent_private_and_stop_when_scope_changes(): void
    {
        config(['ai_assistant.checks_enabled' => true]);
        $this->row('AI-LATE');
        $service = app(AssistantCheckService::class);
        $check = $service->save($this->owner, ['site_id' => $this->site->id, 'kind' => 'overdue_wbs', 'interval_hours' => 6]);
        $service->activate($this->owner, $check->id, true, $check->approval_version);
        $this->assertSame(1, $service->runDue());
        $this->assertSame(0, $service->runDue());
        $this->assertSame(1, $check->fresh()->last_result['count']);
        $other = User::factory()->create(['access_role' => 'super_admin', 'account_status' => 'active']);
        $this->assertSame([], $service->list($other));
        $this->owner->update(['account_status' => 'suspended']);
        $check->update(['next_run_at' => now()->subMinute()]);
        $this->assertSame(0, $service->runDue());
        $this->assertFalse($check->fresh()->enabled);
        $this->assertNull($check->fresh()->last_result);
        Http::assertNothingSent();
    }

    public function test_editing_an_active_check_withdraws_previous_activation(): void
    {
        config(['ai_assistant.checks_enabled' => true]);
        $service = app(AssistantCheckService::class);
        $check = $service->save($this->owner, ['site_id' => $this->site->id, 'kind' => 'overdue_wbs', 'interval_hours' => 24]);
        $service->activate($this->owner, $check->id, true, $check->approval_version);
        $edited = $service->save($this->owner, ['site_id' => $this->site->id, 'kind' => 'overdue_wbs', 'interval_hours' => 1]);
        $this->assertSame($check->id, $edited->id);
        $this->assertFalse($edited->enabled);
        $this->assertNull($edited->approved_at);
        $this->assertDatabaseCount('assistant_checks', 1);
    }

    public function test_saved_check_cannot_be_activated_by_another_actor(): void
    {
        config(['ai_assistant.checks_enabled' => true]);
        $check = app(AssistantCheckService::class)->save($this->owner, ['site_id' => $this->site->id, 'kind' => 'overdue_wbs', 'interval_hours' => 24]);
        $other = User::factory()->create(['access_role' => 'super_admin', 'account_status' => 'active']);
        $this->actingAsPurchaseUser($other)->postJson('/ask-api/workspace/checks/'.$check->id.'/activate', ['confirmed' => true, 'version' => $check->approval_version])->assertNotFound();
    }

    public function test_stale_tab_cannot_approve_a_changed_interval(): void
    {
        config(['ai_assistant.checks_enabled' => true]);
        $service = app(AssistantCheckService::class);
        $old = $service->save($this->owner, ['site_id' => $this->site->id, 'kind' => 'overdue_wbs', 'interval_hours' => 24]);
        $new = $service->save($this->owner, ['site_id' => $this->site->id, 'kind' => 'overdue_wbs', 'interval_hours' => 1]);
        $this->actingAsPurchaseUser($this->owner)->postJson('/ask-api/workspace/checks/'.$new->id.'/activate', ['confirmed' => true, 'version' => $old->approval_version])->assertConflict();
        $this->assertFalse($new->fresh()->enabled);
        $this->postJson('/ask-api/workspace/checks/'.$new->id.'/activate', ['confirmed' => true, 'version' => $new->approval_version])->assertOk();
    }
}
