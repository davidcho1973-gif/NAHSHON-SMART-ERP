<?php

namespace App\Http\Controllers;

use App\Services\Procurement\PurchaseRequestService;
use App\Support\PurchaseAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class PurchaseRequestController extends Controller
{
    public function __construct(private readonly PurchaseRequestService $service) {}

    public function mobile(Request $request)
    {
        abort_unless(PurchaseAccess::canRequest($request->user()), 403, '구매 신청 권한이 없습니다.');

        return view('attendance-app.purchase-requests');
    }

    public function index(Request $request): JsonResponse
    {
        return $this->respond(fn (): array => $this->service->index($request->boolean('desk'), $request->integer('site_id') ?: null, $request->string('status')->toString() ?: null));
    }

    public function show(int $id): JsonResponse
    {
        return $this->respond(fn (): array => $this->service->detail($id));
    }

    public function store(Request $request): JsonResponse
    {
        return $this->respond(fn (): array => $this->service->create($request->all()));
    }

    public function action(Request $request, int $id): JsonResponse
    {
        return $this->respond(fn (): array => $this->service->action($id, $request->all()));
    }

    public function receipts(int $id): JsonResponse
    {
        return $this->respond(fn (): array => $this->service->receipts($id));
    }

    public function email(Request $request, int $id): JsonResponse
    {
        return $this->respond(function () use ($request, $id): array {
            PurchaseAccess::assertBuyer($request->user());
            $row = $this->service->visible($id);
            abort_if($row->status === 'cancelled', 422, '취소된 요청입니다.');
            $data = $request->validate([
                'to' => 'required|email:rfc|max:255', 'subject' => 'required|string|max:200',
                'body' => 'required|string|max:12000', 'request_key' => 'required|uuid',
                'attachment_ids' => 'present|array|max:20', 'attachment_ids.*' => 'integer|distinct',
            ]);
            abort_if(preg_match('/[\r\n]/', $data['subject'].$data['to']), 422, '수신처와 제목을 확인하세요.');
            abort_if(in_array(config('mail.default'), ['log', 'array'], true), 503, '회사 이메일 발송 설정이 필요합니다.');
            $files = $row->attachments()->whereIn('id', $data['attachment_ids'])->get();
            abort_unless($files->count() === count($data['attachment_ids']), 422, '이 요청의 첨부자료만 선택하세요.');
            abort_if($files->sum('size') > 15 * 1024 * 1024, 422, '이메일 첨부는 합계 15MB까지 가능합니다.');
            $key = $request->user()->id.':email:'.$data['request_key'];
            $fingerprint = hash('sha256', json_encode($data));
            return \Illuminate\Support\Facades\DB::transaction(function () use ($row, $request, $data, $key, $fingerprint): array {
                $row = \App\Models\PurchaseRequest::whereKey($row->id)->lockForUpdate()->firstOrFail();
                $this->service->visible($row->id);
                abort_if($row->status === 'cancelled', 422, '취소된 요청입니다.');
                $previous = $row->events()->where('request_key', $key)->first();
                if ($previous) {
                    abort_unless(hash_equals($previous->fingerprint, $fingerprint), 409, '이미 다른 내용으로 전송한 요청입니다.');
                    return ['success' => true, 'delivery' => $previous->data['delivery'], 'replayed' => true];
                }
                $event = $row->events()->create(['actor_id' => $request->user()->id, 'action' => 'email',
                    'status' => $row->status, 'message' => '업체 이메일 전송 대기: '.$data['to'],
                    'data' => $data + ['delivery' => 'queued'], 'request_key' => $key, 'fingerprint' => $fingerprint]);
                \App\Jobs\SendPurchaseInquiry::dispatch($event->id)->onConnection('document-analysis')->onQueue('purchases')->afterCommit();
                return ['success' => true, 'delivery' => 'queued'];
            });
        });
    }

    public function upload(Request $request, int $id): JsonResponse
    {
        return $this->respond(function () use ($request, $id): array {
            $request->validate(['file' => 'required|file', 'purpose' => 'required|in:request,order']);

            return $this->service->upload($id, $request->file('file'), $request->input('purpose'));
        });
    }

    public function download(int $id, int $attachment)
    {
        $file = $this->service->download($id, $attachment);

        return Storage::disk($file->disk)->download($file->path, $file->name, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    private function respond(callable $action): JsonResponse
    {
        try {
            return response()->json($action());
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'error' => collect($e->errors())->flatten()->first(), 'errors' => $e->errors()], 422);
        } catch (HttpExceptionInterface $e) {
            if ($e->getStatusCode() === 403 && PurchaseAccess::hasBuyerPermission(request()->user())
                && ! PurchaseAccess::canBuy(request()->user())) {
                return response()->json(['success' => false, 'code' => 'purchase_reauthentication_required',
                    'error' => '구매 처리는 비밀번호 또는 Google로 다시 로그인한 뒤 이용하세요.',
                    'reauthenticate_url' => '/login'], 403);
            }

            return response()->json(['success' => false, 'error' => $e->getMessage() ?: '접근 권한이 없거나 요청을 찾을 수 없습니다.'], $e->getStatusCode());
        }
    }
}
