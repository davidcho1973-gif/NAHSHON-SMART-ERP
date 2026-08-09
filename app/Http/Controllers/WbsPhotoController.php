<?php

namespace App\Http\Controllers;

use App\Models\WbsItem;
use App\Models\WbsPhoto;
use App\Support\ImageDownscale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 공정별 현장 사진 — 날짜별 업로드·열람.
 *
 * 원본은 저장하지 않는다. 폰 사진은 한 장에 3~8MB 라 그대로 쌓으면 현장 한 곳만으로도
 * 수십 GB 가 된다. 올라온 즉시 장변 1,600px JPEG 로 줄여 저장하고(화면·기록용으로 충분),
 * 목록용 썸네일(400px)을 따로 굽는다. 목록에서 썸네일 대신 본문 사진을 내려 주면
 * 사진 20장짜리 공정을 열 때마다 수 MB 를 받게 된다.
 *
 * 사진은 공개 URL 로 서빙하지 않는다 — 현장 사진에는 도면·인원·주소가 함께 찍힌다.
 * 비공개 디스크(wbs_photos_disk 설정, 기본 local / s3 있으면 s3)에 두고
 * 로그인 + 현장 권한을 확인한 뒤에만 이 컨트롤러가 스트리밍한다.
 */
class WbsPhotoController extends Controller
{
    /** 남의 사진을 지우거나 설명을 고칠 수 있는 역할. 본인 사진은 누구나 관리할 수 있다. */
    private const MANAGE_ROLES = ['super_admin', 'admin', 'site_manager'];

    /** 현장 제한 없이 전체를 보는 역할. */
    private const GLOBAL_ROLES = ['super_admin', 'admin'];

    /**
     * 목록 — 날짜별로 묶어서, 최근 날짜부터.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['wbs' => 'required|string|max:120']);

        $item = $this->findSubtask($request, (string) $request->query('wbs'));

        $user = $request->user();
        $canManageAll = in_array((string) ($user->access_role ?? ''), self::MANAGE_ROLES, true);

        $groups = WbsPhoto::query()
            ->where('wbs_code', $item->wbs_code)
            ->orderByDesc('photo_date')->orderByDesc('id')
            ->with('uploadedBy:id,name')
            ->get()
            ->groupBy(fn (WbsPhoto $p) => $p->photo_date->toDateString())
            ->map(fn ($photos, string $date): array => [
                'date' => $date,
                'photos' => $photos->map(fn (WbsPhoto $p): array => [
                    'id' => $p->id,
                    'caption' => (string) ($p->caption ?? ''),
                    'thumbUrl' => "/wbs-api/photos/{$p->id}/thumb",
                    'fileUrl' => "/wbs-api/photos/{$p->id}/file",
                    'bytes' => (int) $p->bytes,
                    'originalBytes' => (int) $p->original_bytes,
                    'uploadedBy' => $p->uploadedBy?->name,
                    'canEdit' => $canManageAll || (int) $p->uploaded_by_id === (int) $user->id,
                ])->values()->all(),
            ])
            ->values()->all();

        return response()->json(['success' => true, 'dates' => $groups]);
    }

    /**
     * 업로드 — 받자마자 줄여서 저장한다. 원본은 어디에도 남기지 않는다.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'wbs' => 'required|string|max:120',
            // 폰 원본을 그대로 받아야 하므로 한도는 넉넉하게. 어차피 줄여서 저장한다.
            'photo' => 'required|image|max:20480', // 20MB
            'photo_date' => 'required|date',
            'caption' => 'nullable|string|max:2000',
        ]);

        $item = $this->findSubtask($request, (string) $request->input('wbs'));

        $file = $request->file('photo');
        $bytes = (string) file_get_contents($file->getRealPath());
        $mime = (string) ($file->getMimeType() ?: 'image/jpeg');

        $main = ImageDownscale::shrink($bytes, $mime);
        $thumb = ImageDownscale::shrink($bytes, $mime, 400, 70);

        // Laravel Cloud 의 로컬 디스크는 배포마다 초기화된다 — s3 가 붙어 있으면 그쪽으로.
        $disk = (string) config('filesystems.wbs_photos_disk', 'local');

        $dir = 'wbs-photos/'.Str::slug($item->wbs_code, '_');
        $name = now()->format('Ymd_His').'_'.Str::random(6);
        $ext = $main['resized'] ? 'jpg' : ($file->guessExtension() ?: 'jpg');

        $path = "{$dir}/{$name}.{$ext}";
        Storage::disk($disk)->put($path, $main['data']);

        $thumbPath = null;
        if ($thumb['resized']) {
            $thumbPath = "{$dir}/{$name}_thumb.jpg";
            Storage::disk($disk)->put($thumbPath, $thumb['data']);
        }

        $photo = WbsPhoto::query()->create([
            'wbs_code' => $item->wbs_code,
            'project_code' => $item->project_code,
            'site_id' => $item->site_id,
            'photo_date' => (string) $request->input('photo_date'),
            'caption' => trim((string) $request->input('caption', '')) ?: null,
            'disk' => $disk,
            'path' => $path,
            'thumb_path' => $thumbPath,
            'mime' => $main['resized'] ? 'image/jpeg' : $mime,
            'width' => $main['width'] ?: null,
            'height' => $main['height'] ?: null,
            'bytes' => strlen($main['data']),
            'original_bytes' => strlen($bytes),
            'original_name' => $file->getClientOriginalName(),
            'uploaded_by_id' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'id' => $photo->id,
            'saved' => strlen($main['data']),
            'original' => strlen($bytes),
        ]);
    }

    /**
     * 사진 설명(캡션) 수정 — 본인 사진이거나 관리 역할.
     */
    public function caption(Request $request, WbsPhoto $photo): JsonResponse
    {
        $request->validate(['caption' => 'nullable|string|max:2000']);
        $this->authorizeOwn($request, $photo);

        $photo->update(['caption' => trim((string) $request->input('caption', '')) ?: null]);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, WbsPhoto $photo): JsonResponse
    {
        $this->authorizeOwn($request, $photo);

        foreach ([$photo->path, $photo->thumb_path] as $p) {
            if ($p) {
                Storage::disk($photo->disk)->delete($p);
            }
        }
        $photo->delete();

        return response()->json(['success' => true]);
    }

    public function file(Request $request, WbsPhoto $photo)
    {
        $this->authorizeSiteRead($request, $photo);
        abort_unless(Storage::disk($photo->disk)->exists($photo->path), 404);

        return Storage::disk($photo->disk)->response($photo->path, null, [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function thumb(Request $request, WbsPhoto $photo)
    {
        $this->authorizeSiteRead($request, $photo);
        $path = $photo->thumb_path ?: $photo->path;
        abort_unless(Storage::disk($photo->disk)->exists($path), 404);

        return Storage::disk($photo->disk)->response($path, null, [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    // ── 권한 ────────────────────────────────────────────────────────────

    /**
     * 사진이 붙을 공정을 찾고, 현장 제한 사용자의 접근을 막는다.
     */
    private function findSubtask(Request $request, string $wbsCode): WbsItem
    {
        $item = WbsItem::query()
            ->where('wbs_code', $wbsCode)
            ->where('level', WbsItem::LEVEL_SUBTASK)
            ->first();
        abort_unless($item !== null, 404, "공정을 찾을 수 없습니다: {$wbsCode}");

        $user = $request->user();
        if (in_array((string) ($user->access_role ?? ''), self::GLOBAL_ROLES, true)) {
            return $item;
        }
        // 공정에 현장이 지정돼 있으면 그 현장 사용자만.
        if ($item->site_id !== null) {
            $allowed = $user->allowed_site_id ?? null;
            abort_unless($allowed && (int) $allowed === (int) $item->site_id, 403, '이 현장의 사진에 접근할 권한이 없습니다.');
        }

        return $item;
    }

    private function authorizeSiteRead(Request $request, WbsPhoto $photo): void
    {
        $user = $request->user();
        if (in_array((string) ($user->access_role ?? ''), self::GLOBAL_ROLES, true)) {
            return;
        }
        if ($photo->site_id !== null) {
            $allowed = $user->allowed_site_id ?? null;
            abort_unless($allowed && (int) $allowed === (int) $photo->site_id, 403, '이 현장의 사진을 볼 권한이 없습니다.');
        }
    }

    private function authorizeOwn(Request $request, WbsPhoto $photo): void
    {
        $this->authorizeSiteRead($request, $photo);

        $user = $request->user();
        $manager = in_array((string) ($user->access_role ?? ''), self::MANAGE_ROLES, true);
        abort_unless($manager || (int) $photo->uploaded_by_id === (int) $user->id, 403, '본인이 올린 사진만 수정/삭제할 수 있습니다.');
    }
}
