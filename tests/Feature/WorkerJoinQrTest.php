<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Support\QrSvg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 작업자 QR 셀프 등록 — 현장 QR 포스터가 외부 서비스 없이 로컬 SVG QR 로 렌더된다.
 */
class WorkerJoinQrTest extends TestCase
{
    use RefreshDatabase;

    public function test_qr_svg_helper_generates_scannable_svg(): void
    {
        $svg = QrSvg::svg('https://smart-erp.example/member/site/1/apply', 320);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('<path', $svg);      // QR 모듈이 그려짐

        $uri = QrSvg::dataUri('https://smart-erp.example/member/site/1/apply');
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $uri);
    }

    public function test_site_apply_qr_poster_embeds_local_qr_no_external_call(): void
    {
        $site = Site::create(['code' => 'AZ-01', 'name' => 'Arizona Site', 'timezone' => 'America/Phoenix', 'status' => 'active']);

        $res = $this->get('/member/site/'.$site->id.'/apply/qr');

        $res->assertStatus(200);
        $res->assertSee('data:image/svg+xml;base64,');   // 로컬 QR
        $res->assertDontSee('api.qrserver.com');          // 외부 의존 제거
        $res->assertSee('/member/site/'.$site->id.'/apply', false); // 스캔 시 열리는 지원 폼
    }
}
