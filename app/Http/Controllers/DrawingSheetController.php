<?php

namespace App\Http\Controllers;

use App\Jobs\ReadDrawingSheetJob;
use App\Models\DrawingSheet;
use App\Models\IntelligentDocument;
use App\Services\Drawings\DrawingSheetReader;
use App\Services\Drawings\SectionDrawingService;
use App\Support\UploadLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * 도면 쪽 읽기 — 브라우저가 PDF 를 한 쪽씩 그려 보내면 서버가 받아 읽는다.
 *
 * 왜 브라우저가 그리는가: 도면 PDF 는 20MB 가 넘고 사진 쪽 하나가 1억 3천만 화소다. 서버에서
 * 풀면 PHP 메모리를 뚫는다(PdfText 주석의 실사고). 브라우저의 PDF.js 는 이것을 원래 하는 도구이고,
 * 어차피 도면 위에 그리려면 브라우저가 그 쪽을 그려야 한다.
 *
 * 서버가 받는 것: 쪽의 크기, 브라우저가 뽑은 글자(글자가 들어 있는 쪽), 목록용 작은 그림,
 * 그리고 사진 쪽이면 네 조각 사진. 조각은 AI 가 읽고 나면 지운다.
 */
class DrawingSheetController extends Controller
{
    public function __construct(private readonly SectionDrawingService $service) {}

    /** 이 파일을 몇 쪽으로 읽을지 정한다 — 쪽마다 «아직» 줄을 만든다. */
    public function start(Request $request, int $document): JsonResponse
    {
        $doc = $this->document($request, $document);
        $count = max(1, min(400, (int) $request->input('page_count', 0)));

        for ($p = 1; $p <= $count; $p++) {
            DrawingSheet::query()->firstOrCreate(
                ['intelligent_document_id' => $doc->id, 'page_no' => $p],
                ['site_id' => $doc->site_id, 'status' => DrawingSheet::STATUS_PENDING],
            );
        }
        // 파일이 줄었으면(다시 올린 판이 더 짧음) 넘치는 쪽을 지운다.
        DrawingSheet::query()->where('intelligent_document_id', $doc->id)->where('page_no', '>', $count)->delete();

        return $this->status($request, $doc->id);
    }

    /** 한 쪽을 받는다. 읽기는 응답 뒤에 돈다. */
    public function page(Request $request, int $document, int $page): JsonResponse
    {
        if (UploadLimits::bodyOverflowed($request)) {
            return response()->json(['success' => false, 'error' => '한 쪽 사진이 서버 한도를 넘었습니다.'], 413);
        }

        $doc = $this->document($request, $document);
        $request->validate([
            'text' => ['nullable', 'string', 'max:400000'],
            'width' => ['nullable', 'numeric'],
            'height' => ['nullable', 'numeric'],
            'thumb' => ['nullable', 'file', 'mimes:jpg,jpeg', 'max:4096'],
            'tiles' => ['nullable', 'array', 'max:9'],
            'tiles.*' => ['file', 'mimes:jpg,jpeg', 'max:15360'],
        ]);

        $sheet = DrawingSheet::query()->firstOrCreate(
            ['intelligent_document_id' => $doc->id, 'page_no' => $page],
            ['site_id' => $doc->site_id],
        );

        $diskName = (string) config('filesystems.documents_disk');
        $disk = Storage::disk($diskName);

        $thumbPath = $sheet->thumb_path;
        if ($file = $request->file('thumb')) {
            $thumbPath = 'drawing-sheets/'.$doc->id.'/p'.$page.'.jpg';
            $disk->put($thumbPath, (string) file_get_contents($file->getRealPath()), 'private');
        }

        $sheet->forceFill([
            'site_id' => $doc->site_id,
            'status' => DrawingSheet::STATUS_READING,
            'error' => null,
            'thumb_disk' => $diskName,
            'thumb_path' => $thumbPath,
            'width_pt' => is_numeric($request->input('width')) ? (float) $request->input('width') : $sheet->width_pt,
            'height_pt' => is_numeric($request->input('height')) ? (float) $request->input('height') : $sheet->height_pt,
            // 글자가 든 쪽은 브라우저가 뽑은 글자가 정본이다. 사진 쪽은 AI 가 읽은 뒤 채운다.
            'text' => $request->hasFile('tiles') ? null : ($this->clean((string) $request->input('text', '')) ?: null),
        ])->save();

        $dir = DrawingSheetReader::tileDir($sheet);
        foreach ($disk->files($dir) as $old) {
            $disk->delete($old);
        }
        foreach (array_values($request->file('tiles', [])) as $i => $tile) {
            $disk->put($dir.'/t'.$i.'.jpg', (string) file_get_contents($tile->getRealPath()), 'private');
        }

        ReadDrawingSheetJob::dispatch($sheet->id)->afterResponse();

        return response()->json(['success' => true, 'sheet' => $this->service->sheetRow($sheet->fresh('document'))]);
    }

    /** 읽는 상태 — 화면이 이것을 되묻는다. */
    public function status(Request $request, int $document): JsonResponse
    {
        $doc = $this->document($request, $document, manage: false);
        $rows = DrawingSheet::query()->with('document:id,title,original_file_name')
            ->where('intelligent_document_id', $doc->id)->orderBy('page_no')->get()
            ->map(fn (DrawingSheet $s): array => $this->service->sheetRow($s))->all();

        return response()->json(['success' => true, 'documentId' => $doc->id, 'sheets' => $rows]);
    }

    public function thumb(Request $request, DrawingSheet $sheet): Response
    {
        $user = $request->user();
        abort_unless($this->service->canView($user)
            && $this->service->findDocument($user, $sheet->intelligent_document_id) !== null
            && $sheet->thumb_path, 404);

        $disk = Storage::disk((string) ($sheet->thumb_disk ?: config('filesystems.documents_disk')));
        abort_unless($disk->exists($sheet->thumb_path), 404);

        return response((string) $disk->get($sheet->thumb_path), 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** 읽은 글자 — AI 가 사진 도면을 제대로 읽었는지 사람이 눈으로 확인하는 자리. */
    public function text(Request $request, DrawingSheet $sheet): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->service->canView($user)
            && $this->service->findDocument($user, $sheet->intelligent_document_id) !== null, 404);

        return response()->json(['success' => true, 'sheet' => $this->service->sheetRow($sheet->load('document')),
            'text' => (string) $sheet->text]);
    }

    private function document(Request $request, int $id, bool $manage = true): IntelligentDocument
    {
        $user = $request->user();
        abort_unless($manage ? $this->service->canManage($user) : $this->service->canView($user), 403, '도면을 다룰 권한이 없습니다.');
        $doc = $this->service->findDocument($user, $id);
        abort_if($doc === null, 404, '도면 파일을 찾을 수 없습니다. 문서함에서 현장이 지정됐는지 확인하세요.');

        return $doc;
    }

    /** 서브셋 글꼴 PDF 는 깨진 바이트를 뱉는다 — Postgres 저장이 죽지 않게 걸러낸다(PdfText 와 같은 방어). */
    private function clean(string $text): string
    {
        $text = (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\R{3,}/u', "\n\n", $text) ?? $text);
    }
}
