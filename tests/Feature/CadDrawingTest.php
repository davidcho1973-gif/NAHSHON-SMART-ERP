<?php

namespace Tests\Feature;

use App\Models\CadDrawing;
use App\Models\Company;
use App\Models\DrawingSheet;
use App\Models\IntelligentDocument;
use App\Models\Site;
use App\Models\User;
use App\Services\Auth\EmailPasswordAuthService;
use App\Services\Drawings\CadConverter;
use App\Services\Drawings\CadDrawingService;
use App\Support\CadFileLimits;
use App\Support\Org;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CadDrawingTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $admin;

    private const DXF = "0\nSECTION\n2\nENTITIES\n0\nLINE\n8\n0\n10\n0\n20\n0\n11\n100\n21\n50\n0\nENDSEC\n0\nEOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['cad.disk' => 'local', 'cad.converter_binary' => null]);
        $company = Company::create(['code' => 'CAD', 'name' => 'CAD', 'status' => 'active']);
        $this->site = Site::create(['company_id' => $company->id, 'code' => 'CAD-A', 'name' => 'CAD A', 'status' => 'active']);
        $this->admin = User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $this->actingAs($this->admin);
    }

    private function upload(string $bytes = self::DXF, string $name = 'plan.dxf')
    {
        return $this->postJson('/admin-api/cad', ['site_id' => $this->site->id, 'title' => 'Plan', 'file' => UploadedFile::fake()->createWithContent($name, $bytes)]);
    }

    private function markup(): array
    {
        return ['id' => 'area-1', 'kind' => 'area', 'points' => [['x' => 1, 'y' => 2], ['x' => 30, 'y' => 2], ['x' => 30, 'y' => 20]],
            'color' => '#38bdf8', 'opacity' => 0.35, 'width' => 2, 'size' => 8, 'pattern' => 'diagonal', 'text' => '', 'locked' => false];
    }

    // HTTP requests have fresh PHP processes; earlier suite fixtures must not
    // consume the headroom for this full-size upload/save regression.
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_dxf_larger_than_twenty_megabytes_uploads_edits_and_retains_original(): void
    {
        // Valid comment pairs keep the fixture compact in entity count while
        // exercising the actual HTTP, validation, storage and revision sizes.
        $comments = str_repeat('999'."\n".str_repeat('x', 240)."\n", 201200);
        $source = str_replace("0\nEOF\n", $comments."0\nEOF\n", self::DXF);
        unset($comments);
        $this->assertGreaterThan(47 * 1048576, strlen($source));
        $originalHash = hash('sha256', $source);
        $created = $this->upload($source)->assertCreated();
        $id = $created->json('id');
        unset($created);
        $drawing = CadDrawing::findOrFail($id);
        $this->assertSame($originalHash, hash_file('sha256', Storage::disk('local')->path($drawing->original_path)));
        $edited = str_replace("11\n100\n", "11\n125\n", $source);
        $editedHash = hash('sha256', $edited);
        unset($source);
        $saved = $this->putJson('/admin-api/cad/'.$id, ['revision' => 1, 'dxf' => $edited])->assertOk();
        unset($edited);
        $payload = $saved->json();
        $this->assertSame(2, $payload['revision']);
        $this->assertSame($editedHash, hash('sha256', $payload['dxf']));
        $this->assertSame($originalHash, hash_file('sha256', Storage::disk('local')->path($drawing->original_path)));
    }

    public function test_configured_limit_applies_to_upload_save_and_conversion_without_partial_writes(): void
    {
        $id = $this->upload()->assertCreated()->json('id');
        config(['cad.max_bytes' => 1024]);
        $exact = self::DXF.str_repeat(' ', 1024 - strlen(self::DXF));
        $this->putJson('/admin-api/cad/'.$id, ['revision' => 1, 'dxf' => $exact])->assertOk()->assertJsonPath('revision', 2);
        $oversize = str_repeat(' ', 1025);
        $this->upload($oversize)->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->putJson('/admin-api/cad/'.$id, ['revision' => 2, 'dxf' => $oversize])->assertUnprocessable()->assertJsonValidationErrors('dxf');
        $this->assertDatabaseCount('cad_drawings', 1);
        $this->assertDatabaseCount('cad_drawing_revisions', 2);
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('최대');
        app(CadConverter::class)->convert($oversize, 'dxf', 'dwg');
    }

    public function test_capabilities_expose_effective_limits_and_php_lower_bounds(): void
    {
        $this->assertSame(64 * 1048576, CadFileLimits::drawingBytes());
        $this->getJson('/admin-api/cad/capabilities')->assertOk()->assertJsonPath('limits', CadFileLimits::capabilities());
        $this->assertSame(2 * 1048576, CadFileLimits::effectiveUploadBytes(64 * 1048576, 2 * 1048576, 8 * 1048576));
        $this->assertSame(7 * 1048576, CadFileLimits::effectiveUploadBytes(64 * 1048576, 64 * 1048576, 8 * 1048576));
        $this->assertSame(64 * 1048576, CadFileLimits::effectiveUploadBytes(64 * 1048576, 0, 0));
    }

    public function test_streaming_dxf_validation_keeps_line_endings_and_rejects_malformed_pairs(): void
    {
        $validator = app(CadDrawingService::class);
        foreach (["\n", "\r\n", "\r"] as $ending) {
            $validator->validateDxf(str_replace("\n", $ending, self::DXF));
            $validator->validateDxf(rtrim(str_replace("\n", $ending, self::DXF)));
        }
        foreach ([self::DXF."0\nLINE\n", str_replace("0\nEOF\n", "999\n", self::DXF), str_replace("0\nENDSEC\n", '', self::DXF)] as $invalid) {
            $this->upload($invalid)->assertUnprocessable()->assertJsonValidationErrors('dxf');
        }
        $this->assertDatabaseCount('cad_drawings', 0);
    }

    public function test_native_bridge_receives_canonical_limit_and_rejects_oversize_output(): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'cad-size-');
        $binary = $temp.'.dll';
        file_put_contents($binary, '<?php
if (getenv("CAD_CONVERTER_MAX_BYTES") !== "1024") exit(3);
if ($argv[1] === "capabilities") { echo json_encode(["dwgRead"=>true,"dwgWrite"=>true]); exit(0); }
file_put_contents($argv[5], str_repeat("x", 1025)); echo "{}";');
        config(['cad.max_bytes' => 1024, 'cad.converter_binary' => $binary, 'cad.dotnet_binary' => PHP_BINARY]);
        try {
            $this->getJson('/admin-api/cad/capabilities')->assertJsonPath('dwgRead', true);
            $this->upload("AC1032\0fixture", 'large-result.dwg')->assertUnprocessable();
            $this->assertDatabaseCount('cad_drawings', 0);
            $this->assertSame([], glob(storage_path('app/private/cad-tmp/*/input.dwg')) ?: []);
        } finally {
            unlink($binary);
            unlink($temp);
        }
    }

    public function test_pdf_studio_import_is_page_pinned_idempotent_and_keeps_document_permissions(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
        Storage::disk('local')->put('source.pdf', $pdf);
        $document = IntelligentDocument::create(['uuid' => (string) Str::uuid(), 'company_id' => $this->site->company_id, 'site_id' => $this->site->id, 'disk' => 'local', 'sha256' => hash('sha256', $pdf), 'stored_file_name' => 'source.pdf', 'file_path' => 'source.pdf', 'original_file_name' => 'source.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'document_type' => 'drawing', 'access_level' => 'private', 'owner_user_id' => $this->admin->id]);
        $sheet = DrawingSheet::create(['intelligent_document_id' => $document->id, 'site_id' => $this->site->id, 'page_no' => 2, 'sheet_no' => 'A2']);
        $endpoint = '/admin-api/cad/source-sheets/'.$sheet->id;
        $this->getJson($endpoint)->assertOk()->assertJsonPath('page', 2);
        $this->get($endpoint.'/file')->assertContent($pdf);
        $body = ['requestKey' => (string) Str::uuid(), 'title' => 'A2', 'reference' => ['page' => 2, 'width' => 400, 'height' => 300]];
        $id = $this->postJson($endpoint, $body)->assertCreated()->assertJsonPath('reference.sourceSheetId', $sheet->id)->json('id');
        $this->postJson($endpoint, $body)->assertCreated()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('cad_drawings', 1);
        $this->postJson($endpoint, array_replace_recursive($body, ['requestKey' => (string) Str::uuid(), 'reference' => ['page' => 1]]))->assertUnprocessable();
        $this->getJson('/admin-api/cad')->assertJsonCount(1, 'drawings');
        $other = User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $this->actingAs($other);
        $this->getJson($endpoint)->assertNotFound();
        $this->getJson('/admin-api/cad/'.$id)->assertNotFound();
        $this->get('/admin-api/cad/'.$id.'/reference')->assertNotFound();
        $this->getJson('/admin-api/cad')->assertJsonCount(0, 'drawings');
        $this->assertDatabaseCount('drawing_studios', 0);
    }

    public function test_reference_image_keeps_original_pixels_and_rejects_cad_geometry_changes(): void
    {
        $file = UploadedFile::fake()->image('reference.png', 400, 300);
        $bytes = $file->get();
        $result = $this->postJson('/admin-api/cad', ['site_id' => $this->site->id, 'title' => 'Reference', 'file' => $file])->assertCreated()
            ->assertJsonPath('sourceFormat', 'png')->assertJsonPath('reference.width', 400)->assertJsonPath('reference.height', 300);
        $id = $result->json('id');
        $dxf = $result->json('dxf');
        $url = '/admin-api/cad/'.$id;
        $this->get($url.'/reference')->assertOk()->assertContent($bytes)->assertHeader('Content-Type', 'image/png');
        $this->putJson($url, ['revision' => 1, 'dxf' => $dxf, 'markups' => [$this->markup()]])->assertOk();
        $this->putJson($url, ['revision' => 2, 'dxf' => self::DXF, 'markups' => []])->assertUnprocessable();
        $this->getJson($url.'/download?format=dxf')->assertUnprocessable();
        $this->get($url.'/download?format=original')->assertStreamedContent($bytes);
        $this->postJson($url.'/copies', ['requestKey' => (string) Str::uuid(), 'revision' => 2, 'title' => 'Copy', 'dxf' => $dxf, 'markups' => [$this->markup()], 'workspace' => []])
            ->assertCreated()->assertJsonPath('reference.width', 400)->assertJsonPath('sourceFormat', 'png');
        $outsider = User::factory()->create(['access_role' => 'site_manager', 'account_status' => 'active', 'access_scope' => 'site', 'allowed_site_id' => null]);
        $this->actingAs($outsider)->get($url.'/reference')->assertNotFound();
    }

    public function test_linked_details_require_a_valid_source_and_parallelogram_frame(): void
    {
        $id = $this->upload()->json('id');
        $detail = $this->markup();
        $detail['kind'] = 'detail';
        $detail['points'] = [['x' => 20, 'y' => 0], ['x' => 40, 'y' => 0], ['x' => 40, 'y' => 20], ['x' => 20, 'y' => 20]];
        $detail['detail'] = ['minX' => 0, 'minY' => 0, 'maxX' => 10, 'maxY' => 10];
        $this->putJson('/admin-api/cad/'.$id, ['revision' => 1, 'dxf' => self::DXF, 'markups' => [$detail]])->assertOk();
        $detail['points'][2]['x'] = 39;
        $this->putJson('/admin-api/cad/'.$id, ['revision' => 2, 'dxf' => self::DXF, 'markups' => [$detail]])->assertUnprocessable();
        $this->assertDatabaseCount('cad_drawing_revisions', 2);
    }

    public function test_cad_workspace_uses_the_current_organization_name(): void
    {
        config(['org.name' => 'SYNTHETIC WORKS']);
        Org::forget();
        $this->get('/cad')->assertOk()->assertSee('SYNTHETIC WORKS · CAD 도면 편집', false)
            ->assertSee('title="SYNTHETIC WORKS"', false);
        Org::forget();
    }

    public function test_markups_share_immutable_revisions_without_changing_original_or_dxf(): void
    {
        $id = $this->upload()->assertJsonPath('markups', [])->json('id');
        $url = '/admin-api/cad/'.$id;
        $marks = [$this->markup()];
        $this->putJson($url, ['revision' => 1, 'dxf' => self::DXF, 'markups' => $marks])->assertOk()
            ->assertJsonPath('markups.0.color', '#38bdf8')->assertJsonPath('dxf', self::DXF)->assertJsonPath('canExport', true);
        $this->getJson($url.'/history?revision=1')->assertJsonPath('markups', []);
        $this->getJson($url.'/history?revision=2')->assertJsonPath('markups.0.pattern', 'diagonal')->assertJsonPath('canManage', false);
        // A stale write cannot replace either the drawing or its annotations.
        $this->putJson($url, ['revision' => 1, 'dxf' => self::DXF, 'markups' => []])->assertConflict();
        // A pre-markup client preserves the previous overlay by omitting the new field.
        $this->putJson($url, ['revision' => 2, 'dxf' => self::DXF])->assertOk()->assertJsonPath('markups.0.id', 'area-1');
        $this->get($url.'/download?format=original')->assertStreamedContent(self::DXF);
        $this->get($url.'/download?format=dxf')->assertContent(self::DXF);
        $this->putJson($url, ['revision' => 3, 'dxf' => self::DXF, 'markups' => []])->assertOk()->assertJsonPath('markups', []);
        $this->getJson($url.'/history?revision=2')->assertJsonPath('markups.0.id', 'area-1');
    }

    public function test_invalid_overlay_payloads_never_create_a_revision(): void
    {
        $id = $this->upload()->json('id');
        $mark = $this->markup();
        $invalid = [
            [array_replace($mark, ['kind' => 'script'])],
            [array_replace($mark, ['color' => 'url(https://example.test)'])],
            [array_replace($mark, ['points' => [['x' => 1e20, 'y' => 0]]])],
            [array_replace($mark, ['size' => 0])],
            [array_replace($mark, ['kind' => 'cloud', 'points' => array_fill(0, 3, ['x' => 0, 'y' => 0])])],
            [array_replace($mark, ['opacity' => 2])],
            [array_replace($mark, ['text' => str_repeat('x', 501)])],
            [$mark, $mark],
            [array_replace($mark, ['html' => '<script>bad</script>'])],
            ['not-a-list' => $mark],
        ];
        foreach ($invalid as $marks) {
            $this->putJson('/admin-api/cad/'.$id, ['revision' => 1, 'dxf' => self::DXF, 'markups' => $marks])->assertUnprocessable();
        }
        $this->assertDatabaseCount('cad_drawing_revisions', 1);
        $viewer = User::factory()->create(['access_role' => 'payroll', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $this->actingAs($viewer)->putJson('/admin-api/cad/'.$id, ['revision' => 1, 'dxf' => self::DXF, 'markups' => [$mark]])->assertForbidden();
    }

    public function test_holes_text_groups_and_calibration_survive_immutable_revisions_and_old_clients(): void
    {
        $id = $this->upload()->json('id');
        $area = $this->markup();
        $area['points'] = [['x' => 0, 'y' => 0], ['x' => 100, 'y' => 0], ['x' => 100, 'y' => 100], ['x' => 0, 'y' => 100]];
        $area['holes'] = [[['x' => 20, 'y' => 20], ['x' => 30, 'y' => 20], ['x' => 30, 'y' => 30], ['x' => 20, 'y' => 30]]];
        $area['group'] = 'review-group';
        $area['layer'] = 'fire';
        $area['label'] = 'REV-03';
        $text = array_replace($this->markup(), ['id' => 'note', 'kind' => 'text', 'points' => [['x' => 10, 'y' => 10]], 'text' => '검토 메모', 'rotation' => 90,
            'textStyle' => ['font' => 'sans', 'bold' => true, 'italic' => false, 'align' => 'center', 'lineHeight' => 1.5, 'boxWidth' => 30, 'background' => 'none', 'border' => false]]);
        $workspace = ['layers' => [['id' => 'fire', 'name' => '소방', 'color' => '#ff0000', 'visible' => true, 'locked' => false]],
            'measurement' => ['metersPerUnit' => .01, 'unit' => 'm', 'source' => 'calibrated', 'points' => [['x' => 0, 'y' => 0], ['x' => 100, 'y' => 0]], 'distance' => 1],
            'underlay' => ['opacity' => .4, 'grayscale' => true]];
        $url = '/admin-api/cad/'.$id;
        $this->putJson($url, ['revision' => 1, 'dxf' => self::DXF, 'markups' => [$area, $text], 'workspace' => $workspace])->assertOk()
            ->assertJsonPath('workspace.measurement.metersPerUnit', .01)->assertJsonPath('markups.0.holes.0.0.x', 20)->assertJsonPath('markups.1.textStyle.bold', true);
        $this->putJson($url, ['revision' => 2, 'dxf' => self::DXF])->assertOk()->assertJsonPath('workspace.layers.0.id', 'fire')->assertJsonPath('markups.0.group', 'review-group');
        $this->getJson($url.'/history?revision=2')->assertJsonPath('markups.1.rotation', 90)->assertJsonPath('workspace.underlay.grayscale', true);
        $this->get($url.'/download?format=original')->assertStreamedContent(self::DXF);
        $this->putJson($url, ['revision' => 2, 'dxf' => self::DXF, 'workspace' => []])->assertConflict();
        $this->assertDatabaseCount('cad_drawing_revisions', 3);
    }

    public function test_construction_guides_survive_revisions_and_recovery_without_changing_cad_bytes(): void
    {
        $id = $this->upload()->json('id');
        $url = '/admin-api/cad/'.$id;
        $guides = [
            ['id' => 'guide-line', 'type' => 'line', 'p' => ['x' => 10, 'y' => 20], 'direction' => ['x' => .6, 'y' => .8]],
            ['id' => 'guide-point', 'type' => 'point', 'p' => ['x' => 40, 'y' => 60]],
        ];
        $workspace = ['guides' => $guides, 'guidesVisible' => true];
        $this->putJson($url, ['revision' => 1, 'dxf' => self::DXF, 'workspace' => $workspace])->assertOk()
            ->assertJsonPath('workspace.guides.0.direction.x', .6)->assertJsonPath('workspace.guides.1.p.x', 40)
            ->assertJsonPath('workspace.guidesVisible', true)->assertJsonPath('dxf', self::DXF);
        $this->putJson($url, ['revision' => 2, 'dxf' => self::DXF])->assertOk()->assertJsonCount(2, 'workspace.guides');
        $workspace['guides'] = [];
        $workspace['guidesVisible'] = false;
        $this->putJson($url, ['revision' => 3, 'dxf' => self::DXF, 'workspace' => $workspace])->assertOk()
            ->assertJsonPath('workspace.guides', [])->assertJsonPath('workspace.guidesVisible', false);
        $this->getJson($url.'/history?revision=2')->assertOk()->assertJsonPath('workspace.guides.0.id', 'guide-line');
        $copyId = $this->postJson($url.'/copies', ['requestKey' => (string) Str::uuid(), 'revision' => 2,
            'title' => 'Guides recovery', 'dxf' => self::DXF, 'markups' => [], 'workspace' => ['guides' => $guides, 'guidesVisible' => true]])
            ->assertCreated()->assertJsonCount(2, 'workspace.guides')->json('id');
        foreach ([$id, $copyId] as $drawingId) {
            $this->get('/admin-api/cad/'.$drawingId.'/download?format=dxf')->assertOk()->assertContent(self::DXF);
            $this->get('/admin-api/cad/'.$drawingId.'/download?format=original')->assertStreamedContent(self::DXF);
        }
        $viewer = User::factory()->create(['access_role' => 'payroll', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $this->actingAs($viewer)->putJson($url, ['revision' => 4, 'dxf' => self::DXF, 'workspace' => ['guides' => $guides]])->assertForbidden();
        $this->assertDatabaseCount('cad_drawing_revisions', 5);
    }

    public function test_invalid_or_unbounded_guides_are_rejected_without_creating_a_revision(): void
    {
        $id = $this->upload()->json('id');
        $point = ['id' => 'point', 'type' => 'point', 'p' => ['x' => 0, 'y' => 0]];
        $line = ['id' => 'line', 'type' => 'line', 'p' => ['x' => 0, 'y' => 0], 'direction' => ['x' => 1, 'y' => 0]];
        $invalid = [
            ['guides' => ['named' => $point]],
            ['guides' => [$point, $point]],
            ['guides' => [array_replace($point, ['id' => '<script>'])]],
            ['guides' => [array_replace($point, ['type' => 'solid'])]],
            ['guides' => [array_replace($point, ['p' => ['x' => 1e10, 'y' => 0]])]],
            ['guides' => [array_replace($point, ['p' => ['x' => 0]])]],
            ['guides' => [array_replace($point, ['direction' => ['x' => 1, 'y' => 0]])]],
            ['guides' => [array_replace($point, ['quantity' => 100])]],
            ['guides' => [array_diff_key($line, ['direction' => 1])]],
            ['guides' => [array_replace($line, ['direction' => ['x' => 0, 'y' => 0]])]],
            ['guides' => [array_replace($line, ['direction' => ['x' => .5, 'y' => .5]])]],
            ['guides' => [array_replace($line, ['direction' => ['x' => 1]])]],
            ['guides' => [array_replace($line, ['direction' => ['x' => 1, 'y' => 0, 'z' => 0]])]],
            ['guidesVisible' => 'yes'],
            ['guides' => array_map(fn ($n) => array_replace($point, ['id' => 'p-'.$n]), range(1, 257))],
        ];
        foreach ($invalid as $workspace) {
            $this->putJson('/admin-api/cad/'.$id, ['revision' => 1, 'dxf' => self::DXF, 'workspace' => $workspace])->assertUnprocessable();
        }
        $this->assertDatabaseCount('cad_drawing_revisions', 1);
    }

    public function test_invalid_hole_topology_unknown_layers_and_false_scale_are_rejected_atomically(): void
    {
        $id = $this->upload()->json('id');
        $url = '/admin-api/cad/'.$id;
        $mark = $this->markup();
        $square = [['x' => 0, 'y' => 0], ['x' => 10, 'y' => 0], ['x' => 10, 'y' => 10], ['x' => 0, 'y' => 10]];
        $inner = [['x' => 2, 'y' => 2], ['x' => 4, 'y' => 2], ['x' => 4, 'y' => 4], ['x' => 2, 'y' => 4]];
        foreach ([
            ['points' => $square, 'holes' => [$square]],
            ['points' => $square, 'holes' => [$inner, $inner]],
            ['points' => $inner, 'holes' => [$square]],
            ['points' => [['x' => 0, 'y' => 0], ['x' => 10, 'y' => 8], ['x' => 0, 'y' => 10], ['x' => 8, 'y' => 0]]],
            ['layer' => 'missing'],
            ['kind' => 'pen', 'holes' => [$inner]],
            ['textStyle' => ['font' => 'url(javascript:bad)']],
        ] as $change) {
            $this->putJson($url, ['revision' => 1, 'dxf' => self::DXF, 'markups' => [array_replace($mark, $change)]])->assertUnprocessable();
        }
        foreach ([
            ['measurement' => ['unit' => 'mm', 'metersPerUnit' => 1, 'source' => 'declared']],
            ['measurement' => ['unit' => 'm', 'metersPerUnit' => 1, 'source' => 'calibrated']],
            ['underlay' => ['opacity' => 0, 'grayscale' => true]],
            ['approvedQuantity' => 100],
        ] as $workspace) {
            $this->putJson($url, ['revision' => 1, 'dxf' => self::DXF, 'workspace' => $workspace])->assertUnprocessable();
        }
        $this->assertDatabaseCount('cad_drawing_revisions', 1);
    }

    public function test_import_save_history_conflict_and_private_downloads_preserve_original(): void
    {
        $id = $this->upload()->assertCreated()->assertJsonPath('revision', 1)->assertJsonPath('dxf', self::DXF)->json('id');
        $url = '/admin-api/cad/'.$id;
        $edited = str_replace("11\n100", "11\n200", self::DXF);
        $this->putJson($url, ['revision' => 1, 'dxf' => $edited])->assertOk()->assertJsonPath('revision', 2);
        $this->putJson($url, ['revision' => 1, 'dxf' => self::DXF])->assertConflict();
        $this->getJson($url)->assertOk()->assertJsonPath('dxf', $edited);
        $this->getJson($url.'/history?revision=1')->assertOk()->assertJsonPath('dxf', self::DXF);
        $this->getJson($url.'/history')->assertOk()->assertJsonCount(2, 'revisions');
        $this->get($url.'/download?format=dxf&revision=1')->assertOk()->assertContent(self::DXF);
        $this->get($url.'/download?format=original')->assertOk()->assertStreamedContent(self::DXF);
        $this->get($url.'/download?format=dwg')->assertStatus(503);
        $drawing = CadDrawing::findOrFail($id);
        Storage::disk($drawing->disk)->assertExists($drawing->original_path);
        $this->assertDatabaseCount('cad_drawing_revisions', 2);
    }

    public function test_stale_recovery_creates_an_idempotent_scoped_copy_without_overwriting_current_drawing(): void
    {
        $id = $this->upload()->json('id');
        $url = '/admin-api/cad/'.$id;
        $latest = str_replace("11\n100", "11\n200", self::DXF);
        $this->putJson($url, ['revision' => 1, 'dxf' => $latest])->assertOk();
        $payload = ['requestKey' => (string) Str::uuid(), 'revision' => 1, 'title' => 'Recovered plan', 'dxf' => self::DXF,
            'markups' => [$this->markup()], 'workspace' => ['measurement' => ['unit' => 'mm', 'metersPerUnit' => .001, 'source' => 'declared']]];
        $copyId = $this->postJson($url.'/copies', $payload)->assertCreated()->assertJsonPath('revision', 1)->assertJsonPath('markups.0.id', 'area-1')->json('id');
        $files = Storage::disk('local')->allFiles('cad');
        $this->postJson($url.'/copies', $payload)->assertCreated()->assertJsonPath('id', $copyId);
        $this->assertSame($files, Storage::disk('local')->allFiles('cad'));
        $this->postJson($url.'/copies', array_replace($payload, ['title' => 'Different request']))->assertConflict();
        $this->getJson($url)->assertJsonPath('dxf', $latest)->assertJsonPath('revision', 2)->assertJsonPath('markups', []);
        $this->get('/admin-api/cad/'.$copyId.'/download?format=original')->assertStreamedContent(self::DXF);
        $copy = CadDrawing::findOrFail($copyId);
        $source = CadDrawing::findOrFail($id);
        $this->assertSame($source->site_id, $copy->site_id);
        $this->assertEquals($source->revisions()->where('revision', 1)->value('id'), $copy->copied_from_revision_id);
        $this->assertDatabaseCount('cad_drawings', 2);
        $viewer = User::factory()->create(['access_role' => 'payroll', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $this->actingAs($viewer)->postJson($url.'/copies', $payload)->assertForbidden();
    }

    public function test_actual_contents_are_validated_and_dwg_fails_closed_without_converter(): void
    {
        $this->upload('not a drawing', 'fake.dwg')->assertUnprocessable();
        $this->upload(str_replace('LINE', "TEXT\n1\n\xff", self::DXF))->assertUnprocessable();
        $this->upload("AC1032\0binary", 'misnamed.dxf')->assertStatus(503);
        $this->upload(str_replace("0\nEOF\n", '', self::DXF))->assertUnprocessable();
        $this->upload(str_replace("0\nENDSEC", "-1\nENDSEC", self::DXF))->assertUnprocessable();
        $this->assertDatabaseCount('cad_drawings', 0);
        $this->getJson('/admin-api/cad/capabilities')->assertOk()->assertJsonPath('dwgRead', false)->assertJsonPath('dwgWrite', false);
    }

    public function test_dwg_conversion_retains_original_and_surfaces_loss_warnings(): void
    {
        $dwg = "AC1032\0fixture";
        $this->mock(CadConverter::class, function ($mock) use ($dwg): void {
            $mock->shouldReceive('convert')->once()->with($dwg, 'dwg', 'dxf')
                ->andReturn(['bytes' => self::DXF, 'warnings' => ['Proxy object is not editable.']]);
            $mock->shouldReceive('convert')->once()->with(self::DXF, 'dxf', 'dwg')
                ->andReturn(['bytes' => $dwg, 'warnings' => ['Check dimensions after conversion.']]);
        });
        $id = $this->upload($dwg, 'plan.dwg')->assertCreated()->assertJsonPath('sourceFormat', 'dwg')
            ->assertJsonPath('warnings.0', 'Proxy object is not editable.')->json('id');
        $this->get('/admin-api/cad/'.$id.'/download?format=original')->assertStreamedContent($dwg);
        $response = $this->get('/admin-api/cad/'.$id.'/download?format=dwg')->assertOk()->assertContent($dwg);
        $this->assertSame(['Check dimensions after conversion.'], json_decode(base64_decode($response->headers->get('X-CAD-Warnings')), true));
        $this->putJson('/admin-api/cad/'.$id, ['revision' => 1, 'dxf' => self::DXF])->assertOk()
            ->assertJsonPath('warnings.0', 'Proxy object is not editable.');
    }

    public function test_site_scope_and_read_only_permissions_apply_to_all_endpoints(): void
    {
        $id = $this->upload()->json('id');
        $url = '/admin-api/cad/'.$id;
        $other = Site::create(['company_id' => $this->site->company_id, 'code' => 'CAD-B', 'name' => 'B', 'status' => 'active']);
        $outsider = User::factory()->create(['access_role' => 'site_manager', 'access_scope' => 'site', 'allowed_site_id' => $other->id, 'allowed_company_id' => $other->company_id, 'account_status' => 'active']);
        $this->actingAs($outsider);
        foreach ([$url, $url.'/history', $url.'/download?format=original', '/admin-api/cad?site_id='.$this->site->id] as $endpoint) {
            $this->getJson($endpoint)->assertNotFound();
        }
        $this->getJson('/admin-api/cad')->assertOk()->assertJsonCount(0, 'drawings');
        $this->upload()->assertNotFound();
        $this->putJson($url, ['revision' => 1, 'dxf' => self::DXF])->assertNotFound();
        $viewer = User::factory()->create(['access_role' => 'payroll', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $this->actingAs($viewer)->getJson($url)->assertOk()->assertJsonPath('canManage', false);
        $this->putJson($url, ['revision' => 1, 'dxf' => self::DXF])->assertForbidden();
        $this->upload()->assertForbidden();
    }

    public function test_failed_storage_creates_no_drawing_or_revision(): void
    {
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        $disk->shouldReceive('delete')->once()->with(\Mockery::type('array'))->andReturn(true);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        $this->upload()->assertStatus(503);
        $this->assertDatabaseCount('cad_drawings', 0);
        $this->assertDatabaseCount('cad_drawing_revisions', 0);
    }

    public function test_existing_non_converter_binary_cannot_advertise_dwg_support(): void
    {
        config(['cad.converter_binary' => PHP_BINARY]);
        $this->getJson('/admin-api/cad/capabilities')->assertOk()->assertJsonPath('dwgRead', false)->assertJsonPath('dwgWrite', false);
    }

    public function test_converter_failure_is_visible_without_leaking_native_paths_or_creating_a_drawing(): void
    {
        $fake = tempnam(sys_get_temp_dir(), 'cad-bridge-').'.dll';
        file_put_contents($fake, '<?php
if ($argv[1] === "capabilities") { echo json_encode(["dwgRead"=>true,"dwgWrite"=>true]); exit(0); }
fwrite(STDERR, json_encode(["error"=>"InvalidDataException", "message"=>"Entity count changed during serialization; /private/customer-secret.dwg"])); exit(2);');
        config(['cad.converter_binary' => $fake, 'cad.dotnet_binary' => PHP_BINARY]);
        try {
            $response = $this->upload("AC1032\0fixture", 'plan.dwg')->assertUnprocessable();
            $this->assertStringContainsString('객체나 표시 색상이 달라져', $response->json('message'));
            $this->assertStringNotContainsString('customer-secret', $response->getContent());
            $this->assertDatabaseCount('cad_drawings', 0);
            $this->assertDatabaseCount('cad_drawing_revisions', 0);
            $this->assertSame([], glob(storage_path('app/private/cad-tmp/*/input.dwg')) ?: []);
        } finally {
            unlink($fake);
            unlink(substr($fake, 0, -4));
        }
    }

    public function test_invalid_converter_output_is_never_downloaded_as_dwg(): void
    {
        $id = $this->upload()->json('id');
        $this->mock(CadConverter::class, function ($mock): void {
            $mock->shouldReceive('convert')->once()->andReturn(['bytes' => self::DXF, 'warnings' => []]);
        });
        $this->getJson('/admin-api/cad/'.$id.'/download?format=dwg')->assertStatus(503);
    }

    public function test_managed_accounts_require_progress_and_separate_document_export_permission(): void
    {
        $id = $this->upload()->json('id');
        $user = User::factory()->create(['access_role' => 'site_manager', 'account_status' => 'active', 'access_scope' => 'site',
            'allowed_company_id' => $this->site->company_id, 'allowed_site_id' => $this->site->id,
            'job_role' => 'engineering', 'job_site_ids' => [$this->site->id], 'job_permissions' => ['progress' => ['view', 'edit']]]);
        $url = '/admin-api/cad/'.$id;
        $this->actingAs($user)->withSession([EmailPasswordAuthService::STRONG_AUTH_SESSION => $user->id])->getJson($url)->assertOk();
        $this->getJson($url.'/download?format=original')->assertForbidden();
        $user->forceFill(['job_permissions' => ['documents' => ['view', 'export']]])->save();
        $this->getJson($url)->assertForbidden();
        $this->getJson($url.'/history')->assertForbidden();
        $this->getJson($url.'/download?format=original')->assertForbidden();
    }

    public function test_real_converter_roundtrip_retains_edited_coordinate_and_original(): void
    {
        $binary = getenv('CAD_TEST_CONVERTER_BINARY');
        $dotnet = getenv('CAD_TEST_DOTNET_BINARY');
        if (! $binary || ! $dotnet) {
            $this->markTestSkipped('Set CAD_TEST_CONVERTER_BINARY and CAD_TEST_DOTNET_BINARY to run real DWG integration.');
        }
        config(['cad.converter_binary' => $binary, 'cad.dotnet_binary' => $dotnet]);
        $converter = app(CadConverter::class);
        $this->assertTrue($converter->capabilities()['dwgRead']);
        $source = file_get_contents(getenv('CAD_TEST_DXF_FIXTURE') ?: base_path('tests/Fixtures/cad-line.dxf'));
        $original = $converter->convert($source, 'dxf', 'dwg')['bytes'];
        $this->assertMatchesRegularExpression('/^AC10[0-9]{2}/', $original);
        $created = $this->upload($original, 'real-plan.dwg')->assertCreated();
        $id = $created->json('id');
        $dxf = $created->json('dxf');
        // Change the LINE endpoint, rather than replacing the drawing with unrelated test geometry.
        $lines = preg_split('/\r\n|\n|\r/', rtrim($dxf));
        $inLine = false;
        $changed = false;
        for ($i = 0; $i + 1 < count($lines); $i += 2) {
            $code = (int) trim($lines[$i]);
            if ($code === 0) {
                $inLine = trim($lines[$i + 1]) === 'LINE';
            }
            if ($inLine && $code === 11) {
                $lines[$i + 1] = '237.125';
                $changed = true;
                break;
            }
        }
        $this->assertTrue($changed);
        $edited = implode("\r\n", $lines)."\r\n";
        $url = '/admin-api/cad/'.$id;
        $this->putJson($url, ['revision' => 1, 'dxf' => $edited])->assertOk()->assertJsonPath('revision', 2);
        $this->getJson($url.'/history?revision=1')->assertOk()->assertJsonPath('dxf', $dxf);
        $this->getJson($url.'/history?revision=2')->assertOk()->assertJsonPath('dxf', $edited);
        $this->get($url.'/download?format=original')->assertOk()->assertStreamedContent($original);
        $exported = $this->get($url.'/download?format=dwg')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/^AC10[0-9]{2}/', $exported);
        $roundtrip = $converter->convert($exported, 'dwg', 'dxf')['bytes'];
        $this->assertMatchesRegularExpression('/(?:\r?\n)\s*11\r?\n237\.125(?:0*)\r?\n/', $roundtrip);
        $this->assertDatabaseCount('cad_drawing_revisions', 2);
    }
}
