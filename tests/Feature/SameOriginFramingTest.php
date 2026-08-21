<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ERP 는 자기 화면을 자기 안에 품는다 — SPA 가 AI 문서함을 iframe 으로 얹고,
 * 문서 뷰어가 미리보기를 iframe 으로 띄운다. 앱이 아무 선언을 안 하면 플랫폼이
 * X-Frame-Options: deny 를 붙여 <b>자기 자신조차</b> 액자에 못 넣는다 — 문서함
 * 메뉴가 회색 깨진 아이콘만 보여주던 원인이다. SAMEORIGIN 선언이 계속 나가는지
 * 여기서 지킨다(남의 사이트가 품는 것은 여전히 막힌다).
 */
class SameOriginFramingTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_responses_declare_sameorigin_framing(): void
    {
        $this->assertSame('SAMEORIGIN', $this->get('/login')->headers->get('X-Frame-Options'),
            'SAMEORIGIN 선언이 사라졌습니다 — 플랫폼 기본값(deny)이 붙으면 SPA 가 품은 문서함이 깨집니다.');
    }

    public function test_the_embedded_document_hub_is_allowed_to_be_framed(): void
    {
        $admin = User::factory()->create(['access_role' => 'super_admin', 'account_status' => 'active']);

        $response = $this->actingAs($admin)->get('/document-hub?embed=1');

        $response->assertOk();
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }
}
