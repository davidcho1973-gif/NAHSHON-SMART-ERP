<?php

namespace App\Http\Controllers;

use App\Models\OpsIntakeBatch;
use App\Support\AccessPolicy;
use App\Support\ImageDownscale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

    /**
     * 상황실에 올라온 사진을 보여 준다 — 원본 또는 목록용 작은 판.
     *
     * 사진은 올릴 수만 있고 볼 길이 없었다. 반장이 현장 사진을 찍어 보내도 소장 화면에는
     * «사진 3장» 이라는 글자만 떴다. 상황실은 «현장을 한눈에 보는» 자리라 사진이 그 자체로
     * 보고다 — 글보다 먼저 눈에 들어와야 한다.
     *
     * 파일은 «.bin» 으로 저장돼 있어 확장자가 없다. 형식은 바이트를 보고 가린다.
     * 목록에는 줄인 판(?s=t)을 준다 — 원본 10MB 를 카드마다 내려받게 하면 화면이 안 열린다.
     */
    public function show(Request $request, OpsIntakeBatch $batch, int $index): Response
    {
        $user = $request->user();
        $site = $batch->site;
        $mine = $user && (int) $batch->created_by_id === (int) $user->id;
        $siteLocked = $user && ($user->access_scope ?? null) === 'site'
            && (int) ($user->allowed_site_id ?: 0) !== (int) $batch->site_id;
        abort_unless($mine || (! $siteLocked && AccessPolicy::canSeeCompany($user, $site?->company_id)), 403);

        $paths = is_array($batch->photo_paths) ? array_values($batch->photo_paths) : [];
        abort_unless(isset($paths[$index]), 404);

        $disk = Storage::disk((string) ($batch->photo_disk ?: self::disk()));
        abort_unless($disk->exists($paths[$index]), 404);

        $bytes = (string) $disk->get($paths[$index]);
        $info = @getimagesizefromstring($bytes);
        // GD 가 못 읽는 형식(HEIC 등)도 이미지로 내보낸다 — octet-stream 으로 주면 브라우저가
        // 사진 대신 «내려받기» 를 띄운다.
        $mime = is_array($info) && isset($info['mime'])
            ? (string) $info['mime']
            : ((string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream');

        if ($request->query('s') === 't') {
            $shrunk = ImageDownscale::shrink($bytes, $mime, 720, 74);
            $bytes = $shrunk['data'];
            $mime = $shrunk['mime'];
        }

        // 올린 사진은 바뀌지 않는다 — 한 번 받으면 하루는 다시 안 받아도 된다.
        return response($bytes, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=86400',
            'Content-Disposition' => 'inline',
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
