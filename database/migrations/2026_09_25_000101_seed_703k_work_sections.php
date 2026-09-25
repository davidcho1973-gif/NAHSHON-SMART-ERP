<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 703K 주방 — 계약서의 공정 24개와, 공정마다 쓸 도면(사장 지시 2026-09-25).
 *
 * 공정은 원청 계약 기성표(Kitchen Progress Payment, 3_CONTINUATION SHEET)의 섹션이고,
 * 금액은 그 표의 섹션 합계다. 도면 번호는 도면 세트 표지(703K-A00-02)의 SHEET INDEX
 * 55장에서 골랐다. 고른 기준은 «그 공정의 일을 그 위에 그리고 적을 수 있는 장» 이다 —
 * 평면이 먼저고, 일람표·상세는 참고로 붙인다.
 *
 * 선택은 도면 번호로 저장한다. 도면 파일이 아직 문서함에 없어도 선택은 살아 있고,
 * 파일이 올라와 그 번호의 장이 읽히면 그때 화면에 그림이 붙는다. 화면에서 언제든 고친다.
 *
 * 다시 돌아도 안전하다: 있는 공정은 금액·이름만 맞추고, 사람이 고친 선택은 지우지 않는다.
 */
return new class extends Migration
{
    /** @var array<int, array{0: string, 1: string, 2: string, 3: float, 4: array<int, string>}> */
    private const SECTIONS = [
        // [division, code, name, contract amount, sheets]
        ['건축공사', 'A1', '1) TEMPORARY', 37554.00, ['703K-A00-03', 'SITE-C205']],
        ['건축공사', 'A2', '2) DOOR, WINDOW', 25683.44, ['703K-A01-01', '703K-A07-01']],
        ['건축공사', 'A3', '3) DRYWALL', 94548.52, ['703K-A01-01', '703K-A07-01', '703K-A07-02']],
        ['건축공사', 'A4', '4) WALL FINISH', 83277.19, ['703K-A07-02', '703K-A07-03', '703K-A01-01']],
        ['건축공사', 'A5', '5) FLOOR FINISH', 67623.82, ['703K-A01-01', '703K-A07-03', '703K-S01-01']],
        ['건축공사', 'A6', '6) CEILING FINISH', 45294.00, ['703K-A06-01', '703K-A07-03']],
        ['건축공사', 'A7', '7) MISCELLANEOUS', 193390.14, ['703K-A01-02', '703K-S01-02', '703K-A01-01', '703K-P602']],
        ['건축공사', 'A8', '8) TOILET WORKS', 31845.22, ['703K-A01-01', '703K-A07-02', '703K-P403', '703K-P404']],
        ['건축공사', 'A9', '9) CIVIL WORKS', 165781.77, ['SITE-C205', 'SITE-C351', 'SITE-C505', 'SITE-C535K']],
        ['설비공사', 'M0', 'M0 General', 44319.55, ['703K-M001', '703K-P001']],
        ['설비공사', 'M1-1', 'M1-1. Equipment', 31745.98, ['703K-M201', '703K-M203', '703K-M002']],
        ['설비공사', 'M1-2', 'M1-2. Duct Work', 121114.79, ['703K-M201', '703K-M401', '703K-M601']],
        ['설비공사', 'M1-3-1', 'M1-3-1. Domestic Water', 31293.99, ['703K-P301', '703K-P402']],
        ['설비공사', 'M1-3-2', 'M1-3-2. Plumbing', 8248.54, ['703K-P201', '703K-P301', '703K-P403', '703K-P404']],
        ['설비공사', 'M1-3-3', 'M1-3-3. A/G NG Piping Work', 7303.91, ['703K-P401', '703K-P405', '703K-P701']],
        ['설비공사', 'M1-4', 'M1-4. Fire Protection Work', 0.00, ['703K-F201']],
        ['설비공사', 'M1-5', 'M1-5. Mechanical Demolition Work', 26262.95, ['703K-M101']],
        ['설비공사', 'M1-6', 'M1-6. Civil Work', 300981.67, ['SITE-C505', 'SITE-C535K', 'SITE-C943', '703K-P602']],
        ['전기공사', 'E01', '01. Power Distribution Work', 146204.14, ['703K-E301', '703K-E701', '703K-E840']],
        ['전기공사', 'E02', '02. Mechanical Power Work', 140971.86, ['703K-E321', '703K-E322']],
        ['전기공사', 'E03', '03. Lighting Work', 63568.46, ['703K-E201']],
        ['전기공사', 'E04', '04. Receptacle Work', 48825.62, ['703K-E301']],
        ['전기공사', 'E05', '05. Incidental Expense', 73980.80, ['703K-E001', '000-E013', '703K-E601']],
        ['전기공사', 'E06', '06. Fire Alarm System', 0.00, ['703K-E401']],
    ];

    public function up(): void
    {
        $site = DB::table('sites')->whereRaw('upper(code) = ?', ['703K'])->first(['id', 'company_id']);
        if ($site === null) {
            return;   // 703K 가 없는 배포(다른 고객) — 할 일이 없다.
        }

        $now = Carbon::now();
        foreach (self::SECTIONS as $i => [$division, $code, $name, $amount, $sheets]) {
            $existing = DB::table('work_sections')->where('site_id', $site->id)->where('code', $code)->first(['id']);
            $values = [
                'company_id' => $site->company_id, 'division' => $division, 'name' => $name,
                'contract_amount' => $amount, 'sort_order' => $i + 1,
                'source' => '원청 계약 기성표 continuation sheet (2026-09)', 'updated_at' => $now,
            ];

            if ($existing) {
                DB::table('work_sections')->where('id', $existing->id)->update($values);

                continue;   // 이미 있는 공정의 도면 선택은 사람이 고쳤을 수 있다 — 건드리지 않는다.
            }

            $id = DB::table('work_sections')->insertGetId($values + [
                'site_id' => $site->id, 'code' => $code, 'created_at' => $now,
            ]);
            foreach ($sheets as $j => $sheetNo) {
                DB::table('work_section_sheets')->insert([
                    'work_section_id' => $id, 'sheet_no' => $sheetNo, 'sort_order' => $j + 1,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // 공정 목록은 계약의 사실이다 — 되돌릴 때도 지우지 않는다(표를 지우는 것은 앞 마이그레이션의 몫).
    }
};
