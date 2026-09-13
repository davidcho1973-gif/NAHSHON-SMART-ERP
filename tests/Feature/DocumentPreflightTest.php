<?php

namespace Tests\Feature;

use App\Models\IntelligentDocument;
use App\Services\Ocr\OcrEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DocumentPreflightTest extends TestCase
{
    use RefreshDatabase;

    private function document(): IntelligentDocument
    {
        Storage::fake('local');
        $bytes = 'This is a source document long enough for text extraction.';
        Storage::disk('local')->put('audit/source.txt', $bytes);

        return IntelligentDocument::create([
            'uuid' => (string) Str::uuid(), 'source' => 'dropzone', 'disk' => 'local',
            'file_path' => 'audit/source.txt', 'original_file_name' => 'source.txt', 'stored_file_name' => 'source.txt',
            'mime_type' => 'text/plain', 'extension' => 'txt', 'file_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes), 'title' => 'Source', 'received_at' => now(), 'ai_status' => 'failed',
        ]);
    }

    public function test_full_preflight_checks_source_without_ai_or_record_changes(): void
    {
        $document = $this->document();
        $before = $document->fresh()->getAttributes();
        $this->mock(OcrEngine::class)->shouldNotReceive('analyze');
        $this->artisan('docs:preflight')
            ->expectsOutputToContain('"total":1,"checked":1,"failed":0,"truncated":0,"remaining":0,"last_id":'.$document->id.',"ai_calls":0,"records_changed":0')
            ->assertSuccessful();
        $this->assertSame($before, $document->fresh()->getAttributes());
    }

    public function test_preflight_reports_source_hash_mismatch_and_failure_exit(): void
    {
        $document = $this->document();
        Storage::disk('local')->put('audit/source.txt', 'replaced bytes');
        $this->mock(OcrEngine::class)->shouldNotReceive('analyze');
        $this->artisan('docs:preflight')->expectsOutputToContain('[SOURCE_MISMATCH]')
            ->expectsOutputToContain('"failed":1')->assertExitCode(1);
        $this->assertSame('failed', $document->fresh()->ai_status);
    }

    public function test_failed_documents_cannot_produce_an_all_clear_diagnosis(): void
    {
        $this->document();
        config(['services.gemini.api_key' => 'test']);
        $this->mock(OcrEngine::class)->shouldReceive('maxAttachmentBytes')->once()->andReturn(50 * 1048576);
        $this->artisan('docs:diagnose')->expectsOutputToContain('분석 실패 문서 1건')
            ->doesntExpectOutputToContain('막힌 곳을 찾지 못했습니다. 분석이 정상으로 보입니다.')
            ->assertSuccessful();
    }
}
