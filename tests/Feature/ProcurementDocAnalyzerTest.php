<?php

namespace Tests\Feature;

use App\Services\Ocr\OcrEngine;
use App\Services\Procurement\ProcurementDocAnalyzer;
use Tests\TestCase;

/**
 * 조달 서류 AI 분석 — 문서 종류로 파이프라인 단계 자동 판정 + 벤더·PO·금액·ETA 정규화.
 */
class ProcurementDocAnalyzerTest extends TestCase
{
    private function fakeEngine(array $data): void
    {
        $this->app->instance(OcrEngine::class, new class($data) implements OcrEngine {
            public function __construct(private array $data) {}

            public function analyze(array $images, string $prompt, array $schema): array
            {
                return ['data' => $this->data, 'model' => 'gemini-test'];
            }

            public function name(): string
            {
                return 'gemini';
            }

            /** 시험 대역의 한도는 넉넉히 — 여기서 보는 것은 크기 정책이 아니라 판독 흐름이다. */
            public function maxAttachmentBytes(): int
            {
                return 50 * 1024 * 1024;
            }
        });
    }

    private function tempPng(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'po').'.png';
        file_put_contents($path, "\x89PNG fake");

        return $path;
    }

    public function test_purchase_order_maps_to_ordered_stage_and_extracts_fields(): void
    {
        $this->fakeEngine([
            'doc_kind' => 'purchase_order', 'vendor' => 'LG Energy', 'po_no' => 'PO-2026-001',
            'amount' => 48000, 'currency' => 'USD', 'order_date' => '2026-07-01', 'eta' => '2026-09-15',
            'item_summary' => '기계설비 디퓨저', 'summary' => '디퓨저 발주',
        ]);
        $tmp = $this->tempPng();

        $data = app(ProcurementDocAnalyzer::class)->analyze($tmp, 'image/png');
        @unlink($tmp);

        $this->assertSame('발주완료', $data['stage']);   // PO → 발주완료
        $this->assertSame('LG Energy', $data['vendor']);
        $this->assertSame('PO-2026-001', $data['po_no']);
        $this->assertSame(48000.0, $data['amount']);
        $this->assertSame('2026-09-15', $data['eta']);
    }

    public function test_shipping_and_delivery_map_to_later_stages(): void
    {
        $this->fakeEngine(['doc_kind' => 'shipping', 'eta' => '2026-09-20', 'summary' => '선적']);
        $tmp = $this->tempPng();
        $this->assertSame('선적중', app(ProcurementDocAnalyzer::class)->analyze($tmp, 'image/png')['stage']);
        @unlink($tmp);

        $this->fakeEngine(['doc_kind' => 'delivery', 'summary' => '입고']);
        $tmp = $this->tempPng();
        $this->assertSame('입고완료', app(ProcurementDocAnalyzer::class)->analyze($tmp, 'image/png')['stage']);
        @unlink($tmp);
    }

    public function test_quote_does_not_change_stage(): void
    {
        $this->fakeEngine(['doc_kind' => 'quote', 'vendor' => 'ACME', 'summary' => '견적']);
        $tmp = $this->tempPng();

        $data = app(ProcurementDocAnalyzer::class)->analyze($tmp, 'image/png');
        @unlink($tmp);

        $this->assertNull($data['stage']);           // 견적 → 단계 변경 없음
        $this->assertSame('ACME', $data['vendor']);
    }
}
