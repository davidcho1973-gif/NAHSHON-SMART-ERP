<?php

namespace App\Http\Controllers;

use App\Models\MaterialReceipt;
use App\Models\Site;
use App\Services\Inventory\DeliverySlipAnalyzer;
use App\Services\Inventory\MaterialReceiptService;
use App\Support\MaterialReceiptAccess;
use App\Support\MaterialReceiptUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Mobile receiving and ERP intake share one ledger, permission and evidence boundary. */
class MaterialReceiptController extends Controller
{
    public function __construct(
        private readonly DeliverySlipAnalyzer $analyzer,
        private readonly MaterialReceiptService $receipts,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeManager($request);
        $sites = MaterialReceiptAccess::siteOptions($request->user());
        $preferred = $request->query('site_id') ?: $request->user()->allowed_site_id ?: $request->user()->employee?->site_id;
        $site = MaterialReceiptAccess::site($request->user(), $preferred)
            ?? MaterialReceiptAccess::site($request->user(), $sites[0]['value'] ?? null);

        return view('attendance-app.material-receipts', [
            'sites' => $sites,
            'initialSiteId' => $site?->id,
            'receivedOn' => now($site?->timezone ?: config('app.timezone'))->toDateString(),
        ]);
    }

    public function items(Request $request): JsonResponse
    {
        $this->authorizeManager($request);
        $request->validate(['site_id' => 'nullable|string|max:80']);
        $wanted = $request->query('site_id', 'ALL');
        if ($wanted !== 'ALL' && filled($wanted)) {
            $this->authorizeSite($request, $wanted, false);
        }

        return response()->json($this->receipts->list((string) $wanted));
    }

    /** Evidence can be kept even when OCR fails; nothing is booked by an upload. */
    public function upload(Request $request): JsonResponse
    {
        return response()->json($this->receiveUpload($request));
    }

    /** Keep the existing ERP photo-to-draft contract, using the same trusted upload token. */
    public function analyze(Request $request): JsonResponse
    {
        $result = $this->receiveUpload($request, true);
        $data = $result['data'] ?? $this->emptyAnalysis();
        $saved = ['success' => false];
        if ($data['lines'] !== []) {
            $saved = $this->receipts->save([
                'site_id' => $request->input('site_id'),
                'received_on' => $data['received_on'],
                'vendor' => $data['vendor'], 'po_no' => $data['po_no'], 'delivery_no' => $data['delivery_no'],
                'lines' => $data['lines'], 'photo' => $result['file'], 'analysis' => $data,
            ], (string) $request->input('site_id', 'ALL'), $request->user()->id);
        }

        return response()->json($result + [
            'data' => $data,
            'id' => $saved['success'] ? $saved['id'] : null,
            'saveError' => $saved['success'] ? null : ($saved['error'] ?? ($result['warning'] ?? null)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManager($request);
        $data = $request->validate([
            'id' => 'nullable|integer|min:1',
            'request_key' => 'nullable|uuid',
            'site_id' => 'required|integer',
            'received_on' => 'required|date_format:Y-m-d',
            'vendor' => 'nullable|string|max:160',
            'po_no' => 'nullable|string|max:80',
            'delivery_no' => 'nullable|string|max:80',
            'note' => 'nullable|string|max:10000',
            'photo' => 'nullable|array:token,name',
            'photo.token' => 'required_with:photo|string|max:8192',
            'photo.name' => 'nullable|string|max:255',
            'lines' => 'required|array|min:1|max:100',
            'lines.*' => 'required|array',
            'lines.*.item_id' => 'nullable|integer|exists:items,id',
            'lines.*.name' => 'required|string|max:255',
            'lines.*.quantity' => 'required|numeric|min:0.001|max:99999999999.999',
            'lines.*.unit' => 'nullable|string|max:32',
            'lines.*.unit_price' => 'nullable|numeric|min:0|max:999999999999.99',
            'lines.*.note' => 'nullable|string|max:2000',
        ]);
        $this->authorizeSite($request, $data['site_id']);
        if (! empty($data['id'])) {
            abort_unless(MaterialReceipt::query()->visibleTo($request->user())->whereKey($data['id'])->exists(), 403);
        }
        $result = $this->receipts->save($data, (string) $data['site_id'], $request->user()->id);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function confirmMobile(Request $request, MaterialReceipt $receipt): JsonResponse
    {
        $this->authorizeManager($request);
        abort_unless(MaterialReceipt::query()->visibleTo($request->user())->whereKey($receipt->id)->exists(), 403);
        $result = $this->receipts->confirm($receipt->id);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function showFile(Request $request, MaterialReceipt $receipt)
    {
        $this->authorizeManager($request);
        abort_unless(MaterialReceipt::query()->visibleTo($request->user())->whereKey($receipt->id)->exists(), 403);
        $disk = $receipt->photo_disk ?: 'public';
        abort_unless(filled($receipt->photo_path) && str_starts_with($receipt->photo_path, 'material-receipts/')
            && Storage::disk($disk)->exists($receipt->photo_path), 404);

        return Storage::disk($disk)->download($receipt->photo_path, $receipt->photo_name ?: 'delivery-'.$receipt->id, [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function receiveUpload(Request $request, bool $forceAnalyze = false): array
    {
        $this->authorizeManager($request);
        $request->validate([
            'file' => 'required|file|max:32768|mimes:jpg,jpeg,png,webp,heic,heif,pdf,doc,docx,xls,xlsx,csv,txt',
            'site_id' => 'required', 'analyze' => 'nullable|boolean',
        ]);
        // Scope must be checked before either AI processing or a durable storage write.
        $site = $this->authorizeSite($request, $request->input('site_id'));
        $file = $request->file('file');
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $extension = strtolower($file->getClientOriginalExtension());
        // A global MIME list also admits a text file renamed .jpg because text is supported.
        // Match each claimed format to the detected type before retaining it as evidence.
        $allowedTypes = [
            'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'],
            'webp' => ['image/webp'], 'heic' => ['image/heic', 'image/heif', 'image/heic-sequence'],
            'heif' => ['image/heif', 'image/heic', 'image/heif-sequence'], 'pdf' => ['application/pdf'],
            'doc' => ['application/msword', 'application/x-ole-storage', 'application/CDFV2'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            'csv' => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'],
            'txt' => ['text/plain'],
        ];
        if (! in_array($mime, $allowedTypes[$extension] ?? [], true)) {
            throw ValidationException::withMessages(['file' => '파일 확장자와 실제 형식이 일치하지 않습니다. 원본 사진 또는 문서로 다시 올려주세요.']);
        }
        try {
            $photo = MaterialReceiptUpload::store($file, $request->user(), $site);
        } catch (\Throwable $e) {
            report($e);
            abort(503, '파일을 보관하지 못했습니다. 다시 업로드해 주세요.');
        }
        $result = ['success' => true, 'file' => $photo];
        if (! $forceAnalyze && ! $request->boolean('analyze')) {
            return $result;
        }

        if (! str_starts_with($mime, 'image/') && $mime !== 'application/pdf') {
            return $result + ['warning' => '파일은 보관했습니다. 이 형식은 자동 판독하지 않으므로 실제 입고 품목과 수량을 입력해 주세요.'];
        }
        try {
            $result['data'] = $this->analyzer->analyze($file->getRealPath(), $mime);
            if ($result['data']['lines'] === []) {
                $result['warning'] = '파일은 보관했지만 품목을 읽지 못했습니다. 실제 입고 품목과 수량을 직접 입력해 주세요.';
            }
        } catch (\Throwable $e) {
            report($e);
            $result['warning'] = '파일은 보관했습니다. 자동 판독을 완료하지 못했으므로 품목과 실제 입고 수량을 직접 입력해 주세요.';
        }

        return $result;
    }

    private function authorizeManager(Request $request): void
    {
        abort_unless(MaterialReceiptAccess::canManage($request->user()), 403, '자재 입고를 기록할 권한이 없습니다.');
        abort_if($request->filled('as'), 403, '다른 사람 화면 보기에서는 입고를 처리할 수 없습니다.');
    }

    private function authorizeSite(Request $request, mixed $wanted, bool $activeOnly = true): Site
    {
        $site = MaterialReceiptAccess::site($request->user(), $wanted, $activeOnly);
        abort_unless($site, 403, '이 현장에 자재 입고를 기록할 권한이 없습니다.');

        return $site;
    }

    private function emptyAnalysis(): array
    {
        return ['vendor' => null, 'po_no' => null, 'delivery_no' => null, 'received_on' => null,
            'lines' => [], 'confidence' => null, 'summary' => null];
    }
}
