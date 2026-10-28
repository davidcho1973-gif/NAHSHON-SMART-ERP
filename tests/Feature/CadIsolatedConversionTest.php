<?php

namespace Tests\Feature;

use App\Jobs\ProcessCadConversion;
use App\Models\CadConversion;
use App\Models\CadDrawing;
use App\Models\Company;
use App\Models\Site;
use App\Models\User;
use App\Services\Drawings\CadConversionBudget;
use App\Services\Drawings\CadConversionService;
use App\Services\Drawings\CadConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CadIsolatedConversionTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $admin;

    private const DWG = 'AC1032synthetic-dwg';

    private const DXF = "0\nSECTION\n2\nENTITIES\n0\nLINE\n8\n0\n10\n0\n20\n0\n11\n100\n21\n50\n0\nENDSEC\n0\nEOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['cad.disk' => 'local', 'cad.isolated_conversions' => true, 'cad.converter_binary' => null]);
        $company = Company::create(['code' => 'CADQ', 'name' => 'CAD Queue', 'status' => 'active']);
        $this->site = Site::create(['company_id' => $company->id, 'code' => 'CADQ-A', 'name' => 'CAD A', 'status' => 'active']);
        $this->admin = User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $this->actingAs($this->admin);
    }

    private function upload(?string $key = null, string $bytes = self::DWG)
    {
        return $this->postJson('/admin-api/cad/conversions', ['site_id' => $this->site->id, 'title' => 'Civil',
            'requestKey' => $key ?? (string) Str::uuid(), 'file' => UploadedFile::fake()->createWithContent('civil.dwg', $bytes)]);
    }

    private function worker(string $id, int $attempt = 1): void
    {
        config(['cad.worker_active' => true]);
        app(CadConversionService::class)->process($id, $attempt);
    }

    private function nativeReport(bool $invalidSecondPart = false): void
    {
        $this->mock(CadConverter::class)->shouldReceive('prepare2D')->once()->andReturnUsing(function ($input, $output) use ($invalidSecondPart) {
            $this->assertSame(self::DWG, file_get_contents($input));
            mkdir($output);
            $parts = [];
            for ($i = 1; $i <= 2; $i++) {
                $bytes = $invalidSecondPart && $i === 2 ? 'invalid' : self::DXF;
                $file = sprintf('part-%02d.dxf', $i);
                file_put_contents($output.'/'.$file, $bytes);
                $parts[] = ['file' => $file, 'label' => '2D 평면', 'entities' => 1, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
            }

            return ['parts' => $parts, 'warnings' => ['원본 별도 보존'], 'excluded' => ['3DSOLID' => 1], 'projected' => ['LINE' => 2], 'units' => 1];
        });
    }

    public function test_upload_is_durable_and_idempotent_without_native_work_on_http(): void
    {
        $this->mock(CadConverter::class)->shouldNotReceive('convert', 'prepare2D');
        $key = (string) Str::uuid();
        $id = $this->upload($key)->assertStatus(202)->assertJsonPath('status', 'queued')->json('id');
        $this->upload($key)->assertStatus(202)->assertJsonPath('id', $id);
        $this->upload($key, self::DWG.'changed')->assertConflict();
        $this->assertDatabaseCount('cad_conversions', 1);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('jobs', ['queue' => 'cad-conversion']);
        $task = CadConversion::findOrFail($id);
        $this->assertSame(self::DWG, Storage::disk('local')->get($task->source_path));
        $this->assertSame(hash('sha256', self::DWG), $task->sha256);
        $this->assertDatabaseCount('cad_drawings', 0);
    }

    public function test_job_and_task_join_the_same_database_transaction(): void
    {
        DB::beginTransaction();
        $this->upload()->assertStatus(202);
        $this->assertDatabaseCount('jobs', 1);
        DB::rollBack();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('cad_conversions', 0);
    }

    public function test_invalid_or_disabled_upload_creates_nothing(): void
    {
        $this->upload(bytes: 'not-a-dwg')->assertUnprocessable();
        config(['cad.isolated_conversions' => false]);
        $this->upload()->assertStatus(503);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('cad/conversions'));
    }

    public function test_owner_and_site_restrictions_cover_status_original_and_retry(): void
    {
        $id = $this->upload()->assertStatus(202)->json('id');
        $this->actingAs(User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active']));
        $this->getJson('/admin-api/cad/conversions/'.$id)->assertNotFound();
        $this->getJson('/admin-api/cad/conversions/'.$id.'/original')->assertNotFound();
        $this->postJson('/admin-api/cad/conversions/'.$id.'/retry')->assertNotFound();
        $this->getJson('/admin-api/cad/conversions')->assertJsonCount(0, 'conversions');
    }

    public function test_ordinary_erp_queue_cannot_execute_conversion(): void
    {
        $this->mock(CadConverter::class)->shouldNotReceive('convert', 'prepare2D');
        $id = $this->upload()->assertStatus(202)->json('id');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('isolated CAD worker');
        (new ProcessCadConversion($id))->handle(app(CadConversionService::class));
    }

    public function test_worker_refuses_the_existing_512_mib_erp_container(): void
    {
        $this->mock(CadConversionBudget::class)->shouldReceive('memory')->once()->andReturn(['limit' => 512 * 1048576, 'used' => 160 * 1048576]);
        $this->artisan('cad:work --once')->assertFailed();
        $this->assertNotTrue(config('cad.worker_active'));
    }

    public function test_worker_refuses_a_second_process_on_the_same_container(): void
    {
        $this->mock(CadConversionBudget::class)->shouldReceive('memory')->once()->andReturn(['limit' => 4096 * 1048576, 'used' => 160 * 1048576]);
        $root = storage_path('app/private/cad-worker');
        File::ensureDirectoryExists($root);
        $lock = fopen($root.'/worker.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->artisan('cad:work --once')->assertFailed();
            $this->assertNotTrue(config('cad.worker_active'));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function test_working_copies_edit_save_reopen_and_keep_original_unchanged(): void
    {
        $id = $this->upload()->assertStatus(202)->json('id');
        $this->nativeReport();
        $this->worker($id);
        $result = $this->getJson('/admin-api/cad/conversions/'.$id)->assertOk()->assertJsonPath('status', 'completed')->assertJsonCount(2, 'result.drawings');
        $drawingId = $result->json('result.drawings.0.id');
        $edited = str_replace("11\n100\n", "11\n125\n", self::DXF);
        $this->putJson('/admin-api/cad/'.$drawingId, ['revision' => 1, 'dxf' => $edited])->assertOk()->assertJsonPath('revision', 2);
        $this->getJson('/admin-api/cad/'.$drawingId)->assertOk()->assertJsonPath('dxf', $edited);
        foreach (CadDrawing::all() as $drawing) {
            $this->assertSame(self::DWG, Storage::disk('local')->get($drawing->original_path));
        }
        $this->worker($id); // Duplicate delivery must not create new drawings.
        $this->assertDatabaseCount('cad_drawings', 2);
    }

    public function test_invalid_second_part_leaves_no_partial_drawings_or_working_files(): void
    {
        $id = $this->upload()->assertStatus(202)->json('id');
        $this->nativeReport(true);
        $this->worker($id);
        $this->getJson('/admin-api/cad/conversions/'.$id)->assertJsonPath('status', 'failed');
        $this->assertDatabaseCount('cad_drawings', 0);
        $task = CadConversion::findOrFail($id);
        $this->assertSame([$task->source_path], Storage::disk('local')->allFiles('cad/conversions'));
    }

    public function test_hash_mismatch_is_rejected_before_native_conversion(): void
    {
        $id = $this->upload()->assertStatus(202)->json('id');
        $this->mock(CadConverter::class)->shouldNotReceive('prepare2D');
        Storage::disk('local')->put(CadConversion::findOrFail($id)->source_path, 'corrupt');
        $this->worker($id);
        $this->getJson('/admin-api/cad/conversions/'.$id)->assertJsonPath('status', 'failed')->assertJsonPath('error', '원본 무결성 검증에 실패했습니다.');
    }

    public function test_expired_lease_retry_and_old_attempt_cannot_publish_or_fail_new_attempt(): void
    {
        $id = $this->upload()->assertStatus(202)->json('id');
        CadConversion::findOrFail($id)->update(['status' => 'processing', 'started_at' => now()->subMinutes(16)]);
        $this->getJson('/admin-api/cad/conversions/'.$id)->assertJsonPath('status', 'failed');
        $this->postJson('/admin-api/cad/conversions/'.$id.'/retry')->assertStatus(202)->assertJsonPath('attempt', 2)->assertJsonPath('status', 'queued');
        $this->mock(CadConverter::class)->shouldNotReceive('prepare2D');
        $this->worker($id, 1);
        (new ProcessCadConversion($id, 1))->failed(new \RuntimeException('old worker'));
        $this->getJson('/admin-api/cad/conversions/'.$id)->assertJsonPath('status', 'queued');
        $this->postJson('/admin-api/cad/conversions/'.$id.'/retry')->assertConflict();
    }

    public function test_async_dwg_export_uses_saved_revision_and_hides_storage_path(): void
    {
        $drawingId = $this->postJson('/admin-api/cad', ['site_id' => $this->site->id, 'title' => 'Plan',
            'file' => UploadedFile::fake()->createWithContent('plan.dxf', self::DXF)])->assertCreated()->json('id');
        $key = (string) Str::uuid();
        $id = $this->postJson('/admin-api/cad/'.$drawingId.'/export-dwg', ['revision' => 1, 'requestKey' => $key])->assertStatus(202)->json('id');
        $this->postJson('/admin-api/cad/'.$drawingId.'/export-dwg', ['revision' => 1, 'requestKey' => $key])->assertStatus(202)->assertJsonPath('id', $id);
        $this->mock(CadConverter::class)->shouldReceive('convert')->once()->with(self::DXF, 'dxf', 'dwg')->andReturn(['bytes' => self::DWG, 'warnings' => []]);
        $this->worker($id);
        $response = $this->getJson('/admin-api/cad/conversions/'.$id)->assertOk()->assertJsonPath('status', 'completed');
        $this->assertArrayNotHasKey('outputPath', $response->json('result'));
        $task = CadConversion::findOrFail($id);
        $this->assertSame(self::DWG, Storage::disk('local')->get($task->result['outputPath']));
        $this->get('/admin-api/cad/conversions/'.$id.'/download')->assertOk();
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_actual_native_worker_produces_a_working_copy_that_can_be_saved(): void
    {
        if (! getenv('CAD_TEST_CONVERTER_BINARY')) {
            $this->markTestSkipped('Actual Linux converter integration runs in CAD CI.');
        }
        config(['cad.converter_binary' => getenv('CAD_TEST_CONVERTER_BINARY'), 'cad.dotnet_binary' => getenv('CAD_TEST_DOTNET_BINARY') ?: 'dotnet',
            'cad.converter_heap_mib' => 1024, 'cad.worker_active' => true]);
        $native = app(CadConverter::class)->convert(self::DXF, 'dxf', 'dwg');
        $id = $this->upload(bytes: $native['bytes'])->assertStatus(202)->json('id');
        $this->worker($id);
        $response = $this->getJson('/admin-api/cad/conversions/'.$id)->assertOk()->assertJsonPath('status', 'completed');
        $drawingId = $response->json('result.drawings.0.id');
        $source = $this->getJson('/admin-api/cad/'.$drawingId)->assertOk()->json('dxf');
        $this->putJson('/admin-api/cad/'.$drawingId, ['revision' => 1, 'dxf' => $source])->assertOk()->assertJsonPath('revision', 2);
        $drawing = CadDrawing::findOrFail($drawingId);
        $this->assertSame(hash('sha256', $native['bytes']), hash('sha256', Storage::disk('local')->get($drawing->original_path)));
    }
}
