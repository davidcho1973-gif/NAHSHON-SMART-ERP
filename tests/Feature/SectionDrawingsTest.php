<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DrawingSheet;
use App\Models\IntelligentDocument;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkSection;
use App\Services\Drawings\SectionDrawingService;
use App\Services\Ocr\OcrEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 공정별 도면 — 사장 지시(2026-09-25): 「사진으로 되어 있을 경우 도면을 올리면 자동으로 글자를
 * 읽어 붙이게. 공정별로 사용할 도면을 선정해서 저장해줘.」
 *
 * 잠그는 것:
 *  - 도면 파일은 문서함 한 곳에 있다. 이 기능은 쪽마다의 번호·글자만 적는다.
 *  - 글자가 든 쪽은 그 글자를 쓰고, 사진 쪽은 조각을 AI 가 읽어 붙인다. 조각은 읽고 나면 지운다.
 *  - 선택은 도면 번호로 잇는다 — 개정판이 오면 새 파일의 장이 붙는다.
 *  - 사람이 고친 번호는 다시 읽어도 덮지 않는다.
 *  - 703K 는 계약서 공정 24개와 공정별 도면이 미리 골라져 있다.
 */
class SectionDrawingsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Site $site;

    /** @var array<int, array{images: int, prompt: string}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.documents_disk' => 'drawings-test']);
        Storage::fake('drawings-test');
        Storage::fake('local');

        $this->company = Company::create(['code' => 'C1', 'name' => 'ABC ENG', 'status' => 'active']);
        $this->site = Site::create(['code' => '703K', 'name' => 'Savannah', 'country' => 'US',
            'timezone' => 'America/New_York', 'status' => 'active', 'company_id' => $this->company->id]);
    }

    private function admin(string $role = 'super_admin'): User
    {
        $user = User::factory()->create(['access_role' => $role, 'access_scope' => 'all_sites', 'account_status' => 'active']);
        $this->actingAs($user);

        return $user;
    }

    private function drawing(string $title = '06_배관.pdf', ?int $siteId = null, array $extra = []): IntelligentDocument
    {
        $bytes = '%PDF-1.4 '.$title.' '.Str::random(20);   // 진짜 해시여야 문서관리 다리가 사본을 만들지 않는다
        Storage::disk('local')->put('docs/'.$title, $bytes);

        return IntelligentDocument::create($extra + [
            'uuid' => (string) Str::uuid(), 'source' => 'dropzone', 'disk' => 'local',
            'file_path' => 'docs/'.$title, 'original_file_name' => $title, 'stored_file_name' => $title,
            'mime_type' => 'application/pdf', 'extension' => 'pdf', 'file_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes), 'title' => $title, 'received_at' => now(), 'ai_status' => 'ready',
            'category' => 'drawing_spec', 'site_id' => $siteId ?? $this->site->id, 'company_id' => $this->company->id,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function fakeAi(array $data): void
    {
        $engine = \Mockery::mock(OcrEngine::class);
        $engine->shouldReceive('analyze')->andReturnUsing(function (array $images, string $prompt) use ($data): array {
            $this->calls[] = ['images' => count($images), 'prompt' => $prompt];

            return ['data' => $data, 'model' => 'fake'];
        });
        $engine->shouldReceive('name')->andReturn('fake');
        $this->app->instance(OcrEngine::class, $engine);
    }

    private function seed703k(): void
    {
        (require database_path('migrations/2026_09_25_000101_seed_703k_work_sections.php'))->up();
    }

    private function vectorText(): string
    {
        return str_repeat('PLUMBING - KITCHEN FIRST FLOOR PLAN 3" GV THROUGH SIDEWALL SEE 703K-P405 ', 5).' 703K-P301 08/21/2026';
    }

    // ── 703K 공정 ────────────────────────────────────────────────────

    public function test_703k_gets_the_24_contract_sections_with_amounts_and_sheet_picks(): void
    {
        $this->seed703k();
        $this->seed703k();   // 두 번 돌아도 안전

        $sections = WorkSection::query()->where('site_id', $this->site->id)->orderBy('sort_order')->get();
        $this->assertCount(24, $sections);
        $this->assertSame(1789820.36, round($sections->sum('contract_amount'), 2), '섹션 합계 = 계약서 품목 합계(Round Off 전)');
        $this->assertSame(['건축공사', '설비공사', '전기공사'], $sections->pluck('division')->unique()->values()->all());

        $drywall = $sections->firstWhere('code', 'A3');
        $this->assertSame('3) DRYWALL', $drywall->name);
        $this->assertSame(['703K-A01-01', '703K-A07-01', '703K-A07-02'], $drywall->sheets->pluck('sheet_no')->all());
        $this->assertSame(['703K-F201'], $sections->firstWhere('code', 'M1-4')->sheets->pluck('sheet_no')->all());
    }

    public function test_the_seed_does_not_undo_a_persons_pick(): void
    {
        $this->seed703k();
        $this->admin();
        $drywall = WorkSection::query()->where('code', 'A3')->first();
        app(SectionDrawingService::class)->setSheets($drywall->id, ['703K-A01-01']);

        $this->seed703k();

        $this->assertSame(['703K-A01-01'], $drywall->fresh()->sheets->pluck('sheet_no')->all());
    }

    // ── 쪽 읽기 ──────────────────────────────────────────────────────

    public function test_a_page_with_text_keeps_its_own_text_and_ai_only_picks_the_title_block(): void
    {
        $this->seed703k();
        $this->admin();
        $this->fakeAi(['sheet_no' => '703k-p301 ', 'title' => 'PLUMBING - KITCHEN FIRST FLOOR PLAN', 'discipline' => 'Plumbing']);
        $doc = $this->drawing();

        $this->postJson("/drawing-api/documents/{$doc->id}/start", ['page_count' => 3])->assertOk()
            ->assertJsonCount(3, 'sheets');
        $this->post("/drawing-api/documents/{$doc->id}/pages/2", [
            'text' => $this->vectorText(), 'width' => 3168, 'height' => 2448,
            'thumb' => UploadedFile::fake()->image('thumb.jpg', 90, 70),
        ])->assertOk();

        $sheet = DrawingSheet::query()->where('intelligent_document_id', $doc->id)->where('page_no', 2)->first();
        $this->assertSame('done', $sheet->status);
        $this->assertSame('pdf', $sheet->text_source);
        $this->assertSame('703K-P301', $sheet->sheet_no, '번호는 대문자·공백 없이');
        $this->assertStringContainsString('3" GV THROUGH SIDEWALL', $sheet->text, '글자는 도면에 든 것을 그대로');
        $this->assertSame(0, $this->calls[0]['images'], '글자가 든 쪽은 그림을 AI 에 보내지 않는다');
        $this->assertStringContainsString('703K-P301', $this->calls[0]['prompt'], '알려진 도면 번호를 힌트로 준다');
        Storage::disk('drawings-test')->assertExists($sheet->thumb_path);
    }

    public function test_a_picture_page_is_read_by_ai_from_four_tiles_and_the_tiles_are_removed(): void
    {
        $this->seed703k();
        $this->admin();
        $this->fakeAi(['sheet_no' => '703K-A01-01', 'title' => 'OVERALL FLOOR PLAN', 'discipline' => 'Architectural',
            'text' => "KITCHEN 100\nOFFICE 108\nDISH WASH 109"]);
        $doc = $this->drawing('04_건축.pdf');

        $this->post("/drawing-api/documents/{$doc->id}/pages/2", [
            'width' => 3168, 'height' => 2448,
            'thumb' => UploadedFile::fake()->image('thumb.jpg', 90, 70),
            'tiles' => [UploadedFile::fake()->image('t0.jpg'), UploadedFile::fake()->image('t1.jpg'),
                UploadedFile::fake()->image('t2.jpg'), UploadedFile::fake()->image('t3.jpg')],
        ])->assertOk();

        $sheet = DrawingSheet::query()->where('intelligent_document_id', $doc->id)->first();
        $this->assertSame('ocr', $sheet->text_source);
        $this->assertSame('703K-A01-01', $sheet->sheet_no);
        $this->assertStringContainsString('OFFICE 108', $sheet->text);
        $this->assertSame(4, $this->calls[0]['images']);
        $this->assertStringContainsString('네 조각', $this->calls[0]['prompt']);
        $this->assertSame([], Storage::disk('drawings-test')->files('drawing-sheets/'.$doc->id.'/tiles/p2'), '조각은 읽고 나면 지운다');
    }

    public function test_when_ai_misses_the_number_a_single_known_number_in_the_text_is_used(): void
    {
        $this->seed703k();
        $this->admin();
        $this->fakeAi(['sheet_no' => '', 'title' => '', 'discipline' => '']);
        $doc = $this->drawing();

        $this->post("/drawing-api/documents/{$doc->id}/pages/1", ['text' => str_repeat('KITCHEN HVAC PLAN ', 20).' 703K-M201'])->assertOk();
        $this->assertSame('703K-M201', DrawingSheet::query()->first()->sheet_no);

        // 알려진 번호가 둘 이상 나오면(참조 섞임) 고르지 않는다 — 틀린 번호보다 빈칸이 낫다.
        $this->post("/drawing-api/documents/{$doc->id}/pages/2", ['text' => str_repeat('SEE 703K-P401 AND 703K-P405 ', 20)])->assertOk();
        $this->assertNull(DrawingSheet::query()->where('page_no', 2)->first()->sheet_no);
    }

    public function test_a_failed_read_is_recorded_not_lost(): void
    {
        $this->admin();
        $engine = \Mockery::mock(OcrEngine::class);
        $engine->shouldReceive('analyze')->andThrow(new \RuntimeException('Gemini 응답 없음'));
        $this->app->instance(OcrEngine::class, $engine);
        $doc = $this->drawing();

        $this->post("/drawing-api/documents/{$doc->id}/pages/1", [
            'tiles' => [UploadedFile::fake()->image('t0.jpg')],
        ])->assertOk();

        $sheet = DrawingSheet::query()->first();
        $this->assertSame('failed', $sheet->status);
        $this->assertStringContainsString('Gemini 응답 없음', $sheet->error);
        $this->assertSame([], Storage::disk('drawings-test')->files('drawing-sheets/'.$doc->id.'/tiles/p1'));
    }

    public function test_a_text_page_is_still_read_when_the_ai_is_down(): void
    {
        $this->seed703k();
        $this->admin();
        $engine = \Mockery::mock(OcrEngine::class);
        $engine->shouldReceive('analyze')->andThrow(new \RuntimeException('GEMINI_API_KEY is not configured.'));
        $this->app->instance(OcrEngine::class, $engine);
        $doc = $this->drawing();

        $this->post("/drawing-api/documents/{$doc->id}/pages/1", ['text' => $this->vectorText()])->assertOk();

        $sheet = DrawingSheet::query()->first();
        $this->assertSame('done', $sheet->status, '글자가 정본이다 — AI 가 멈춰도 쪽은 읽힌다');
        $this->assertSame('pdf', $sheet->text_source);
        $this->assertNull($sheet->sheet_no, '알려진 번호가 둘(P301·P405) 나오므로 고르지 않는다');
    }

    public function test_a_number_a_person_fixed_survives_a_re_read(): void
    {
        $this->admin();
        $this->fakeAi(['sheet_no' => 'WRONG-1', 'title' => 'WRONG', 'discipline' => '']);
        $doc = $this->drawing();
        $this->post("/drawing-api/documents/{$doc->id}/pages/1", ['text' => $this->vectorText()])->assertOk();
        $sheet = DrawingSheet::query()->first();

        app(SectionDrawingService::class)->updateSheet($sheet->id, ' 703k-p301', 'PLUMBING PLAN');
        $this->post("/drawing-api/documents/{$doc->id}/pages/1", ['text' => $this->vectorText()])->assertOk();

        $sheet->refresh();
        $this->assertSame('703K-P301', $sheet->sheet_no);
        $this->assertSame('PLUMBING PLAN', $sheet->title);
        $this->assertTrue($sheet->manual);
    }

    public function test_a_shorter_new_file_drops_the_extra_pages(): void
    {
        $this->admin();
        $doc = $this->drawing();
        $this->postJson("/drawing-api/documents/{$doc->id}/start", ['page_count' => 5])->assertOk();
        $this->postJson("/drawing-api/documents/{$doc->id}/start", ['page_count' => 3])->assertOk()->assertJsonCount(3, 'sheets');
    }

    // ── 선택과 화면 ──────────────────────────────────────────────────

    public function test_board_resolves_picks_by_number_and_a_revision_replaces_the_old_page(): void
    {
        $this->seed703k();
        $this->admin();
        $old = $this->drawing('06_배관_rev0.pdf');
        $old->forceFill(['created_at' => now()->subDays(3)])->save();
        $new = $this->drawing('06_배관_rev1.pdf');
        DrawingSheet::create(['intelligent_document_id' => $old->id, 'site_id' => $this->site->id, 'page_no' => 7, 'sheet_no' => '703K-P301', 'status' => 'done']);
        DrawingSheet::create(['intelligent_document_id' => $new->id, 'site_id' => $this->site->id, 'page_no' => 7, 'sheet_no' => '703K-P301', 'status' => 'done']);

        $board = app(SectionDrawingService::class)->board('703K');

        $water = collect($board['sections'])->firstWhere('code', 'M1-3-1');
        $p301 = collect($water['sheets'])->firstWhere('sheetNo', '703K-P301');
        $this->assertTrue($p301['found']);
        $this->assertSame($new->id, $p301['sheet']['documentId'], '같은 번호면 늦게 올라온 파일의 장');
        $this->assertFalse(collect($water['sheets'])->firstWhere('sheetNo', '703K-P402')['found'], '아직 안 읽힌 번호도 선택은 산다');
        $this->assertCount(2, $board['documents']);
        $this->assertSame(24, count($board['sections']));
    }

    public function test_picking_sheets_replaces_the_selection_in_order_and_normalizes_numbers(): void
    {
        $this->seed703k();
        $this->admin();
        $duct = WorkSection::query()->where('code', 'M1-2')->first();

        $res = app(SectionDrawingService::class)->setSheets($duct->id, [' 703k-m401', '703K-M201', '703K-M201', '']);

        $this->assertTrue($res['success']);
        $this->assertSame(['703K-M401', '703K-M201'], $duct->fresh()->sheets->pluck('sheet_no')->all());
    }

    public function test_other_pdfs_are_offered_and_reading_one_makes_it_a_drawing_file(): void
    {
        $this->admin();
        $this->fakeAi(['sheet_no' => '', 'title' => '', 'discipline' => '']);
        $misfiled = $this->drawing('scan.pdf', null, ['category' => 'general']);

        $board = app(SectionDrawingService::class)->board('703K');
        $this->assertSame([$misfiled->id], array_column($board['otherPdfs'], 'id'));
        $this->assertSame([], $board['documents']);

        $this->postJson("/drawing-api/documents/{$misfiled->id}/start", ['page_count' => 1])->assertOk();

        $board = app(SectionDrawingService::class)->board('703K');
        $this->assertSame([$misfiled->id], array_column($board['documents'], 'id'));
        $this->assertSame([], $board['otherPdfs']);
    }

    public function test_only_site_operators_read_and_pick_and_other_sites_stay_hidden(): void
    {
        $this->seed703k();
        $other = Site::create(['code' => 'X1', 'name' => 'Other', 'status' => 'active', 'company_id' => $this->company->id]);
        $foreign = $this->drawing('other.pdf', $other->id);
        $mine = $this->drawing();

        $this->admin('safety_manager');
        $this->postJson("/drawing-api/documents/{$mine->id}/start", ['page_count' => 1])->assertForbidden();
        $this->assertFalse(app(SectionDrawingService::class)->setSheets(WorkSection::query()->first()->id, ['X'])['success']);

        User::query()->delete();
        $this->actingAs(User::factory()->create(['access_role' => 'site_manager', 'access_scope' => 'site',
            'allowed_site_id' => $this->site->id, 'allowed_company_id' => $this->company->id, 'account_status' => 'active']));
        $this->postJson("/drawing-api/documents/{$foreign->id}/start", ['page_count' => 1])->assertNotFound();
        $this->postJson("/drawing-api/documents/{$mine->id}/start", ['page_count' => 1])->assertOk();
    }

    public function test_the_page_endpoint_refuses_non_jpeg_tiles(): void
    {
        $this->admin();
        $doc = $this->drawing();

        $this->postJson("/drawing-api/documents/{$doc->id}/pages/1", [
            'tiles' => [UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')],
        ])->assertUnprocessable();
    }
}
