<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\IntelligentDocument;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** 원청 계약 기성표 모양의 작은 엑셀 — 계약서 올리기와 RFI 시험이 같이 쓴다. */
trait ContractWorkbookFixture
{
    private Company $company;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->company = Company::create(['code' => 'C1', 'name' => 'ABC ENG', 'status' => 'active']);
        $this->site = Site::create(['code' => 'K1', 'name' => 'Kitchen', 'country' => 'US', 'timezone' => 'America/New_York',
            'status' => 'active', 'company_id' => $this->company->id]);
        $this->actingAs(User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active']));
    }

    /** 원청 기성표와 같은 모양: 요약표가 본문의 섹션 제목·소계를 수식으로 가리킨다. */
    private function workbook(array $override = []): IntelligentDocument
    {
        $book = new Spreadsheet;
        $cover = $book->getActiveSheet()->setTitle('1_PROGRESS PAYMENT');
        $cover->setCellValue('B12', ' PROJECT ')->setCellValue('C12', 'Test Project');
        $cover->setCellValue('B14', 'SCOPE OF WORKS ')->setCellValue('C14', 'Finish PKG-1');

        $s = $book->createSheet()->setTitle('3_CONTINUATION SHEET');
        $cells = $override + [
            'B9' => 'ITEM  / NO.', 'C9' => 'DESCRIPTION OF WORK', 'D9' => 'Specification', 'E9' => 'Unit', 'F9' => 'CONTRACT VALUE',
            'F11' => 'Quantity', 'G11' => 'Material', 'H11' => 'Labor', 'J11' => 'Unit Price', 'K11' => 'Amount',
            'B12' => 1, 'C12' => '건축공사', 'E12' => 'L/S', 'F12' => 1, 'K12' => '=TRUNC(SUM(K13:K14),2)',
            'C13' => '=C21', 'E13' => 'L/S', 'F13' => 1, 'K13' => '=K26',
            'C14' => '=C27', 'E14' => 'L/S', 'F14' => 1, 'K14' => '=K30',
            'C16' => 'Round Off', 'K16' => '=-MOD(K12,1000)',
            'C17' => 'TOTAL', 'K17' => '=K12+K16',
            'B20' => 0, 'C20' => '건축공사',
            'C21' => '3) DRYWALL',
            'C22' => 'Dry Wall', 'D22' => '6in stud', 'E22' => 'LF2', 'F22' => 100, 'G22' => 2.5, 'H22' => 7.5,
            'C23' => 'Paint group',
            'C24' => 'Paint', 'E24' => 'LF2', 'F24' => 200, 'G24' => 0, 'H24' => 3,
            'C26' => '[ Sub Total ]', 'K26' => '=SUM(K22:K25)',
            'C27' => 'M1-2. Duct Work',
            'C28' => 'Duct', 'E28' => 'M2', 'F28' => 10, 'G28' => 0, 'H28' => 150.55,
            'C30' => '[ Sub Total ]', 'K30' => '=SUM(K28:K29)',
        ];
        for ($row = 21; $row <= 30; $row++) {
            $cells['B'.$row] ??= '=B'.($row - 1).'+1';
            if (isset($cells['E'.$row]) && ! isset($cells['K'.$row])) {
                $cells['J'.$row] = '=I'.$row.'+H'.$row.'+G'.$row;
                $cells['K'.$row] = '=J'.$row.'*F'.$row;
            }
        }
        foreach ($cells as $cell => $value) {
            $s->setCellValue($cell, $value);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'wb');
        (new Xlsx($book))->save($tmp);
        $bytes = file_get_contents($tmp);
        unlink($tmp);
        $name = 'contract-'.Str::random(6).'.xlsx';
        Storage::disk('local')->put('docs/'.$name, $bytes);

        return IntelligentDocument::create([
            'uuid' => (string) Str::uuid(), 'source' => 'dropzone', 'disk' => 'local', 'file_path' => 'docs/'.$name,
            'original_file_name' => $name, 'stored_file_name' => $name, 'extension' => 'xlsx', 'file_size' => strlen($bytes),
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'sha256' => hash('sha256', $bytes), 'title' => $name, 'received_at' => now(), 'ai_status' => 'ready',
            'site_id' => $this->site->id, 'company_id' => $this->company->id, 'access_level' => 'shared',
        ]);
    }
}
