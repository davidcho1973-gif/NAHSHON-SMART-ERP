<?php

namespace Tests\Feature;

use App\Models\IntelligentDocument;
use App\Models\User;
use App\Support\OfficePreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Writer\Word2007;
use Tests\TestCase;
use ZipArchive;

/**
 * 올린 형식 그대로 보이는가 — 엑셀은 표로, 워드는 문서로, PPT 는 슬라이드로.
 *
 * "바로 보기" 가 PDF·이미지·텍스트만 지원해서, 급여명세서 엑셀 서식을 올린 사람이
 * 표 대신 추출한 글자 덩어리를 봤다. 브라우저는 오피스 형식을 원래 못 그리므로,
 * 서버가 형식별로 HTML 로 변환한다(OfficePreview). 여기서 그 변환이 실제 내용을
 * 담는지, 그리고 업로드된 내용이 스크립트로 살아나지 못하는지 지킨다.
 */
class OfficePreviewTest extends TestCase
{
    use RefreshDatabase;

    private function xlsxBytes(): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Workers');
        $sheet->setCellValue('A1', '성명');
        $sheet->setCellValue('B1', '시급');
        $sheet->setCellValue('A2', '김반장');
        $sheet->setCellValue('B2', 28.5);
        $sheet->getStyle('A1:B1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFFFF2CC'); // 노란 머리행 — 서식이 살아야 한다.

        $second = $spreadsheet->createSheet();
        $second->setTitle('Timesheet');
        $second->setCellValue('A1', '근무시간');

        $tmp = tempnam(sys_get_temp_dir(), 'xl').'.xlsx';
        (new XlsxWriter($spreadsheet))->save($tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    private function docxBytes(): string
    {
        $word = new PhpWord();
        $sectionStyle = $word->addSection();
        $sectionStyle->addText('급여 정산 안내문', ['bold' => true, 'size' => 16]);
        $sectionStyle->addText('노란색 칸만 입력하십시오.');

        $tmp = tempnam(sys_get_temp_dir(), 'wd').'.docx';
        (new Word2007($word))->save($tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    private function pptxBytes(): string
    {
        $slide = static fn (string $title, string $body): string => '<?xml version="1.0"?>'
            .'<p:sld xmlns:p="p" xmlns:a="a"><p:txBody>'
            ."<a:p><a:r><a:t>{$title}</a:t></a:r></a:p>"
            ."<a:p><a:r><a:t>{$body}</a:t></a:r></a:p>"
            .'</p:txBody></p:sld>';

        $tmp = tempnam(sys_get_temp_dir(), 'pp').'.zip';
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::CREATE);
        $zip->addFromString('ppt/slides/slide2.xml', $slide('둘째 장', '마무리'));
        $zip->addFromString('ppt/slides/slide1.xml', $slide('안전 교육', '추락 방지'));
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    // ── 변환 자체 ──────────────────────────────────────────────────────

    public function test_an_excel_file_becomes_a_real_table_not_a_wall_of_text(): void
    {
        $html = OfficePreview::html($this->xlsxBytes(), 'xlsx', '급여 서식');

        $this->assertNotNull($html, '엑셀이 HTML 로 변환되지 않았습니다.');
        $this->assertStringContainsString('<table', $html, '표가 아니라 글자 덩어리로 나왔습니다.');
        $this->assertStringContainsString('김반장', $html);
        $this->assertStringContainsString('Timesheet', $html, '두 번째 시트가 사라졌습니다.');
        $this->assertMatchesRegularExpression('/fff2cc/i', $html, '노란 배경(서식)이 날아갔습니다.');
    }

    public function test_a_word_file_reads_like_a_document(): void
    {
        $html = OfficePreview::html($this->docxBytes(), 'docx', '안내문');

        $this->assertNotNull($html);
        $this->assertStringContainsString('급여 정산 안내문', $html);
        $this->assertStringContainsString('노란색 칸만 입력하십시오.', $html);
    }

    public function test_slides_come_out_in_order_with_slide_frames(): void
    {
        $html = OfficePreview::html($this->pptxBytes(), 'pptx', '교육 자료');

        $this->assertNotNull($html);
        $this->assertStringContainsString('슬라이드 1', $html);
        $this->assertStringContainsString('슬라이드 2', $html);
        $this->assertLessThan(strpos($html, '둘째 장'), strpos($html, '안전 교육'),
            'slide2 가 slide1 보다 먼저 나왔습니다 — zip 순서를 그대로 믿으면 이렇게 됩니다.');
    }

    public function test_a_broken_file_returns_null_instead_of_blowing_up(): void
    {
        // 미리보기 실패가 문서 열람 자체를 막으면 안 된다 — null 이면 호출자가
        // 기존 다운로드 안내로 후퇴한다.
        $this->assertNull(OfficePreview::html('PK not really an office file', 'xlsx'));
        $this->assertNull(OfficePreview::html('garbage', 'docx'));
    }

    // ── 화면까지 이어지는가 ────────────────────────────────────────────

    private function hubDocument(string $bytes, string $extension, string $mime): IntelligentDocument
    {
        $disk = (string) config('document-intelligence.disk', 'local');
        Storage::fake($disk);
        $path = 'document-intelligence/inbox/'.Str::uuid()."/file.{$extension}";
        Storage::disk($disk)->put($path, $bytes);

        return IntelligentDocument::query()->create([
            'uuid' => (string) Str::uuid(),
            'source' => 'dropzone',
            'disk' => $disk,
            'file_path' => $path,
            'original_file_name' => "급여서식.{$extension}",
            'stored_file_name' => "file.{$extension}",
            'mime_type' => $mime,
            'extension' => $extension,
            'file_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'title' => 'KSR 급여 서식',
            'received_at' => now(),
            'ai_status' => 'ready',
        ]);
    }

    public function test_the_hub_preview_serves_excel_as_html_with_a_script_proof_sandbox(): void
    {
        $document = $this->hubDocument($this->xlsxBytes(), 'xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $admin = User::factory()->create(['access_role' => 'super_admin', 'account_status' => 'active']);

        $response = $this->actingAs($admin)
            ->get(route('document-intelligence.preview', $document));

        $response->assertOk();
        $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
        // 업로드된 내용은 남이 만든 것이다 — 스크립트로 살아나면 안 된다.
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
        // 같은 사이트 안의 뷰어(iframe)가 품을 수 있어야 한다 — deny 면 뷰어가 회색으로 깨진다.
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertStringContainsString('김반장', $response->getContent());
    }

    public function test_a_broken_office_file_still_falls_back_to_the_download_notice(): void
    {
        $document = $this->hubDocument('PK broken', 'xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $admin = User::factory()->create(['access_role' => 'super_admin', 'account_status' => 'active']);

        $this->actingAs($admin)
            ->get(route('document-intelligence.preview', $document))
            ->assertStatus(415);
    }
}
