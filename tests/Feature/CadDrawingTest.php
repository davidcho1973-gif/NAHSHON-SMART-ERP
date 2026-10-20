<?php

namespace Tests\Feature;

use App\Models\CadDrawing;
use App\Models\Company;
use App\Models\Site;
use App\Models\User;
use App\Services\Auth\EmailPasswordAuthService;
use App\Services\Drawings\CadConverter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
