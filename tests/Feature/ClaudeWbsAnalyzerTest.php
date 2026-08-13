<?php

namespace Tests\Feature;

use App\Models\WbsItem;
use App\Services\Wbs\ClaudeWbsAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClaudeWbsAnalyzerTest extends TestCase
{
    use RefreshDatabase;

    private function fakeClaudeWbs(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode([
                        'stages' => [[
                            'stage_no' => '1',
                            'stage_name' => '반입 및 설치',
                            'tasks' => [[
                                'task_no' => '1.1',
                                'task_name' => '장비 반입',
                                'sub_tasks' => [
                                    ['sub_no' => '1.1.1', 'sub_name' => '크레인 양중', 'company' => 'ABC ENG', 'manhours' => 40, 'days' => 2, 'ehs' => 'high'],
                                    ['sub_no' => '1.1.2', 'sub_name' => '앵커 시공', 'company' => 'AI-KOREA', 'manhours' => 24, 'days' => 1, 'ehs' => 'medium'],
                                ],
                            ]],
                        ]],
                    ]),
                ]],
            ]),
        ]);
    }

    public function test_it_generates_and_persists_wbs_from_claude_response(): void
    {
        config(['services.anthropic.api_key' => 'test-anthropic-key', 'services.anthropic.model' => 'claude-opus-4-8']);
        $this->fakeClaudeWbs();

        $result = app(ClaudeWbsAnalyzer::class)->processManual('CLD-01');

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['processed']);
        $this->assertSame('claude', $result['results'][0]['engine']);
        $this->assertSame(1, $result['results'][0]['stages']);
        $this->assertSame(1, $result['results'][0]['tasks']);
        $this->assertSame(2, $result['results'][0]['subTasks']);

        // Persisted to wbs_items as an AI-sourced tree.
        $this->assertSame(1, WbsItem::where('project_code', 'CLD-01')->where('level', 'stage')->count());
        $this->assertSame(2, WbsItem::where('project_code', 'CLD-01')->where('level', 'subtask')->count());
        $this->assertSame('high', WbsItem::where('project_code', 'CLD-01')->where('node_no', '1.1.1')->value('ehs'));

        // Correct Anthropic request shape: model, structured-output schema, message content.
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return str_contains($request->url(), 'api.anthropic.com/v1/messages')
                && $request->hasHeader('x-api-key', 'test-anthropic-key')
                && $request->hasHeader('anthropic-version', '2023-06-01')
                && data_get($data, 'model') === 'claude-opus-4-8'
                && data_get($data, 'output_config.format.type') === 'json_schema'
                && data_get($data, 'output_config.format.schema.type') === 'object'
                && data_get($data, 'messages.0.role') === 'user';
        });
    }

    public function test_it_sends_pdf_document_block_when_manual_provided(): void
    {
        config(['services.anthropic.api_key' => 'test-anthropic-key']);
        $this->fakeClaudeWbs();

        app(ClaudeWbsAnalyzer::class)->processManual('CLD-01', 'ALL', [
            'data' => base64_encode('%PDF-1.4 fake manual bytes'),
            'media_type' => 'application/pdf',
        ]);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return data_get($data, 'messages.0.content.0.type') === 'document'
                && data_get($data, 'messages.0.content.0.source.media_type') === 'application/pdf'
                && filled(data_get($data, 'messages.0.content.0.source.data'))
                && data_get($data, 'messages.0.content.1.type') === 'text';
        });
    }

    public function test_it_returns_friendly_error_without_api_key(): void
    {
        config(['services.anthropic.api_key' => null]);

        $result = app(ClaudeWbsAnalyzer::class)->processManual('CLD-01');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ANTHROPIC_API_KEY', $result['error']);
    }

    public function test_it_surfaces_a_refusal_as_an_error(): void
    {
        // generate() throws on refusal; SmartCompanyData::processWbsManual catches it
        // and converts to a friendly error (same pattern as GeminiWbsAnalyzer).
        config(['services.anthropic.api_key' => 'test-anthropic-key']);
        Http::fake([
            'api.anthropic.com/*' => Http::response(['stop_reason' => 'refusal', 'content' => []]),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('refusal');

        app(ClaudeWbsAnalyzer::class)->processManual('CLD-01');
    }
}
