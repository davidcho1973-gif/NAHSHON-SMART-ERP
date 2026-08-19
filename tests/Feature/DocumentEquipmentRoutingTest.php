<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\IntelligentDocument;
use App\Models\Site;
use App\Services\Equipment\DocumentEquipmentConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 장비를 빌린 문서는 장비 대장에도 닿는다.
 *
 * 선벨트 임대 영수증을 문서함에 올렸더니 경비로는 넘어갔는데 <b>장비 대장에는
 * 그 굴착기가 없었다.</b> 빌린 장비가 대장에 없으면 지금 현장에 뭐가 나가 있는지,
 * 반납일이 언제인지 아무도 모른다 — "문서를 알맞은 모듈에 넣어라" 에서 재무만
 * 잇고 장비를 빼먹은 것이다. 여기서 그 길을 지킨다.
 */
class DocumentEquipmentRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function analyzedDocument(array $payload, array $attributes = []): IntelligentDocument
    {
        return IntelligentDocument::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'source' => 'dropzone',
            'disk' => 'local',
            'file_path' => 'document-intelligence/inbox/'.Str::uuid().'/doc.pdf',
            'original_file_name' => 'Sunbelt_186971353.pdf',
            'stored_file_name' => 'doc.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'file_size' => 100,
            'sha256' => hash('sha256', (string) Str::uuid()),
            'title' => 'Sunbelt Rentals Contract 186971353',
            'sender' => 'SUNBELT RENTALS, INC.',
            'document_type' => 'invoice',
            'received_at' => now(),
            'ai_status' => 'ready',
            'ai_payload' => $payload,
        ], $attributes));
    }

    private array $sunbelt = [
        'equipment' => [
            'involved' => 'rental', 'name' => 'JCB 18Z Mini Excavator', 'model' => 'JCB 18Z-1',
            'rate' => 3186, 'rate_unit' => 'month',
            'rent_start' => '2026-07-24', 'rent_end' => '2026-08-24',
        ],
        'money' => ['flow' => 'out', 'amount' => 3186, 'payee' => 'Sunbelt Rentals', 'category_hint' => 'equipment'],
    ];

    // ── 임대 문서가 대장에 닿는가 ──────────────────────────────────────

    public function test_a_rental_document_registers_the_machine_on_the_ledger(): void
    {
        $site = Site::create(['code' => 'S-EQD', 'name' => '링크 현장', 'timezone' => 'America/Phoenix', 'status' => 'active']);
        $doc = $this->analyzedDocument($this->sunbelt, ['site_id' => $site->id]);

        app(DocumentEquipmentConnector::class)->sync($doc);

        $equipment = Equipment::query()->where('equipment_code', "DOC-{$doc->id}")->first();

        $this->assertNotNull($equipment, '임대 문서의 장비가 대장에 등록되지 않았습니다 — 빌린 장비를 아무도 모릅니다.');
        $this->assertSame('임대', $equipment->acquisition_type);
        $this->assertSame('JCB 18Z Mini Excavator', $equipment->equipment_type);
        $this->assertSame('JCB 18Z-1', $equipment->model);
        $this->assertSame('Sunbelt Rentals', $equipment->vendor);
        $this->assertSame('2026-07-24', $equipment->rent_start?->toDateString());
        $this->assertSame('2026-08-24', $equipment->rent_end?->toDateString());
        $this->assertSame('사용중', $equipment->status, '현장이 지정된 문서면 현장 배치(사용중)여야 합니다.');
        $this->assertSame('AI자동분석', $equipment->registration_method);
    }

    public function test_without_a_site_the_machine_waits_in_the_yard(): void
    {
        $doc = $this->analyzedDocument($this->sunbelt);
        app(DocumentEquipmentConnector::class)->sync($doc);

        $this->assertSame('대기중', Equipment::query()->where('equipment_code', "DOC-{$doc->id}")->value('status'));
    }

    public function test_a_purchase_document_lands_as_owned(): void
    {
        $doc = $this->analyzedDocument([
            'equipment' => ['involved' => 'purchase', 'name' => 'Miller 용접기', 'model' => 'XMT 350'],
            'money' => ['flow' => 'out', 'amount' => 5200, 'category_hint' => 'equipment'],
        ]);

        app(DocumentEquipmentConnector::class)->sync($doc);

        $equipment = Equipment::query()->where('equipment_code', "DOC-{$doc->id}")->firstOrFail();
        $this->assertSame('소유', $equipment->acquisition_type);
        $this->assertNull($equipment->rent_start);
    }

    // ── 이중 계상이 생기지 않는가 ──────────────────────────────────────

    public function test_the_rate_stays_in_the_payload_so_rent_is_not_double_counted(): void
    {
        // daily_rate 를 채우면 월별 자동 계상이 시작되는데, 같은 문서가 이미
        // 경비(실제 청구액)로도 넘어가 있다 — 같은 임대료가 두 번 잡힌다.
        $doc = $this->analyzedDocument($this->sunbelt);
        app(DocumentEquipmentConnector::class)->sync($doc);

        $equipment = Equipment::query()->where('equipment_code', "DOC-{$doc->id}")->firstOrFail();
        $this->assertSame(0.0, (float) $equipment->daily_rate,
            '요율이 대장에 바로 앉았습니다 — 인보이스 경비와 이중 계상됩니다.');
        $this->assertSame(3186.0, (float) ($equipment->payload['rate'] ?? 0), '읽은 요율이 보관되지 않았습니다.');
        $this->assertSame('month', $equipment->payload['rate_unit'] ?? null);
    }

    // ── 멱등과 사람 존중 ───────────────────────────────────────────────

    public function test_reanalysis_does_not_duplicate_the_machine(): void
    {
        $doc = $this->analyzedDocument($this->sunbelt);

        app(DocumentEquipmentConnector::class)->sync($doc);
        app(DocumentEquipmentConnector::class)->sync($doc);

        $this->assertSame(1, Equipment::query()->where('equipment_code', "DOC-{$doc->id}")->count());
    }

    public function test_a_row_a_person_already_curated_is_left_alone(): void
    {
        // 사람이 요율을 확정하고 등록 방식을 바꾼 줄을 재분석이 도로 덮으면,
        // 확정한 월별 계상이 소리 없이 풀린다.
        $doc = $this->analyzedDocument($this->sunbelt);
        app(DocumentEquipmentConnector::class)->sync($doc);

        Equipment::query()->where('equipment_code', "DOC-{$doc->id}")
            ->update(['registration_method' => 'manual', 'daily_rate' => 106, 'vendor' => 'Sunbelt Rentals (확정)']);

        app(DocumentEquipmentConnector::class)->sync($doc->fresh());

        $equipment = Equipment::query()->where('equipment_code', "DOC-{$doc->id}")->firstOrFail();
        $this->assertSame(106.0, (float) $equipment->daily_rate, '사람이 확정한 요율을 재분석이 덮었습니다.');
        $this->assertSame('Sunbelt Rentals (확정)', $equipment->vendor);
    }

    public function test_a_document_that_is_not_about_equipment_creates_nothing(): void
    {
        $doc = $this->analyzedDocument([
            'equipment' => ['involved' => 'none'],
            'money' => ['flow' => 'out', 'amount' => 120, 'category_hint' => 'meals'],
        ]);

        app(DocumentEquipmentConnector::class)->sync($doc);

        $this->assertSame(0, Equipment::query()->count());
    }

    // ── 길 자체가 살아 있는가 ──────────────────────────────────────────

    public function test_the_analysis_pipeline_actually_calls_the_equipment_connector(): void
    {
        $service = (string) file_get_contents(base_path('app/Services/Documents/DocumentIntelligenceService.php'));
        $this->assertStringContainsString('DocumentEquipmentConnector', $service,
            '분석 완료 지점이 장비 커넥터를 부르지 않습니다 — 임대 문서가 다시 문서함에서 끝납니다.');

        $analyzer = (string) file_get_contents(base_path('app/Services/Documents/DocumentIntelligenceAnalyzer.php'));
        $this->assertStringContainsString("'involved'", $analyzer,
            '분석 스키마에서 장비 추출이 빠졌습니다.');
    }
}
