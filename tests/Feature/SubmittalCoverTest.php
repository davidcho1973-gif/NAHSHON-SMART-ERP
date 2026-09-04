<?php

namespace Tests\Feature;

use App\Models\OrgSetting;
use App\Models\User;
use App\Support\Org;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * SUBMITTAL 커버 — 빈 양식이 정말 비어 있는가.
 *
 * 이 표지는 원청·EOR 에게 나가는 종이다. 원본 엑셀에는 지난 제출물(703K 팬)의 값,
 * 다른 패키지(DHU/MAU)의 숨김 시트, 열 때마다 날짜가 바뀌는 =TODAY() 가 들어 있었다.
 * 빈 양식에 그중 하나라도 남으면 다음 제출물이 남의 데이터를 달고 나간다 — 그래서 잠근다.
 */
class SubmittalCoverTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create([
            'access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);
    }

    public function test_the_blank_cover_keeps_the_layout_the_eor_knows(): void
    {
        $res = $this->actingAs($this->user())->get(route('submittal.cover.blank'))->assertOk();

        // 원본 표지의 라벨과 번호 그대로 — 4번이 없는 것까지 원본이다.
        foreach ([
            'SUBMITTAL', 'SUB No.', 'Project', 'Issued by', 'Issued to', 'Related to', 'Subject', 'Description',
            '1. Subcon PKG / Work Trade', '2. Equipment Included.', '3. Equipment Selection:', 'Maker :',
            '5. Estimated Lead Time', 'Attachment', 'Reviewer', 'Comment', 'Action', 'A : Complies',
            'Manager', 'Team Leader', 'P.M.', 'Signature',
        ] as $label) {
            $res->assertSee($label);
        }
        foreach (['Drawing', 'Schedule', 'Equipment', 'Data', 'Sample', 'Payment', 'P.O.'] as $type) {
            $res->assertSee($type);
        }
        // 장비표 — 원본에서 그림(EMF)으로 붙어 있던 표와 같은 일곱 열, 빈 아홉 행.
        foreach (['CFM', 'SERVICE', 'MODEL', 'MOTOR(HP)', 'V/ph/Hz', 'Remark'] as $head) {
            $res->assertSee($head);
        }
        $this->assertSame(9, substr_count($res->getContent(), 'class="eq"'));
    }

    public function test_the_blank_cover_carries_nothing_from_the_previous_submittal(): void
    {
        $res = $this->actingAs($this->user())->get(route('submittal.cover.blank'))->assertOk();

        foreach (['703K', 'Greenheck', 'Gresham Smith', 'SUB-ME-VP', 'EF-703', 'DHU-', 'MAU-'] as $leftover) {
            $res->assertDontSee($leftover);
        }
    }

    public function test_the_issuer_is_our_company_not_the_form_s_original_owner(): void
    {
        // 원본 엑셀에는 발행처가 'HEA' 로 박혀 있었다. 다음 고객은 자기 회사 이름을 봐야 한다.
        OrgSetting::query()->updateOrCreate(['key' => 'name'], ['value' => 'ABC 건설']);
        Org::forget();

        $this->actingAs($this->user())->get(route('submittal.cover.blank'))
            ->assertOk()
            ->assertSee('ABC 건설', false)
            ->assertDontSee('HEA');
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('submittal.cover.blank'))->assertRedirect('/login');
    }

    public function test_the_excel_template_is_blank_too(): void
    {
        // 화면과 같은 양식의 엑셀 — 지난 값, 다른 패키지 시트, =TODAY() 가 없어야 한다.
        $path = public_path('forms/SUBMITTAL_COVER_TEMPLATE.xlsx');
        $this->assertFileExists($path);

        $book = IOFactory::load($path);
        $this->assertSame(['COVER', 'TR-001'], $book->getSheetNames());

        $cover = $book->getSheetByName('COVER');
        $text = '';
        foreach ($cover->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $text .= ' '.$cell->getValue();
            }
        }

        foreach (['703K', 'Greenheck', 'Gresham', 'EF-703', 'SF-703', 'TODAY'] as $leftover) {
            $this->assertStringNotContainsString($leftover, $text);
        }
        foreach (['SUBMITTAL', 'SUB No.', '1. Subcon PKG / Work Trade', '5. Estimated Lead Time', 'MOTOR(HP)', 'A : Complies'] as $label) {
            $this->assertStringContainsString($label, $text);
        }
        // Date 칸 — 원본은 =TODAY() 라 여는 날마다 제출일이 바뀌었다. 빈 칸이어야 한다.
        $this->assertNull($cover->getCell('M4')->getValue());
    }
}
