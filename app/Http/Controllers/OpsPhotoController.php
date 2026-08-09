<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 상황실 사진 업로드 — 한 요청에 한 장씩 받는다.
 *
 * 왜 나눠 받나: 예전에는 사진을 전부 base64 로 바꿔 판독 요청 본문에 실어 보냈다. 6장이면
 * 본문이 수 MB 가 되고, 업로드가 끝나기도 전에 게이트웨이가 요청을 끊어 504 가 났다.
 * 한 장씩 따로 올리면 요청 하나하나가 작아 크기 제한이 사실상 사라지고, 진행률도 보여줄 수 있다.
 *
 * 원본은 줄이지 않고 그대로 보관한다. 줄이는 건 AI 에 넘기기 직전(ImageDownscale)에만 한다.
 */
class OpsPhotoController extends Controller
{
    /** 업로드 한 장의 최대 크기(KB). 요즘 폰 사진(8~15MB)이 넉넉히 들어간다. */
    private const MAX_KB = 65536;   // 64MB

    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

    public function store(Request $request): JsonResponse
    {
        // post_max_size 를 넘기면 PHP 가 본문을 통째로 버려 요청이 빈 채로 도착한다.
        // 그대로 두면 "사진을 선택하세요" 같은 엉뚱한 오류가 나므로 실제 원인을 알려준다.
        if ($request->file('photo') === null && (int) $request->server('CONTENT_LENGTH', 0) > 0 && $request->all() === []) {
            return response()->json([
                'success' => false,
                'error' => '사진이 서버 업로드 한도를 넘었습니다. 관리자에게 문의하세요(post_max_size).',
            ], 413);
        }

        $request->validate([
            'photo' => ['required', 'file', 'max:'.self::MAX_KB],
        ], [
            'photo.max' => '사진 한 장은 64MB 까지 올릴 수 있습니다.',
        ]);

        $file = $request->file('photo');
        $mime = strtolower((string) $file->getMimeType());
        $mime = $mime === 'image/jpg' ? 'image/jpeg' : $mime;

        if (! in_array($mime, self::ALLOWED, true)) {
            return response()->json(['success' => false, 'error' => '이미지 파일만 올릴 수 있습니다.'], 422);
        }

        // 경로는 서버가 만든다 — 클라이언트가 임의 경로를 되돌려주지 못하게 하기 위해서다.
        $token = (string) Str::uuid();
        $path = self::pathFor($request->user()?->id, $token);

        Storage::disk(self::disk())->put($path, file_get_contents($file->getRealPath()), 'private');

        return response()->json([
            'success' => true,
            'token' => $token,
            'bytes' => (int) $file->getSize(),
            'mime' => $mime,
        ]);
    }

    public static function disk(): string
    {
        return (string) config('filesystems.documents_disk', 'public');
    }

    /** 업로더 id 를 경로에 박아, 남이 올린 사진 토큰을 넘겨도 열리지 않게 한다. */
    public static function pathFor(?int $userId, string $token): string
    {
        return 'ops-intake/'.($userId ?: 'anon').'/'.$token.'.bin';
    }

    /**
     * 클라이언트가 돌려준 토큰 목록을 실제 경로로 바꾼다. 형식이 어긋나거나 없는 파일은 버린다.
     *
     * @param  array<int, mixed>  $tokens
     * @return array<int, string>
     */
    public static function resolve(array $tokens, ?int $userId): array
    {
        $disk = Storage::disk(self::disk());
        $out = [];

        foreach ($tokens as $t) {
            $t = is_string($t) ? trim($t) : '';
            if (! Str::isUuid($t)) {
                continue;
            }
            $path = self::pathFor($userId, $t);
            if ($disk->exists($path)) {
                $out[] = $path;
            }
        }

        return $out;
    }
}
