<?php

namespace App\Http\Controllers;

use App\Models\IntegratedDocument;
use App\Models\MaterialReceipt;
use App\Services\Inventory\DeliverySlipAnalyzer;
use App\Services\Inventory\MaterialReceiptService;
use App\Support\AccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * 납품서 사진 → AI 판독 → <b>확인 대기</b> 입고 한 장.
 *
 * 사진을 올리면 그 자리에서 초안이 만들어진다. 현장에서 손으로 품목을 옮겨 적는
 * 단계를 없애는 것이 목적이다 — 그 단계가 있으면 아무도 안 적고, 안 적으면 없는 일이 된다.
 *
 * 판독이 곧 장부는 아니다. 만들어지는 것은 `draft` 이고, 사람이 화면에서 수량을 보고
 * «확정» 을 눌러야 숫자가 된다.
 */
class MaterialReceiptController extends Controller
{
    public function __construct(
        private readonly DeliverySlipAnalyzer $analyzer,
        private readonly MaterialReceiptService $receipts,
    ) {}

    public function analyze(Request $request): JsonResponse
    {
        if (! AccessPolicy::canManageSite($request->user())) {
            return response()->json(['success' => false, 'error' => '자재 입고를 기록할 권한이 없습니다.'], 403);
        }

        $request->validate([
            'file' => 'required|file|max:32768',
            'site_id' => 'nullable',
        ]);

        try {
            $file = $request->file('file');
            $ext = strtolower((string) $file?->getClientOriginalExtension());
            if (! in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'heic'], true)) {
                return response()->json([
                    'success' => false,
                    'error' => sprintf('지원하지 않는 형식입니다(.%s). 납품서는 사진(JPG·PNG) 또는 PDF 로 올려주세요.', $ext ?: '?'),
                ], 422);
            }

            $mime = $file->getClientMimeType() ?: ($file->getMimeType() ?: 'application/octet-stream');

            // 임시 파일을 바로 읽고(왕복 한 번), 원본은 근거로 보관한다.
            $data = $this->analyzer->analyze($file->getRealPath(), $mime);

            $disk = IntegratedDocument::storageDisk();
            $path = $file->store('material-receipts', $disk);
            $photo = [
                'disk' => $disk,
                'path' => $path,
                'name' => $file->getClientOriginalName() ?: basename($path),
            ];

            // 품목을 한 줄이라도 읽었으면 확인 대기 상태로 바로 만들어 둔다.
            // 한 줄도 못 읽었으면 저장하지 않는다 — 빈 입고 한 장이 목록에 남아 있으면
            // 나중에 그게 «아직 안 적은 것» 인지 «온 게 없는 것» 인지 아무도 모른다.
            $saved = ['success' => false];
            if ($data['lines'] !== []) {
                $saved = $this->receipts->save([
                    'site_id' => $request->input('site_id'),
                    'received_on' => $data['received_on'],
                    'vendor' => $data['vendor'],
                    'po_no' => $data['po_no'],
                    'delivery_no' => $data['delivery_no'],
                    'lines' => $data['lines'],
                    'photo' => $photo,
                    'analysis' => $data,
                ], (string) $request->input('site_id', 'ALL'), $request->user()?->id);
            }

            return response()->json([
                'success' => true,
                'data' => $data,
                'file' => $photo,
                'id' => $saved['success'] ? $saved['id'] : null,
                // 읽기는 했는데 저장이 막힌 경우(현장 미선택 등)를 숨기지 않는다.
                'saveError' => $saved['success'] ? null : ($saved['error'] ?? null),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /** 근거 사진 원본 열람. */
    public function showFile(Request $request, MaterialReceipt $receipt)
    {
        abort_unless(
            MaterialReceipt::query()->visibleTo($request->user())->whereKey($receipt->id)->exists(),
            403,
        );

        $disk = $receipt->photo_disk ?: 'public';
        abort_unless(filled($receipt->photo_path) && Storage::disk($disk)->exists($receipt->photo_path), 404);

        return Storage::disk($disk)->response(
            $receipt->photo_path,
            $receipt->photo_name ?: ('delivery-'.$receipt->id),
        );
    }
}
