<?php

namespace Tests\Feature;

use App\Models\CommunicationMessage;
use App\Models\CommunicationRoom;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\IntegratedDocument;
use App\Models\OpsActionItem;
use App\Models\OpsIntakeItem;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WbsItem;
use App\Services\Communication\ChatAssistant;
use App\Services\Communication\CommunicationService;
use App\Services\Communication\RoomStreamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Regression coverage for legacy routes bypassing role, site and document checks. */
class LegacyBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function worker(): User
    {
        return User::factory()->create(['access_role' => 'worker', 'access_scope' => 'self', 'account_status' => 'active']);
    }

    private function document(string $type = 'other'): IntegratedDocument
    {
        Storage::fake('local');
        Storage::disk('local')->put('audit.txt', 'AUDIT SYNTHETIC CONTENT');

        return IntegratedDocument::create(['title' => 'Audit synthetic only', 'document_type' => $type, 'disk' => 'local', 'path' => 'audit.txt', 'original_name' => 'audit.txt', 'mime_type' => 'text/plain', 'status' => 'confirmed']);
    }

    public function test_boundary_worker_cannot_read_money_document_original(): void
    {
        $d = $this->document('payroll_record');
        $this->actingAs($this->worker())->get('/docs-api/file/'.$d->id)->assertNotFound();
    }

    public function test_boundary_worker_cannot_delete_another_document(): void
    {
        $d = $this->document();
        $this->actingAs($this->worker())->postJson('/smart-company-api/api_deleteDoc', ['args' => [$d->id]])->assertOk()->assertJson(['success' => false]);
        $this->assertDatabaseHas('integrated_documents', ['id' => $d->id]);
    }

    public function test_boundary_worker_cannot_confirm_document(): void
    {
        $d = $this->document();
        $d->update(['status' => 'needs_review']);
        $this->actingAs($this->worker())->postJson('/smart-company-api/api_confirmDoc', ['args' => [$d->id, '01']])->assertOk()->assertJson(['success' => false]);
        $this->assertSame('needs_review', $d->fresh()->status);
    }

    public function test_boundary_worker_cannot_delete_ops_action(): void
    {
        $a = OpsActionItem::create(['title' => 'Audit action', 'kind' => 'todo', 'status' => 'open']);
        $this->actingAs($this->worker())->postJson('/smart-company-api/api_deleteOpsAction', ['args' => [$a->id]])->assertOk()->assertJson(['success' => false]);
        $this->assertDatabaseHas('ops_action_items', ['id' => $a->id]);
    }

    public function test_boundary_suspended_session_cannot_read_original(): void
    {
        $d = $this->document();
        $u = $this->worker();
        $u->update(['account_status' => 'suspended']);
        $this->actingAs($u)->get('/docs-api/file/'.$d->id)->assertForbidden();
    }

    public function test_boundary_worker_cannot_browse_other_site_documents(): void
    {
        $a = Site::create(['code' => 'AUD-A', 'name' => 'Audit site A', 'status' => 'active']);
        $b = Site::create(['code' => 'AUD-B', 'name' => 'Audit site B', 'status' => 'active']);
        $d = $this->document();
        $d->update(['site_id' => $b->id, 'folder_code' => '03']);
        $u = $this->worker();
        $u->update(['access_scope' => 'site', 'allowed_site_id' => $a->id]);
        $this->actingAs($u)->postJson('/smart-company-api/api_getDocFolder', ['siteId' => (string) $b->id, 'args' => ['03']])->assertOk()->assertJsonPath('success', false);
    }

    public function test_boundary_document_status_exposes_other_money_summary(): void
    {
        $d = $this->document('payroll_record');
        $d->update(['summary' => ['AUDIT payroll synthetic']]);
        $this->actingAs($this->worker())->getJson('/docs-api/status?ids='.$d->id)->assertOk()->assertJsonPath('documents', []);
    }

    public function test_boundary_worker_cannot_delete_equipment(): void
    {
        $e = Equipment::create(['equipment_code' => 'AUD-EQ-1', 'equipment_type' => 'tool', 'model' => 'Audit synthetic', 'status' => 'available']);
        $this->actingAs($this->worker())->postJson('/equipment-api/'.$e->id.'/delete')->assertForbidden();
        $this->assertDatabaseHas('equipments', ['id' => $e->id]);
    }

    public function test_boundary_worker_cannot_return_other_vehicle(): void
    {
        $v = Vehicle::create(['model' => 'Audit synthetic', 'status' => '운행중', 'current_mileage' => 10]);
        $this->actingAs($this->worker())->postJson('/vehicle-api/return', ['vehicle_id' => $v->id, 'current_mileage' => 11])->assertForbidden();
        $this->assertSame(10, (int) $v->fresh()->current_mileage);
    }

    public function test_boundary_unknown_write_endpoint_rejects_operation(): void
    {
        $this->actingAs($this->worker())->postJson('/smart-company-api/api_auditNotImplemented', ['args' => []])->assertOk()->assertJson(['success' => false]);
    }

    public function test_boundary_shared_ai_answer_does_not_expose_admin_financial_facts(): void
    {
        config(['services.anthropic.api_key' => 'audit-fake-key']);
        Http::fake(['*' => Http::response(['content' => [['type' => 'text', 'text' => 'AUDIT finance answer 12345 USD']], 'stop_reason' => 'end_turn'])]);
        $c = Company::create(['code' => 'AUD-CHAT', 'name' => 'Audit', 'status' => 'active']);
        $s = Site::create(['company_id' => $c->id, 'code' => 'AUD-CHAT', 'name' => 'Audit', 'status' => 'active']);
        $room = CommunicationRoom::create(['company_id' => $c->id, 'site_id' => $s->id, 'type' => 'site_chat', 'name' => 'Audit shared', 'status' => 'active']);
        $e = Employee::create(['company_id' => $c->id, 'site_id' => $s->id, 'first_name' => 'Audit', 'last_name' => 'Worker', 'employment_status' => 'active']);
        $u = $this->worker();
        $u->update(['employee_id' => $e->id, 'allowed_site_id' => $s->id, 'allowed_company_id' => $c->id]);
        $svc = app(CommunicationService::class);
        $svc->ensureRoomMember($room, $e);
        $admin = User::factory()->create(['access_role' => 'super_admin', 'account_status' => 'active']);
        $q = CommunicationMessage::create(['communication_room_id' => $room->id, 'company_id' => $c->id, 'site_id' => $s->id, 'sender_user_id' => $admin->id, 'kind' => 'text', 'body' => '@AI 비용 알려줘', 'status' => 'active']);
        $answer = app(ChatAssistant::class)->answer($q);
        $this->assertNotNull($answer);
        $this->assertStringNotContainsString('12345', $answer->body);
        $this->assertTrue($svc->canAccessRoom($u, $room));
        $rows = app(RoomStreamService::class)->since($room, $u, 0);
        $this->assertNotContains('AUDIT finance answer 12345 USD', array_column($rows['messages'], 'body'));
    }

    public function test_boundary_worker_cannot_apply_another_site_wbs_proposal(): void
    {
        $s = Site::create(['code' => 'AUD-WBS', 'name' => 'Other site', 'status' => 'active']);
        Project::create(['project_code' => 'AUD-P', 'name' => 'Audit', 'construction_type' => 'equipment_setting']);
        $w = WbsItem::create(['project_code' => 'AUD-P', 'site_id' => $s->id, 'level' => 'subtask', 'wbs_code' => 'AUD-P-W1', 'activity_id' => 'W1', 'node_no' => '1.1', 'name' => 'Audit synthetic', 'status' => '검수완료', 'progress' => 0]);
        $i = OpsIntakeItem::create(['site_id' => $s->id, 'source' => 'paste', 'raw_text' => 'Audit', 'category' => 'progress', 'confidence' => 90, 'summary' => 'Audit', 'target_type' => 'wbs', 'target_code' => $w->wbs_code, 'proposed' => ['progress' => 60], 'status' => 'pending']);
        $this->actingAs($this->worker())->postJson('/smart-company-api/api_applyOpsItem', ['args' => [$i->id]])->assertOk()->assertJson(['success' => false]);
        $this->assertSame(0,(int) $w->fresh()->progress);
    }
}
