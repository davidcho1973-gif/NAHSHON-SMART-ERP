<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 휴대폰에서도 관리 화면에 들어갈 수 있는가.
 *
 * 데스크톱 사이드바와 휴대폰 "더보기" 타일은 서로 다른 목록이다. 화면을 새로 만들 때
 * 사이드바에만 넣으면 휴대폰에서는 그 화면에 들어갈 길이 아예 없다 — 없어진 것도
 * 아니고 고장난 것도 아니라, 밖에서는 원인을 짐작할 수 없다.
 * (관리자 패널을 ERP 안으로 옮기면서 아홉 화면이 실제로 그렇게 빠져 있었다.)
 */
class MobileNavAdminScreensTest extends TestCase
{
    use RefreshDatabase;

    /** 데스크톱 사이드바의 "DASOL PRISM 통합관리" 화면들. */
    private const ADMIN_VIEWS = [
        'access-control', 'attendance-logs', 'applicant-admin', 'employee-admin',
        'item-master', 'contract-admin', 'site-admin', 'pay-profiles', 'messenger-admin',
    ];

    private function html(): string
    {
        $admin = User::factory()->create([
            'access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active',
        ]);

        return $this->actingAs($admin)->get('/')->assertOk()->getContent();
    }

    public function test_every_admin_screen_has_a_mobile_tile(): void
    {
        $html = $this->html();

        foreach (self::ADMIN_VIEWS as $view) {
            $this->assertStringContainsString(
                'data-mobile-view="'.$view.'"',
                $html,
                "[{$view}] 이 휴대폰 더보기 목록에 없습니다 — 휴대폰에서는 들어갈 길이 없습니다."
            );
        }
    }

    public function test_the_sidebar_and_the_mobile_tiles_point_at_the_same_screens(): void
    {
        $html = $this->html();

        foreach (self::ADMIN_VIEWS as $view) {
            // 사이드바에도 있고
            $this->assertStringContainsString('data-view="'.$view.'"', $html);
            // 실제로 그릴 수 있는 화면이어야 한다(타일만 있고 화면이 없으면 눌러도 아무 일이 없다).
            $this->assertStringContainsString("'".$view."': {", $html, "[{$view}] 화면이 등록되어 있지 않습니다.");
        }
    }
}
