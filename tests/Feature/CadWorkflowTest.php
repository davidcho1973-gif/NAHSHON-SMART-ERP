<?php

namespace Tests\Feature;

use App\Models\CadDrawing;
use App\Models\CadDrawingPacket;
use App\Models\CadTask;
use App\Models\Company;
use App\Models\ContractBoqLine;
use App\Models\IntelligentDocument;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\PurchaseRequest;
use App\Models\Site;
use App\Models\Submittal;
use App\Models\User;
use App\Models\WbsItem;
use App\Models\WbsPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class CadWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $actor;

    private CadDrawing $drawing;

    private ContractBoqLine $line;

    private string $url;

    private const DXF = "0\nSECTION\n2\nENTITIES\n0\nLINE\n10\n0\n20\n0\n11\n100\n21\n50\n0\nENDSEC\n0\nEOF\n";

    private function mark(): array
    {
        return ['id' => 'zone-A', 'kind' => 'area', 'points' => [['x' => 0, 'y' => 0], ['x' => 10, 'y' => 0], ['x' => 10, 'y' => 10], ['x' => 0, 'y' => 10]], 'holes' => [[['x' => 2, 'y' => 2], ['x' => 4, 'y' => 2], ['x' => 4, 'y' => 4], ['x' => 2, 'y' => 4]]], 'color' => '#38bdf8', 'opacity' => .4, 'width' => 2, 'size' => 3, 'pattern' => 'solid', 'text' => '구역 A', 'locked' => false];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        config(['cad.disk' => 'local', 'cad.converter_binary' => null]);
        $company = Company::create(['code' => 'CAD-WORK', 'name' => 'CAD Workflow', 'status' => 'active']);
        $this->site = Site::create(['company_id' => $company->id, 'code' => 'CAD-WORK', 'name' => 'CAD Workflow', 'status' => 'active']);
        $this->actor = User::factory()->create(['access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $this->actingAs($this->actor);
        $contract = ProjectContract::create(['company_id' => $company->id, 'site_id' => $this->site->id, 'title' => 'CAD Workflow', 'direction' => 'receivable', 'status' => 'active', 'original_amount' => 1000]);
        $this->line = ContractBoqLine::create(['project_contract_id' => $contract->id, 'line_no' => '1', 'description' => 'Duct', 'unit' => 'm2', 'contract_qty' => 100, 'unit_price' => 10, 'status' => 'accepted']);
        $id = $this->postJson('/admin-api/cad', ['site_id' => $this->site->id, 'title' => 'Workflow Drawing', 'file' => UploadedFile::fake()->createWithContent('plan.dxf', self::DXF)])->assertCreated()->json('id');
        $this->drawing = CadDrawing::findOrFail($id);
        $this->url = '/admin-api/cad/'.$id.'/workflow';
        $this->putJson('/admin-api/cad/'.$id, ['revision' => 1, 'dxf' => self::DXF, 'markups' => [$this->mark()], 'workspace' => ['measurement' => ['metersPerUnit' => 1, 'unit' => 'm', 'source' => 'declared']]])->assertOk();
        $this->drawing->refresh();
    }

    public function test_links_are_idempotent_scoped_bidirectional_and_do_not_write_quantities(): void
    {
        $body = ['revision' => 2, 'markupId' => 'zone-A', 'type' => 'boq', 'targetId' => $this->line->id];
        $first = $this->postJson($this->url.'/links', $body)->assertOk()->json('locationId');
        $this->postJson($this->url.'/links', $body)->assertOk()->assertJsonPath('locationId', $first);
        $this->assertDatabaseCount('cad_locations', 1);
        $this->assertDatabaseCount('cad_location_links', 1);
        $this->assertDatabaseCount('cad_workflow_events', 1);
        $this->assertDatabaseCount('claim_work_records', 0);
        $this->assertSame('100.0000', $this->line->fresh()->contract_qty);
        $this->getJson($this->url.'/backlinks?type=boq&targetId='.$this->line->id)->assertOk()->assertJsonPath('items.0.revision', 2);
        $this->putJson('/admin-api/cad/'.$this->drawing->id, ['revision' => 2, 'dxf' => self::DXF, 'markups' => []])->assertOk();
        $this->getJson($this->url.'?revision=3')->assertOk()->assertJsonPath('locations.0.present', false)->assertJsonPath('locations.0.sourceRevision', 2);
        $this->postJson($this->url.'/links', $body)->assertConflict();
        $this->postJson($this->url.'/links', array_replace($body, ['revision' => 3]))->assertUnprocessable();
    }

    public function test_cross_site_targets_and_read_only_writers_fail_closed(): void
    {
        $other = Site::create(['company_id' => $this->site->company_id, 'code' => 'OTHER', 'name' => 'Other', 'status' => 'active']);
        $wbs = WbsItem::create(['company_id' => $this->site->company_id, 'site_id' => $other->id, 'project_code' => 'OTHER', 'wbs_code' => 'OTHER-1', 'name' => 'PRIVATE', 'level' => 'subtask', 'status' => '진행중']);
        $body = ['revision' => 2, 'markupId' => 'zone-A', 'type' => 'wbs', 'targetId' => $wbs->id];
        $this->postJson($this->url.'/links', $body)->assertNotFound();
        $this->assertDatabaseCount('cad_locations', 0);
        $viewer = User::factory()->create(['access_role' => 'payroll', 'account_status' => 'active', 'access_scope' => 'all_sites']);
        $this->actingAs($viewer)->postJson($this->url.'/links', array_replace($body, ['type' => 'boq', 'targetId' => $this->line->id]))->assertForbidden();
    }

    public function test_task_requires_evidence_review_order_and_optimistic_version(): void
    {
        $task = ['id' => (string) Str::uuid(), 'revision' => 2, 'markupId' => 'zone-A', 'kind' => 'inspection', 'title' => '압력 시험', 'details' => '배관 압력 시험 결과 검토', 'assignedUserId' => $this->actor->id, 'dueOn' => '2026-10-25'];
        $id = $this->postJson($this->url.'/tasks', $task)->assertCreated()->json('id');
        $this->postJson($this->url.'/tasks', $task)->assertCreated();
        $this->assertDatabaseCount('cad_tasks', 1);
        $action = ['version' => 1, 'requestKey' => (string) Str::uuid(), 'status' => 'closed', 'resolution' => '완료', 'evidence' => []];
        $this->putJson($this->url.'/tasks/'.$id, $action)->assertUnprocessable();
        $action['status'] = 'submitted';
        $this->putJson($this->url.'/tasks/'.$id, $action)->assertUnprocessable();
        Storage::disk('local')->put('test-photo.jpg', 'synthetic photo');
        $photo = WbsPhoto::create(['site_id' => $this->site->id, 'wbs_code' => 'TEST', 'project_code' => 'TEST', 'photo_date' => '2026-10-21', 'caption' => '압력 시험', 'disk' => 'local', 'path' => 'test-photo.jpg', 'original_path' => 'test-photo.jpg', 'original_sha256' => hash('sha256', 'synthetic photo')]);
        $action['evidence'] = [['type' => 'photo', 'id' => $photo->id]];
        $this->putJson($this->url.'/tasks/'.$id, $action)->assertOk()->assertJsonPath('version', 2)->assertJsonPath('status', 'submitted');
        $this->putJson($this->url.'/tasks/'.$id, $action)->assertOk()->assertJsonPath('version', 2);
        $action['requestKey'] = (string) Str::uuid();
        $action['status'] = 'closed';
        $this->putJson($this->url.'/tasks/'.$id, $action)->assertConflict();
        $action['version'] = 2;
        $this->putJson($this->url.'/tasks/'.$id, $action)->assertOk()->assertJsonPath('status', 'closed')->assertJsonPath('closedBy', $this->actor->id);
        $this->assertDatabaseCount('claim_work_records', 0);
        $this->assertSame(3, CadTask::find($id)->version);
    }

    public function test_design_answer_does_not_approve_or_create_a_contract_change(): void
    {
        $body = ['id' => (string) Str::uuid(), 'revision' => 2, 'markupId' => 'zone-A', 'kind' => 'design_question', 'title' => '천장 간섭', 'details' => '설계 답변 필요', 'assignedUserId' => $this->actor->id, 'dueOn' => '2026-10-25'];
        $id = $this->postJson($this->url.'/tasks', $body)->assertCreated()->json('id');
        $this->putJson($this->url.'/tasks/'.$id, ['version' => 1, 'requestKey' => (string) Str::uuid(), 'status' => 'submitted', 'resolution' => '설계자 답변: 위치 조정', 'evidence' => []])->assertOk();
        $this->assertDatabaseCount('contract_changes', 0);
    }

    public function test_purchase_basis_excludes_holes_and_only_reviewed_submit_creates_one_request(): void
    {
        $this->getJson($this->url.'/purchase-basis?revision=2&markupId=zone-A')->assertOk()->assertJsonPath('quantity', 96)->assertJsonPath('unit', 'M2');
        $body = ['id' => (string) Str::uuid(), 'version' => 0, 'revision' => 2, 'markupId' => 'zone-A', 'content' => ['need_by' => '2026-10-26', 'note' => '현장 검토 수량', 'lines' => [['name' => 'Duct insulation', 'specification' => '25mm', 'quantity' => 110, 'unit' => 'M2']]]];
        $id = $this->postJson($this->url.'/purchase-drafts', $body)->assertOk()->assertJsonPath('content.basis.quantity', 96)->json('id');
        $this->assertDatabaseCount('purchase_requests', 0);
        $this->postJson($this->url.'/purchase-drafts/'.$id.'/submit', ['version' => 1, 'reviewed' => false])->assertUnprocessable();
        $requestId = $this->postJson($this->url.'/purchase-drafts/'.$id.'/submit', ['version' => 1, 'reviewed' => true])->assertOk()->json('request.id');
        $this->postJson($this->url.'/purchase-drafts/'.$id.'/submit', ['version' => 1, 'reviewed' => true])->assertOk()->assertJsonPath('request.id', $requestId);
        $this->assertDatabaseCount('purchase_requests', 1);
        $this->assertDatabaseCount('purchase_request_orders', 0);
        $this->assertDatabaseCount('material_receipts', 0);
        $this->assertSame('110.000', PurchaseRequest::find($requestId)->lines()->first()->quantity);
        $this->assertSame('100.0000', $this->line->fresh()->contract_qty);
    }

    private function packetPayload(): array
    {
        $source = $this->getJson('/admin-api/cad/'.$this->drawing->id)->assertOk()->json();

        return ['id' => (string) Str::uuid(), 'revision' => 2, 'snapshotToken' => $source['snapshotToken'], 'title' => '검토본', 'reviewed' => true,
            'pdf' => UploadedFile::fake()->createWithContent('marked.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n")];
    }

    public function test_catalog_reads_canonical_wbs_submittals_documents_and_rechecks_packet_access(): void
    {
        $project = Project::create(['company_id' => $this->site->company_id, 'site_id' => $this->site->id, 'project_code' => 'CAD-W', 'name' => 'CAD Workflow', 'construction_type' => 'mechanical']);
        $wbs = WbsItem::create(['company_id' => $this->site->company_id, 'site_id' => $this->site->id, 'project_id' => $project->id, 'project_code' => 'CAD-W', 'wbs_code' => 'A.1', 'level' => 'task', 'name' => 'DUCT', 'status' => '진행중', 'progress' => 40]);
        $this->getJson($this->url.'/targets?type=wbs')->assertOk()->assertJsonPath('items.0.progress', 40);
        $wbs->update(['status' => '완료']);
        $this->getJson($this->url.'/targets?type=wbs')->assertJsonPath('items.0.progress', 100);
        $bytes = '%PDF-1.4 SOURCE';
        Storage::disk('local')->put('approved.pdf', $bytes);
        $doc = IntelligentDocument::create(['uuid' => (string) Str::uuid(), 'company_id' => $this->site->company_id, 'site_id' => $this->site->id, 'disk' => 'local', 'stored_file_name' => 'approved.pdf', 'file_path' => 'approved.pdf', 'original_file_name' => 'approved.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'title' => 'Approved product', 'access_level' => 'site', 'sha256' => hash('sha256', $bytes)]);
        $sub = Submittal::create(['company_id' => $this->site->company_id, 'site_id' => $this->site->id, 'project_id' => $project->id, 'seq' => 1, 'csi' => '23 0700', 'section' => 'HVAC', 'category' => 'Action', 'title' => 'Duct insulation approval', 'status' => '승인']);
        $sub->documents()->attach($doc->id, ['kind' => 'approved']);
        $this->getJson($this->url.'/targets?type=submittal')->assertOk()->assertJsonPath('items.0.status', '승인')->assertJsonPath('items.0.documents.0.id', $doc->id);
        $body = $this->packetPayload();
        $body['references'] = [['type' => 'submittal', 'id' => $sub->id]];
        $packet = $this->postJson($this->url.'/packets', $body)->assertCreated()->json();
        $this->get($packet['url'])->assertOk();
        $doc->update(['access_level' => 'private', 'owner_user_id' => User::factory()->create()->id]);
        $this->getJson($packet['url'])->assertNotFound();
        $this->getJson($this->url.'/packets')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson($this->url.'/targets?type=document')->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_packet_is_an_immutable_hash_checked_source_snapshot_and_retries_are_idempotent(): void
    {
        $body = $this->packetPayload();
        $pdf = $body['pdf']->get();
        $packet = $this->postJson($this->url.'/packets', $body)->assertCreated()->json();
        $this->postJson($this->url.'/packets', $body)->assertCreated()->assertJsonPath('id', $packet['id']);
        $this->assertDatabaseCount('cad_drawing_packets', 1);
        $bytes = $this->get($packet['url'])->assertOk()->getContent();
        $this->assertSame($packet['sha256'], hash('sha256', $bytes));
        $temp = tempnam(sys_get_temp_dir(), 'cad-verify-');
        file_put_contents($temp, $bytes);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($temp));
        $this->assertSame(self::DXF, $zip->getFromName('original.dxf'));
        $this->assertSame(self::DXF, $zip->getFromName('revision.dxf'));
        $this->assertSame($pdf, $zip->getFromName('marked.pdf'));
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        foreach ($manifest['files'] as $file) {
            $this->assertSame($file['sha256'], hash('sha256', $zip->getFromName($file['name'])));
        }$zip->close();
        unlink($temp);
        $this->putJson('/admin-api/cad/'.$this->drawing->id, ['revision' => 2, 'dxf' => self::DXF, 'markups' => []])->assertOk();
        $this->get($packet['url'])->assertContent($bytes);
        $this->postJson($this->url.'/packets', array_replace($body, ['title' => 'Different']))->assertConflict();
        $this->postJson($this->url.'/packets', array_replace($body, ['id' => (string) Str::uuid(), 'snapshotToken' => str_repeat('0', 64)]))->assertConflict();
        $this->get($packet['pdfUrl'].'&inline=1')->assertHeader('Content-Type', 'application/pdf')->assertContent($pdf);
        $row = CadDrawingPacket::find($packet['id']);
        Storage::disk($row->disk)->put($row->pdf_path, 'tampered');
        $this->getJson($packet['pdfUrl'])->assertConflict();
    }

    public function test_packet_mail_sends_exact_saved_pdf_once_and_changed_retries_conflict(): void
    {
        $body = $this->packetPayload();
        $pdf = $body['pdf']->get();
        $packet = $this->postJson($this->url.'/packets', $body)->assertCreated()->json();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.org', 'mail.from.address' => 'office@example.org']);
        Mail::shouldReceive('raw')->once()->andReturnUsing(function ($body, $callback) use ($pdf) {
            $email = new Email;
            $callback(new Message($email));
            $this->assertSame($pdf, $email->getAttachments()[0]->getBody());
            $this->assertSame('review@example.org', $email->getTo()[0]->getAddress());
        });
        $request = ['requestId' => (string) Str::uuid(), 'channel' => 'mail', 'to' => 'review@example.org', 'subject' => '검토본', 'body' => '확인한 PDF', 'reviewed' => true];
        $url = $this->url.'/packets/'.$packet['id'].'/share';
        $this->postJson($url, $request)->assertOk()->assertJsonPath('status', 'sent');
        $this->postJson($url, $request)->assertOk()->assertJsonPath('duplicate', true);
        $this->postJson($url, array_replace($request, ['body' => 'Changed']))->assertConflict();
        $this->assertDatabaseCount('drawing_share_dispatches',1);
    }
}
