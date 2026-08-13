<?php

namespace App\Http\Controllers;

use App\Services\Admin\OrgSettingService;
use App\Support\Org;
use App\Support\OrgLogo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Throwable;

/**
 * 고객사 로고 그림 — 올리고, 내보내고, 지운다.
 *
 * 내보내는 쪽(show)은 로그인 없이 열린다. 로그인 화면과 게이트 화면이 로고를
 * 쓰는데, 그 두 곳은 아직 로그인하기 전이기 때문이다. 로고는 회사가 명함과
 * 간판에 이미 붙여 둔 그림이라 숨길 것이 없다.
 *
 * 올리고 지우는 쪽은 최고 관리자만. 이 배포가 누구의 것인지를 바꾸는 일이다.
 */
class OrgLogoController extends Controller
{
    public function __construct(private readonly OrgSettingService $settings) {}

    public function show(): Response
    {
        $mime = Org::logoMime();
        $bytes = $mime ? Org::logoBytes() : null;

        if ($bytes === null || $mime === null) {
            // 로고가 없는 것은 정상이다(대부분의 배포가 그렇다). 화면은 이 자리에
            // 이름에서 뽑은 머리글자를 대신 넣는다.
            return response('', 404);
        }

        return response($bytes, 200, OrgLogo::safeHeaders($mime) + [
            // 주소에 로고 판(version)이 붙어 있다. 그림이 바뀌면 주소가 바뀌므로
            // 브라우저에 오래 맡겨 둬도 옛 그림이 남지 않는다.
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => '"'.Org::logoVersion().'"',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->settings->canManage()) {
            return response()->json(['success' => false, 'error' => '로고는 최고 관리자만 바꿀 수 있습니다.'], 403);
        }

        $file = $request->file('file');
        if (! $file || ! $file->isValid()) {
            return response()->json(['success' => false, 'error' => '파일을 받지 못했습니다. 다시 올려 주세요.'], 422);
        }

        try {
            $normalized = OrgLogo::normalize((string) file_get_contents($file->getRealPath()));
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (Throwable) {
            return response()->json(['success' => false, 'error' => '그림을 처리하지 못했습니다.'], 422);
        }

        Org::putLogo($normalized['data'], $normalized['mime']);

        return response()->json(['success' => true] + $this->settings->load());
    }

    public function destroy(): JsonResponse
    {
        if (! $this->settings->canManage()) {
            return response()->json(['success' => false, 'error' => '로고는 최고 관리자만 바꿀 수 있습니다.'], 403);
        }

        Org::removeLogo();

        return response()->json(['success' => true] + $this->settings->load());
    }
}
