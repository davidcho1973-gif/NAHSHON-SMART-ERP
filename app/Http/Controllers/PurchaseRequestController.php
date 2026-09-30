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
