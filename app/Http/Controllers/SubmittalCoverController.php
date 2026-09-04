<?php

namespace App\Http\Controllers;

use App\Support\Org;
use Illuminate\View\View;

/**
 * SUBMITTAL 커버 — 제출물(Vendor Print·장비 승인 등)을 원청·EOR 에 보낼 때 맨 앞에 붙는 표지.
 *
 * 현장에서 쓰던 엑셀 표지(703K 기계설비 팬 제출물 등)를 그대로 옮긴 것이다. 받는 쪽(EOR)은
 * 이 배치를 보고 어느 칸이 SUB No. 이고 어디에 Action 을 찍는지 안다 — 칸이 옮겨지면
 * "다른 서류" 가 되므로 라벨·순서·번호(1/2/3/5)까지 원본을 따른다.
 *
 * 지금은 빈 양식만 낸다. 제출물 대장(submittals)의 행을 얹어 채워 주는 것은 다음 단계다.
 */
class SubmittalCoverController extends Controller
{
    /**
     * 아무것도 안 채운 빈 표지.
     *
     * 화면에서 칸을 채워 바로 인쇄하거나, 그대로 뽑아 손으로 쓴다. 데이터가 하나도 없으므로
     * 역할을 따로 제한하지 않는다 — 회사 이름·로고만 조직 설정에서 가져온다.
     */
    public function blank(): View
    {
        return view('submittal.cover', [
            'blank' => true,
            // 발행처(Issued by) 는 우리 회사다 — 원본 양식에서 'HEA' 가 고정으로 박혀 있던 자리.
            'issuer' => Org::shortName(),
            'hasLogo' => Org::hasLogo(),
            'values' => [],
            // 원본 표지의 장비표는 헤더 1행 + 9행이다. 화면에서는 행을 더 붙일 수 있다.
            'equipmentRows' => 9,
        ]);
    }
}
