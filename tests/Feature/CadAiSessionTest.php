<?php

namespace Tests\Feature;

use App\Mcp\Tools\CadSessionTool;
use App\Models\CadAiCommand;
use App\Models\CadAiSession;
use App\Models\CadDrawing;
use App\Models\Company;
use App\Models\Site;
use App\Models\User;
use App\OAuth\ErpTokenFamily;
use App\Services\Auth\EmailPasswordAuthService;
use App\Services\Auth\PersonalAppAccessService;
use App\Services\Drawings\CadAiSchema;
use App\Services\Drawings\CadAiSessionService;
use App\Support\Mcp\CadMcpOAuth;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Passport\Passport;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class CadAiSessionTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private CadDrawing $drawing;

    private string $client;

    private string $family;

    private const HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const BASE = '/admin-api/cad/ai/sessions';

    protected function setUp(): void
    {
        parent::setUp();
        config(['cad_ai.enabled' => true, 'cad_ai.origin' => 'https://erp.example.test', 'cad.disk' => 'local', 'cad.converter_binary' => null]);
        Storage::fake('local');
        $company = Company::create(['code' => 'CAD-AI', 'name' => 'CAD AI', 'status' => 'active']);
        $site = Site::create(['company_id' => $company->id, 'code' => 'CAD-AI', 'name' => 'CAD AI', 'status' => 'active']);
        $this->actor = User::factory()->create(['account_status' => 'active', 'access_role' => 'super_admin', 'access_scope' => 'all_sites']);
        $this->actingAs($this->actor)->withSession([EmailPasswordAuthService::STRONG_AUTH_SESSION => $this->actor->id]);
        $id = $this->postJson('/admin-api/cad', ['site_id' => $site->id, 'title' => 'Explicit CAD', 'file' => new UploadedFile(base_path('tests/Fixtures/cad-line.dxf'), 'line.dxf', 'application/dxf', null, true)])->assertCreated()->json('id');
        $this->drawing = CadDrawing::findOrFail($id);
        [$this->client, $this->family] = $this->connection();
    }

    private function connection(): array
    {
        $client = Passport::client()->newQuery()->create(['id' => (string) Str::uuid(), 'name' => 'Synthetic CAD Client', 'secret' => null, 'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false]);
        DB::table('cad_ai_oauth_clients')->insert(['client_id' => $client->id, 'created_at' => now()]);
        $family = ErpTokenFamily::create();
        Passport::token()->newQuery()->create(['id' => bin2hex(random_bytes(40)), 'user_id' => $this->actor->id, 'client_id' => $client->id, 'name' => 'CAD test',
            'scopes' => ['cad:session'], 'revoked' => false, 'expires_at' => now()->utc()->addHour(), 'resource' => CadMcpOAuth::resource(), 'issuer' => CadMcpOAuth::issuer(), 'family_id' => $family]);

        return [$client->id, $family];
    }

    private function context(): array
    {
        return ['version' => 1, 'documentHash' => self::HASH, 'unit' => 'm', 'scope' => 'selection', 'selection' => ['line-1'],
            'entities' => [['id' => 'line-1', 'type' => 'LINE', 'layer' => '0', 'editable' => true, 'geometry' => ['p' => ['x' => 0, 'y' => 0], 'q' => ['x' => 3, 'y' => 4]],
                'style' => ['color' => null, 'lineType' => 'BYLAYER', 'lineWeight' => -1], 'quantities' => ['length' => 999999, 'area' => null, 'available' => true, 'reason' => null]]],
            'layers' => [['name' => '0', 'color' => '#ffffff', 'visible' => true, 'locked' => false]], 'totals' => ['length' => 999999, 'area' => 0, 'unavailableCount' => 0]];
    }

    private function share(bool $edits = true, ?array $context = null): array
    {
        return $this->postJson(self::BASE, ['drawingId' => $this->drawing->id, 'revision' => 1, 'context' => $context ?? $this->context(), 'allowEdits' => $edits])->assertCreated()->json();
    }

    private function service(): CadAiSessionService
    {
        return app(CadAiSessionService::class);
    }

    private function action(): array
    {
        return [['type' => 'move', 'entityIds' => ['line-1'], 'dx' => 1.0, 'dy' => 0.0]];
    }

    private function submit(array $share, string $key = 'test-command', ?array $actions = null, ?string $hash = null): array
    {
        return $this->service()->submit($this->actor, $this->client, $share['sessionId'], $hash ?? self::HASH, $key, $actions ?? $this->action(), $this->family);
    }

    private function denied(int $status, Closure $callback): void
    {
        try {
            $callback();
            $this->fail('Expected access or schema rejection.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        } catch (ModelNotFoundException) {
            $this->assertSame(404, $status);
        } catch (ValidationException) {
            $this->assertSame(422, $status);
        }
    }

    public function test_owner_browser_token_queue_ack_and_retry_never_save_the_drawing(): void
    {
        $share = $this->share();
        $this->assertSame(64, strlen($share['editorToken']));
        $session = CadAiSession::first();
        $this->assertNotSame($share['editorToken'], $session->editor_token_hash);
        $this->getJson(self::BASE.'/'.$share['sessionId'].'/commands')->assertForbidden();
        $command = $this->submit($share);
        $retry = $this->submit($share);
        $this->assertSame($command['id'], $retry['id']);
        $this->assertFalse($command['applied']);
        $this->assertDatabaseCount('cad_ai_commands', 1);
        $headers = ['X-CAD-Editor-Token' => $share['editorToken']];
        $this->getJson(self::BASE.'/'.$share['sessionId'].'/commands', $headers)->assertOk()->assertJsonPath('commands.0.id', $command['id']);
        $context = $this->context();
        $context['documentHash'] = str_repeat('b', 64);
        $context['entities'][0]['geometry']['p']['x'] = 1;
        $this->postJson(self::BASE.'/'.$share['sessionId'].'/commands/'.$command['id'].'/result', ['status' => 'applied', 'message' => 'Applied one browser move.', 'context' => $context], $headers)->assertOk()->assertJsonPath('status', 'applied');
        $this->assertSame('applied', $this->service()->commandStatus($this->actor, $this->client, $share['sessionId'], $command['id'])['status']);
        $this->assertSame(str_repeat('b', 64), $this->service()->read($this->actor, $share['sessionId'])['documentHash']);
        $this->assertDatabaseCount('cad_drawing_revisions', 1);
        $this->assertSame(1, (int) $this->drawing->fresh()->revision);
        $this->assertDatabaseCount('claim_work_records', 0);
        $this->denied(409, fn () => $this->submit($share, 'other-key'));
    }

    public function test_no_implicit_sharing_and_other_user_cannot_read_poll_or_submit(): void
    {
        $this->assertSame([], $this->service()->list($this->actor)['sessions']);
        $share = $this->share();
        $other = User::factory()->create(['account_status' => 'active', 'access_role' => 'super_admin', 'access_scope' => 'all_sites']);
        $this->assertSame([], $this->service()->list($other)['sessions']);
        $this->denied(404, fn () => $this->service()->read($other, $share['sessionId']));
        $this->denied(404, fn () => $this->service()->submit($other, $this->client, $share['sessionId'], self::HASH, 'other', $this->action(), $this->family));
        $this->actingAs($other)->withSession([EmailPasswordAuthService::STRONG_AUTH_SESSION => $other->id]);
        $this->getJson(self::BASE.'/'.$share['sessionId'].'/commands', ['X-CAD-Editor-Token' => $share['editorToken']])->assertNotFound();
    }

    public function test_read_only_share_locked_entities_stale_hash_unknown_operations_and_retry_collision_fail_closed(): void
    {
        $readOnly = $this->share(false);
        $this->denied(403, fn () => $this->submit($readOnly));
        $context = $this->context();
        $context['layers'][0]['locked'] = true;
        $locked = $this->share(true, $context);
        $this->denied(422, fn () => $this->submit($locked));
        $share = $this->share();
        $this->denied(409, fn () => $this->submit($share, hash: str_repeat('b', 64)));
        $this->denied(422, fn () => $this->submit($share, actions: [['type' => 'execute', 'code' => 'save()']]));
        $this->denied(422, fn () => $this->submit($share, actions: [['type' => 'move', 'entityIds' => ['unshared-id'], 'dx' => 1, 'dy' => 0]]));
        $this->denied(422, fn () => $this->submit($share, actions: [['type' => 'move', 'entityIds' => ['line-1'], 'dx' => null, 'dy' => 0]]));
        $this->submit($share);
        $this->denied(409, fn () => $this->submit($share, actions: [['type' => 'delete', 'entityIds' => ['line-1']]]));
    }

    public function test_stop_and_expiry_redact_context_actions_results_and_browser_credentials(): void
    {
        $share = $this->share();
        $command = $this->submit($share);
        $this->service()->result($this->actor, $share['sessionId'], $share['editorToken'], $command['id'], 'failed', 'Synthetic browser failure.', null);
        $this->assertNotNull(CadAiCommand::first()->result);
        $this->deleteJson(self::BASE.'/'.$share['sessionId'], [], ['X-CAD-Editor-Token' => $share['editorToken']])->assertOk();
        $this->assertNull(CadAiSession::first()->context);
        $this->assertNull(CadAiSession::first()->editor_token_hash);
        $this->assertNull(CadAiCommand::first()->actions);
        $this->assertNull(CadAiCommand::first()->result);
        $this->denied(410, fn () => $this->service()->read($this->actor, $share['sessionId']));
        $second = $this->share();
        $this->travel(16)->minutes();
        $this->artisan('cad-ai:prune')->assertSuccessful();
        $this->assertNull(CadAiSession::where('uuid', $second['sessionId'])->first()->context);
        $this->denied(410, fn () => $this->service()->read($this->actor, $second['sessionId']));
    }

    public function test_current_site_scope_and_export_permission_are_rechecked_on_every_read(): void
    {
        $share = $this->share();
        $otherSite = Site::create(['company_id' => $this->drawing->company_id, 'code' => 'AI-OTHER', 'name' => 'Other', 'status' => 'active']);
        $this->actor->update(['access_role' => 'site_manager', 'access_scope' => 'site', 'allowed_site_id' => $otherSite->id, 'allowed_company_id' => $this->drawing->company_id]);
        $this->denied(404, fn () => $this->service()->read($this->actor, $share['sessionId']));
        $this->assertNull(CadAiSession::first()->context);
        $this->actor->forceFill(['access_role' => 'admin', 'access_scope' => 'site', 'allowed_site_id' => $this->drawing->site_id,
            'job_role' => 'engineering', 'job_site_ids' => [$this->drawing->site_id], 'job_permissions' => ['progress' => ['view', 'edit']]])->save();
        $this->denied(403, fn () => $this->service()->create($this->actor, $this->drawing->id, 1, $this->context(), false));
        $this->actor->forceFill(['job_permissions' => ['progress' => ['view', 'edit'], 'documents' => ['view', 'export']]])->save();
        $allowed = $this->service()->create($this->actor, $this->drawing->id, 1, $this->context(), false);
        $this->actor->forceFill(['job_permissions' => ['progress' => ['view', 'edit']]])->save();
        $this->denied(403, fn () => $this->service()->read($this->actor, $allowed['sessionId']));
    }

    public function test_oauth_client_and_grant_family_revocation_block_delivery_ack_and_status(): void
    {
        $share = $this->share();
        $command = $this->submit($share);
        [$otherClient] = $this->connection();
        $this->denied(404, fn () => $this->service()->commandStatus($this->actor, $otherClient, $share['sessionId'], $command['id']));
        ErpTokenFamily::revoke($this->family);
        $this->getJson(self::BASE.'/'.$share['sessionId'].'/commands', ['X-CAD-Editor-Token' => $share['editorToken']])->assertOk()->assertJsonCount(0, 'commands');
        $this->denied(403, fn () => $this->service()->commandStatus($this->actor, $this->client, $share['sessionId'], $command['id']));
        $this->postJson(self::BASE.'/'.$share['sessionId'].'/commands/'.$command['id'].'/result', ['status' => 'applied', 'message' => '', 'context' => $this->context()], ['X-CAD-Editor-Token' => $share['editorToken']])->assertForbidden();
        $this->assertSame('rejected', CadAiCommand::first()->status);
    }

    public function test_deterministic_quantity_calculation_uses_geometry_and_rejects_guessed_units(): void
    {
        $share = $this->share(false);
        $quantity = $this->service()->quantities($this->actor, $share['sessionId']);
        $this->assertSame(5.0, $quantity['length']);
        $this->assertEquals(0.0, $quantity['area']);
        $this->assertTrue($quantity['notVerifiedWork']);
        $inches = $this->service()->quantities($this->actor, $share['sessionId'], [], 'in');
        $this->assertEqualsWithDelta(5 / .0254, $inches['length'], 1e-8);
        $context = $this->context();
        $context['unit'] = 'drawing';
        $unspecified = $this->share(false, $context);
        $this->denied(422, fn () => $this->service()->quantities($this->actor, $unspecified['sessionId'], [], 'm'));
    }

    public function test_heartbeat_invalidates_pending_commands_and_can_publish_empty_result_after_delete(): void
    {
        $share = $this->share();
        $command = $this->submit($share);
        $context = $this->context();
        $context['documentHash'] = str_repeat('c', 64);
        $headers = ['X-CAD-Editor-Token' => $share['editorToken']];
        $this->putJson(self::BASE.'/'.$share['sessionId'], ['context' => $context, 'allowEdits' => false], $headers)->assertOk();
        $this->assertSame('rejected', CadAiCommand::first()->status);
        $this->postJson(self::BASE.'/'.$share['sessionId'].'/commands/'.$command['id'].'/result', ['status' => 'applied', 'message' => '', 'context' => $context], $headers)->assertConflict();
        $context['entities'] = [];
        $context['selection'] = [];
        $context['layers'] = [];
        $context['totals'] = ['length' => 0, 'area' => 0, 'unavailableCount' => 0];
        $this->putJson(self::BASE.'/'.$share['sessionId'], ['context' => $context, 'allowEdits' => false], $headers)->assertOk();
        $this->assertSame([], $this->service()->read($this->actor, $share['sessionId'])['entities']);
    }

    public function test_circle_arc_polygon_area_and_unsupported_exclusions_are_computed_without_browser_totals(): void
    {
        $context = $this->context();
        $template = $context['entities'][0];
        foreach ([
            ['circle', 'CIRCLE', ['center' => ['x' => 0, 'y' => 0], 'radius' => 2]],
            ['arc', 'ARC', ['center' => ['x' => 0, 'y' => 0], 'radius' => 2, 'start' => 0, 'end' => 90]],
            ['rectangle', 'LWPOLYLINE', ['points' => [['x' => 0, 'y' => 0], ['x' => 3, 'y' => 0], ['x' => 3, 'y' => 4], ['x' => 0, 'y' => 4]], 'closed' => true]],
        ] as [$id, $type, $geometry]) {
            $entity = $template;
            $entity['id'] = $id;
            $entity['type'] = $type;
            $entity['geometry'] = $geometry;
            $context['entities'][] = $entity;
        }
        foreach ([
            ['annotation', 'TEXT', ['p' => ['x' => 0, 'y' => 0], 'text' => 'Untrusted annotation', 'height' => 2.5, 'width' => 0, 'angle' => 0]],
            ['proxy', 'HATCH', null], ['nonplanar', 'LINE', null],
            ['curve', 'LWPOLYLINE', ['points' => [['x' => 0, 'y' => 0, 'bulge' => 1], ['x' => 3, 'y' => 4]], 'closed' => false]],
        ] as [$id, $type, $geometry]) {
            $entity = $template;
            $entity['id'] = $id;
            $entity['type'] = $type;
            $entity['geometry'] = $geometry;
            $entity['editable'] = false;
            $entity['quantities'] = ['length' => null, 'area' => null, 'available' => false, 'reason' => 'unsupported_or_annotation'];
            $context['entities'][] = $entity;
        }
        $context['totals']['unavailableCount'] = 4;
        $share = $this->share(false, $context);
        $result = $this->service()->quantities($this->actor, $share['sessionId'], [], 'mm');
        $this->assertEqualsWithDelta((19 + 5 * M_PI) * 1000, $result['length'], 1e-7);
        $this->assertEqualsWithDelta((12 + 4 * M_PI) * 1000000, $result['area'], .00001);
        $this->assertSame(4, $result['includedCount']);
        $this->assertSame(4, $result['excludedCount']);
        $this->assertCount(4, $result['excluded']);
    }

    public function test_context_limits_and_unknown_nested_properties_are_rejected_without_silent_truncation(): void
    {
        $context = $this->context();
        $context['rawDxf'] = '0 SECTION ...';
        $this->postJson(self::BASE, ['drawingId' => $this->drawing->id, 'revision' => 1, 'context' => $context, 'allowEdits' => false])->assertUnprocessable();
        $context = $this->context();
        $context['entities'][0]['geometry']['script'] = 'window.save()';
        $this->postJson(self::BASE, ['drawingId' => $this->drawing->id, 'revision' => 1, 'context' => $context, 'allowEdits' => false])->assertUnprocessable();
        $context = $this->context();
        $context['entities'] = array_fill(0, 5001, $context['entities'][0]);
        $this->postJson(self::BASE, ['drawingId' => $this->drawing->id, 'revision' => 1, 'context' => $context, 'allowEdits' => false])->assertStatus(413);
        $this->assertDatabaseCount('cad_ai_sessions', 0);
    }

    public function test_read_pagination_and_tool_schemas_expose_only_named_shared_operations(): void
    {
        $context = $this->context();
        $second = $context['entities'][0];
        $second['id'] = 'line-2';
        $context['entities'][] = $second;
        $share = $this->share(false, $context);
        $page = $this->service()->read($this->actor, $share['sessionId'], ['offset' => 0, 'limit' => 1]);
        $this->assertSame(1, $page['nextOffset']);
        $this->assertSame(2, $page['totalEntities']);
        $this->assertArrayNotHasKey('original_path', $page);
        $tool = new CadSessionTool('submit_cad_actions');
        $schema = $tool->toArray();
        $this->assertFalse($schema['inputSchema']['additionalProperties']);
        $this->assertCount(10, $schema['inputSchema']['properties']['actions']['items']['oneOf']);
        $this->assertSame(['cad:session'], $schema['securitySchemes'][0]['scopes']);
        $read = new CadSessionTool('read_cad_context');
        $response = $read->handle(new McpRequest(['sessionId' => $share['sessionId'], 'limit' => 1]));
        $this->assertFalse($response->isError());
        $json = json_decode($response->content()->toTool($read)['text'], true);
        $this->assertSame('current_browser_snapshot', $json['source']);
        $bad = $read->handle(new McpRequest(['sessionId' => $share['sessionId'], 'sql' => 'select *']));
        $this->assertTrue($bad->isError());
    }

    public function test_weak_or_personal_app_sessions_cannot_start_sharing(): void
    {
        $this->withSession([EmailPasswordAuthService::STRONG_AUTH_SESSION => 0]);
        $this->postJson(self::BASE, ['drawingId' => $this->drawing->id, 'revision' => 1, 'context' => $this->context(), 'allowEdits' => true])->assertForbidden();
        $this->withSession([EmailPasswordAuthService::STRONG_AUTH_SESSION => 0, PersonalAppAccessService::LEGACY_SESSION => true]);
        $this->postJson(self::BASE, ['drawingId' => $this->drawing->id, 'revision' => 1, 'context' => $this->context(), 'allowEdits' => true])->assertForbidden();
        $this->assertDatabaseCount('cad_ai_sessions', 0);
    }

    public function test_action_schema_rejects_nested_extras_and_cumulative_target_overflow_and_keeps_partial_text(): void
    {
        $schema = app(CadAiSchema::class);
        $context = $this->context();
        $this->denied(422, fn () => $schema->actions([['type' => 'add_line', 'p' => ['x' => 0, 'y' => 0, 'url' => 'https://invalid'], 'q' => ['x' => 1, 'y' => 1], 'layer' => '0']], $context));
        $this->denied(422, fn () => $schema->actions([['type' => 'style', 'entityIds' => ['line-1']]], $context));
        for ($i = 2; $i <= 11; $i++) {
            $entity = $context['entities'][0];
            $entity['id'] = 'line-'.$i;
            $context['entities'][] = $entity;
        }
        $many = array_fill(0, 100, ['type' => 'move', 'entityIds' => array_column($context['entities'], 'id'), 'dx' => 1, 'dy' => 1]);
        $this->denied(422, fn () => $schema->actions($many, $context));
        $text = $context['entities'][0];
        $text['id'] = 'text-1';
        $text['type'] = 'TEXT';
        $text['geometry'] = ['p' => ['x' => 0, 'y' => 0], 'text' => 'Original content', 'height' => 2.5, 'width' => 0, 'angle' => 0];
        $context['entities'][] = $text;
        $partial = [['type' => 'text', 'entityIds' => ['text-1'], 'color' => '#ff0000']];
        $this->assertSame($partial, $schema->actions($partial, $context));
        $clear = [['type' => 'text', 'entityIds' => ['text-1'], 'text' => '']];
        $this->assertSame($clear, $schema->actions($clear, $context));
    }

    public function test_managed_browser_share_needs_export_and_write_permission_is_separate(): void
    {
        $this->actor->forceFill(['access_role' => 'admin', 'access_scope' => 'site', 'allowed_site_id' => $this->drawing->site_id,
            'allowed_company_id' => $this->drawing->company_id, 'job_role' => 'engineering', 'job_site_ids' => [$this->drawing->site_id],
            'job_permissions' => ['progress' => ['view'], 'documents' => ['view', 'export']]])->save();
        $share = $this->share(false);
        $headers = ['X-CAD-Editor-Token' => $share['editorToken']];
        $this->getJson(self::BASE.'/'.$share['sessionId'].'/commands', $headers)->assertOk();
        $this->putJson(self::BASE.'/'.$share['sessionId'], ['context' => $this->context(), 'allowEdits' => true], $headers)->assertForbidden();
        $this->actor->forceFill(['job_permissions' => ['progress' => ['view', 'edit'], 'documents' => ['view', 'export']]])->save();
        $this->putJson(self::BASE.'/'.$share['sessionId'], ['context' => $this->context(), 'allowEdits' => true], $headers)->assertOk()->assertJsonPath('allowEdits', true);
        $this->actor->forceFill(['job_permissions' => ['progress' => ['view', 'edit']]])->save();
        $this->getJson(self::BASE.'/'.$share['sessionId'].'/commands', $headers)->assertForbidden();
        $this->assertNull(CadAiSession::first()->context);
    }

    public function test_shared_text_whitespace_and_empty_strings_survive_http_normalization(): void
    {
        $context = $this->context();
        $context['entities'][0]['type'] = 'TEXT';
        $context['entities'][0]['geometry'] = ['p' => ['x' => 0, 'y' => 0], 'text' => '  Shared annotation  ', 'height' => 2.5, 'width' => 0, 'angle' => 0];
        $context['entities'][0]['quantities'] = ['length' => null, 'area' => null, 'available' => false, 'reason' => 'annotation'];
        $context['totals']['unavailableCount'] = 1;
        $share = $this->share(false, $context);
        $this->assertSame('  Shared annotation  ', $this->service()->read($this->actor, $share['sessionId'])['entities'][0]['geometry']['text']);
        $context['entities'][0]['geometry']['text'] = '';
        $this->putJson(self::BASE.'/'.$share['sessionId'], ['context' => $context, 'allowEdits' => false], ['X-CAD-Editor-Token' => $share['editorToken']])->assertOk();
        $this->assertSame('', $this->service()->read($this->actor, $share['sessionId'])['entities'][0]['geometry']['text']);
    }
}
