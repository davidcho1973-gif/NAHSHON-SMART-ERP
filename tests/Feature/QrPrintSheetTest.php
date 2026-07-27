<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use App\Support\QrPosters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 현장 QR 모아 인쇄 — 포스터 4종을 한 화면에서 골라 A4 한 장씩 출력한다.
 */
class QrPrintSheetTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Site
    {
        return Site::create([
            'code' => 'AZ-01', 'name' => 'Arizona Site',
            'address' => '100 W Main St, Phoenix AZ',
            'timezone' => 'America/Phoenix', 'status' => 'active',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['access_role' => 'admin', 'account_status' => 'active']);
    }

    public function test_sheet_requires_login(): void
    {
        $site = $this->site();

        $this->get('/print/qr/'.$site->id)->assertRedirect('/login');
    }

    public function test_sheet_renders_every_poster(): void
    {
        $site = $this->site();

        $res = $this->actingAs($this->admin())->get('/print/qr/'.$site->id);

        $res->assertStatus(200);
        foreach (QrPosters::LABELS as $label) {
            $res->assertSee($label);
        }
        // 포스터마다 자체 생성한 QR 이미지가 들어간다(외부 서비스 호출 없음).
        $this->assertSame(count(QrPosters::ORDER), substr_count($res->getContent(), 'data:image/svg+xml;base64,'));
        $res->assertDontSee('api.qrserver.com');
    }

    public function test_sheet_shows_each_poster_target_url(): void
    {
        $site = $this->site();

        $res = $this->actingAs($this->admin())->get('/print/qr/'.$site->id);

        $res->assertSee('/gate/'.$site->id, false);
        $res->assertSee('/join/w/'.$site->id, false);
        $res->assertSee('/member/site/'.$site->id.'/apply', false);
        // 등록 QR 은 한 장뿐이라 고용 형태가 주소에 박히지 않는다.
        $res->assertDontSee('type=direct', false);
        $res->assertDontSee('type=indirect', false);
    }

    public function test_only_parameter_limits_posters(): void
    {
        $site = $this->site();

        $res = $this->actingAs($this->admin())->get('/print/qr/'.$site->id.'?only=gate,join');

        $res->assertStatus(200);
        $res->assertSee(QrPosters::LABELS[QrPosters::GATE]);
        $res->assertSee(QrPosters::LABELS[QrPosters::JOIN]);
        $this->assertSame(2, substr_count($res->getContent(), 'data:image/svg+xml;base64,'));
    }

    public function test_unknown_only_values_fall_back_to_all(): void
    {
        $site = $this->site();

        $res = $this->actingAs($this->admin())->get('/print/qr/'.$site->id.'?only=nonsense');

        $res->assertStatus(200);
        $this->assertSame(count(QrPosters::ORDER), substr_count($res->getContent(), 'data:image/svg+xml;base64,'));
    }

    public function test_poster_definitions_are_shared_with_single_posters(): void
    {
        $site = $this->site();

        // 같은 정의를 쓰므로 개별 포스터와 모아 인쇄의 문구·주소가 어긋날 수 없다.
        $gate = QrPosters::make($site, QrPosters::GATE);
        $this->get('/gate/'.$site->id.'/qr')->assertStatus(200)->assertSee($gate['url']);

        $join = QrPosters::make($site, QrPosters::JOIN);
        $this->get('/join/w/'.$site->id.'/qr')->assertStatus(200)->assertSee($join['url']);
        $this->assertNull($join['badge']);
    }
}
