<?php

namespace Tests\Feature;

use App\Models\SafetyWorkItem;
use App\Services\Safety\GeminiSafetyAnalyzer;
use App\Services\Safety\SafetyWorkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 작업 마감 시 현장 사진을 첨부하면 AI가 사진+보고를 종합 분석한다.
 *
 * 사진이 있으면 비전 엔진(OcrEngine) 경로를 타고 사진 소견/품질/안전 지적을 함께 돌려주고,
 * 없으면 기존 텍스트 전용 경로로 동작한다.
 */
class SafetyProgressPhotoTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGemini(array $payload): void
    {
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'gemini-3.5-flash', 'ocr.engine' => 'gemini']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode($payload)]]]]],
            ]),
        ]);
    }

    private function item(array $overrides = []): array
    {
        return array_merge([
            'id' => 'WRK-PH-1', 'project' => 'LGES-AZ 전기', 'site' => '2층',
            'title' => '천장 케이블 포설', 'crew' => 3, 'qty' => 30, 'unit' => 'm',
            'planStatus' => '초안', 'tbmStatus' => '완료', 'closeStatus' => '마감대기', 'progressStatus' => '미분석',
            'progress' => 0, 'doneQty' => 20, 'totalQty' => 30,
            'workText' => '천장 케이블 30m 포설.', 'closeText' => '20m 완료.', 'signatures' => [], 'issues' => [],
        ], $overrides);
    }

    public function test_photos_trigger_vision_analysis_and_return_findings(): void
    {
        $this->fakeGemini([
            'recommended_progress' => 60,
            'status' => '일부 완료',
            'summary' => '사진상 케이블 트레이 3개 구간 중 2개 완료. 나머지 1개 미시공.',
            'rationale' => '사진에서 미시공 구간이 확인되어 60%로 판단.',
            'photo_findings' => ['트레이 2개 구간 케이블 포설 완료', '우측 1개 구간 미시공'],
            'quality_flags' => ['케이블 밴딩 정리 미흡'],
            'safety_flags' => ['사다리 하부 미고정 상태 노출'],
            'follow_up' => '잔여 구간 자재 입고 후 재개.',
        ]);

        $result = app(SafetyWorkService::class)->recommendProgress(
            $this->item(['photos' => [['data' => 'aGVsbG8=', 'mime_type' => 'image/jpeg']]]),
            'ALL',
            null,
        );

        $rec = $result['recommendation'];
        $this->assertSame(60, $rec['recommended_progress']);
        $this->assertSame(1, $rec['photo_count']);
        $this->assertContains('우측 1개 구간 미시공', $rec['photo_findings']);
        $this->assertContains('케이블 밴딩 정리 미흡', $rec['quality_flags']);
        $this->assertContains('사다리 하부 미고정 상태 노출', $rec['safety_flags']);

        // 종합 분석이 기록(plan_payload)에 저장돼 프로젝트 기록으로 올라간다.
        $saved = SafetyWorkItem::where('work_code', 'WRK-PH-1')->first();
        $this->assertSame(60, (int) $saved->progress);
        $this->assertSame('추천완료', $saved->progress_status);
        $this->assertContains('우측 1개 구간 미시공', $saved->plan_payload['progress']['photo_findings']);
    }

    public function test_data_url_prefixed_photo_is_accepted(): void
    {
        $this->fakeGemini(['recommended_progress' => 50, 'status' => '일부 완료', 'summary' => 's', 'rationale' => 'r', 'photo_findings' => [], 'quality_flags' => [], 'safety_flags' => []]);

        $rec = app(GeminiSafetyAnalyzer::class)->recommendProgress([
            'title' => 't', 'closeText' => 'done',
            'photos' => [['data' => 'data:image/png;base64,iVBORw0KGgo=', 'mime_type' => '']],
        ]);

        $this->assertSame(1, $rec['photo_count']);
    }

    public function test_non_image_attachment_is_ignored_and_falls_back_to_text(): void
    {
        $this->fakeGemini(['recommended_progress' => 40, 'status' => '지연', 'summary' => 's', 'rationale' => 'r', 'follow_up' => '']);

        $rec = app(GeminiSafetyAnalyzer::class)->recommendProgress([
            'title' => 't', 'closeText' => 'done',
            'photos' => [['data' => 'JVBERi0=', 'mime_type' => 'application/pdf']],
        ]);

        $this->assertSame(0, $rec['photo_count']);
        // 텍스트 전용 경로여도 배열 필드는 빈 배열로 정규화된다.
        $this->assertSame([], $rec['photo_findings']);
    }

    public function test_more_than_six_photos_are_capped(): void
    {
        $this->fakeGemini(['recommended_progress' => 70, 'status' => '일부 완료', 'summary' => 's', 'rationale' => 'r', 'photo_findings' => [], 'quality_flags' => [], 'safety_flags' => []]);

        $photos = [];
        for ($i = 0; $i < 9; $i++) {
            $photos[] = ['data' => 'aGVsbG8=', 'mime_type' => 'image/jpeg'];
        }

        $rec = app(GeminiSafetyAnalyzer::class)->recommendProgress([
            'title' => 't', 'closeText' => 'done', 'photos' => $photos,
        ]);

        $this->assertSame(6, $rec['photo_count']);
    }
}
