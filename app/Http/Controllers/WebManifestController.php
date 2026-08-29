<?php

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\Http\JsonResponse;
use App\Support\Org;

/**
 * 휴대폰이 "이건 앱이다" 라고 판단하는 근거 파일.
 *
 * 홈 화면에 추가하려면 브라우저가 이 파일을 읽어야 한다. 없으면 안드로이드는 설치를
 * 제안하지 않고, 아이폰은 추가는 되지만 주소창이 그대로 남아 웹사이트처럼 보인다.
 *
 * 왜 정적 파일이 아니라 라우트인가 — 현장마다 주소가 다르기 때문이다(/gate/3, /gate/7).
 * start_url 이 현장까지 가리켜야 아이콘을 눌렀을 때 그 현장 출퇴근 화면이 바로 열린다.
 * 현장이 둘인 사람은 아이콘도 둘이 생기고, 각각 이름에 현장 코드가 찍힌다.
 */
class WebManifestController extends Controller
{
    /** 게이트 출퇴근 — 로그인 없이 쓰는 화면이라 매니페스트도 공개다. */
    public function gate(Site $site): JsonResponse
    {
        $url = route('gate.show', ['site' => $site]);

        return $this->manifest(
            // id 가 고정되어야 "같은 앱을 다시 추가"로 인식된다. 주소가 바뀌어도 아이콘이
            // 둘로 늘어나지 않는다.
            id: "gate-{$site->getKey()}",
            name: trim("{$site->code} 출퇴근"),
            shortName: (string) ($site->code ?: '출퇴근'),
            start: $url,
            scope: $url,
            theme: '#FEE500',
            background: '#FEE500',
            icon: 'attendance',
        );
    }

    /** 로그인해서 쓰는 작업자 앱(반장·계정이 있는 작업자). */
    public function worker(): JsonResponse
    {
        return $this->manifest(
            id: 'worker-app',
            name: '내 출퇴근 · '.Org::name(),
            shortName: '내 출퇴근',
            start: route('attendance-app.index'),
            scope: route('attendance-app.index'),
            // 앱을 여는 첫 0.5초(스플래시)도 화면과 같은 색이어야 한다 — 어두운 판이
            // 번쩍였다가 노란 화면이 뜨면 잘못 눌린 것처럼 보인다.
            theme: '#FEE500',
            background: '#FFFFFF',
            icon: 'attendance',
        );
    }

    /** 영수증 앱 — 직원 폰의 경비 접수 전용. */
    public function expense(): JsonResponse
    {
        return $this->manifest(
            id: 'expense-app',
            name: '영수증 · '.Org::name(),
            shortName: '영수증',
            start: route('expense-app.index'),
            scope: route('expense-app.index'),
            theme: '#FEE500',
            background: '#FFFFFF',
            icon: 'upload',
        );
    }

    /** 관리자 ERP 본체. 정적 파일이었는데 고객사 이름이 박혀 있어 라우트로 옮겼다. */
    public function erp(): JsonResponse
    {
        return $this->manifest(
            id: 'erp',
            name: Org::name().' SMART ERP',
            shortName: Org::shortName(),
            start: url('/'),
            scope: url('/'),
        );
    }

    private function manifest(
        string $id,
        string $name,
        string $shortName,
        string $start,
        string $scope,
        string $theme = '#17160F',
        string $background = '#17160F',
        string $icon = 'worker',
    ): JsonResponse {
        return response()->json([
            'id' => $id,
            'name' => $name,
            // 홈 화면 아이콘 밑에 찍히는 이름. 길면 잘린다 — 12자 안쪽으로.
            'short_name' => mb_substr($shortName, 0, 12),
            'start_url' => $start,
            'scope' => $scope,
            // 주소창을 숨긴다. 이게 앱처럼 보이게 만드는 거의 전부다.
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => $background,
            'theme_color' => $theme,
            'lang' => 'ko',
            'dir' => 'ltr',
            // 앱마다 다른 아이콘을 쓴다 — 예전에는 넷이 같은 그림이라 홈 화면에 두 개를
            // 깔면 어느 것이 출퇴근인지 알 수 없었다.
            'icons' => [
                ['src' => asset("images/{$icon}-icon-192.png"), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset("images/{$icon}-icon-512.png"), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                // maskable 을 따로 두는 이유 — 안드로이드가 아이콘을 원·사각·눈물방울로
                // 깎는다. 'any' 만 주면 깎이면서 시계가 잘린다.
                ['src' => asset("images/{$icon}-icon-maskable-512.png"), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, [
            'Content-Type' => 'application/manifest+json',
            // 현장 이름이 바뀌면 하루 안에 따라오면 된다. 매번 받아오게 두면 느리다.
            'Cache-Control' => 'public, max-age=86400',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
