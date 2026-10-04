<?php

namespace App\Services\Procurement;

use App\Jobs\SendPurchaseRequestPush;
use App\Models\AiJob;
use App\Models\CommunicationNotification;
use App\Models\Equipment;
use App\Models\Item;
use App\Models\MaterialReceipt;
use App\Models\MaterialReceiptLine;
use App\Models\PurchaseReceiptAllocation;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestAttachment;
use App\Models\PurchaseRequestLine;
use App\Models\PurchaseRequestOrder;
use App\Models\User;
use App\Services\Vendors\VendorResolver;
use App\Support\PurchaseAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Requests are separate from purchases; receiving reads the existing receipt ledger. */
class PurchaseRequestService
{
    public const REASONS = [
        'needs_info' => ['size' => '규격 확인', 'quantity' => '수량 확인', 'photo' => '사진 필요', 'purpose' => '용도 확인'],
        'hold' => ['budget' => '예산 확인', 'schedule' => '일정 조정', 'approval' => '승인 대기', 'duplicate' => '중복 확인'],
        'out_of_stock' => ['sold_out' => '재고 없음', 'discontinued' => '단종', 'lead_time' => '납기 확인'],
        'cancel' => ['no_longer_needed' => '요청 철회', 'duplicate' => '중복 요청', 'changed' => '계획 변경', 'supplier_cancelled' => '주문 취소 확인'],
    ];

    public function index(bool $desk = false, ?int $siteId = null, ?string $status = null): array
    {
        $user = $this->actor();
        abort_unless($desk ? PurchaseAccess::canBuy($user) : PurchaseAccess::canRequest($user), 403, '구매 신청 권한이 없습니다.');
        $sites = PurchaseAccess::sites($user);
        if ($siteId) {
            PurchaseAccess::assertSite($user, $siteId);
        }
        $query = PurchaseRequest::query()->select('purchase_requests.*')->whereIn('site_id', $sites->pluck('id'))
            ->when(! $desk, fn ($q) => $q->where('requested_by_id', $user->id))
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId));
        $quantities = DB::table('purchase_request_lines')->selectRaw("purchase_request_id, sum(quantity) as requested, count(*) FILTER (WHERE quantity IS NULL OR unit IS NULL OR trim(unit) = '') as unresolved")->groupBy('purchase_request_id');
        $orders = DB::table('purchase_request_orders')->join('purchase_request_order_lines', 'purchase_request_order_lines.purchase_request_order_id', '=', 'purchase_request_orders.id')
            ->selectRaw('purchase_request_orders.purchase_request_id, sum(purchase_request_order_lines.quantity) as ordered')->groupBy('purchase_request_orders.purchase_request_id');
        $receipts = DB::table('purchase_receipt_allocations')->join('purchase_request_lines', 'purchase_request_lines.id', '=', 'purchase_receipt_allocations.purchase_request_line_id')
            ->join('material_receipt_lines', 'material_receipt_lines.id', '=', 'purchase_receipt_allocations.material_receipt_line_id')
            ->join('material_receipts', 'material_receipts.id', '=', 'material_receipt_lines.material_receipt_id')
            ->where('material_receipts.status', 'confirmed')->selectRaw('purchase_request_lines.purchase_request_id, sum(purchase_receipt_allocations.quantity) as received')->groupBy('purchase_request_lines.purchase_request_id');
        $query->leftJoinSub($quantities, 'pr_qty', 'pr_qty.purchase_request_id', '=', 'purchase_requests.id')
            ->leftJoinSub($orders, 'pr_order', 'pr_order.purchase_request_id', '=', 'purchase_requests.id')
            ->leftJoinSub($receipts, 'pr_receipt', 'pr_receipt.purchase_request_id', '=', 'purchase_requests.id');
        // Filter the derived status before limiting: old unfulfilled requests must not
        // disappear behind a page of more recent completed purchases.
        $effectiveStatus = "CASE WHEN purchase_requests.status = 'cancelled' THEN 'cancelled' WHEN COALESCE(pr_qty.requested,0) > 0 AND COALESCE(pr_qty.unresolved,0) = 0 AND COALESCE(pr_receipt.received,0) >= pr_qty.requested THEN 'received' WHEN COALESCE(pr_receipt.received,0) > 0 THEN 'partial' WHEN purchase_requests.status IN ('needs_info','on_hold','out_of_stock') THEN purchase_requests.status WHEN purchase_requests.status = 'supplier_confirmed' THEN 'supplier_confirmed' WHEN COALESCE(pr_qty.requested,0) > 0 AND COALESCE(pr_qty.unresolved,0) = 0 AND COALESCE(pr_order.ordered,0) >= pr_qty.requested THEN 'ordered' WHEN COALESCE(pr_order.ordered,0) > 0 THEN 'partially_ordered' ELSE purchase_requests.status END";
        if ($status && $status !== 'all') {
            $query->whereRaw('('.$effectiveStatus.') = ?', [$status]);
        }
        $rows = $query->with($this->relations())->orderByRaw("CASE WHEN ({$effectiveStatus}) IN ('received','cancelled') THEN 1 ELSE 0 END")
            ->orderByDesc('purchase_requests.updated_at')->limit(501)->get()
            ->map(fn (PurchaseRequest $r): array => $this->serialize($r, $user, $desk));

        return ['success' => true, 'can_request' => PurchaseAccess::canRequest($user), 'can_buy' => PurchaseAccess::canBuy($user),
            'sites' => $sites->map(fn ($s): array => ['id' => $s->id, 'name' => $s->name, 'code' => $s->code])->all(),
            'statuses' => PurchaseRequest::STATUSES, 'reasons' => self::REASONS, 'has_more' => $rows->count() > 500, 'rows' => $rows->take(500)->values()->all()];
    }

    public function visible(int $id, ?User $user = null): PurchaseRequest
    {
        $user ??= $this->actor();
        abort_unless(PurchaseAccess::canRequest($user) || PurchaseAccess::canBuy($user), 403, '구매 신청 권한이 없습니다.');
        $row = PurchaseRequest::query()->whereKey($id)->firstOrFail();
        abort_unless(PurchaseAccess::canUseSite($user, $row->site)
            && (PurchaseAccess::canBuy($user) || $row->requested_by_id === $user->id), 404);

        return $row;
    }

    public function detail(int $id): array
    {
        $user = $this->actor();
        $row = $this->visible($id, $user);
        $data = $this->serialize($row, $user);
        if (PurchaseAccess::canBuy($user)) {
            $data['context'] = $this->context($row, $user);
        }

        return ['success' => true, 'request' => $data];
    }

    public function create(array $input): array
    {
        $user = $this->actor();
        abort_unless(PurchaseAccess::canRequest($user), 403, '구매 신청 권한이 없습니다.');
        $data = Validator::make($input, [
            'site_id' => 'required|integer|min:1', 'need_by' => 'nullable|date_format:Y-m-d',
            'note' => 'nullable|string|max:10000', 'request_key' => 'required|uuid',
            'analysis_job_id' => 'nullable|integer|min:1',
        ] + $this->lineRules())->validate();
        $site = PurchaseAccess::assertSite($user, (int) $data['site_id']);
        abort_unless($site->status === 'active', 422, '운영 중인 현장을 선택하세요.');
        $data['lines'] = $this->cleanLines($data['lines']);
        $fingerprint = $this->fingerprint($data);
        $key = $user->id.':'.strtolower($data['request_key']);

        return DB::transaction(function () use ($user, $site, $data, $key, $fingerprint): array {
            User::whereKey($user->id)->lockForUpdate()->first();
            $existing = PurchaseRequest::where('request_key', $key)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_fingerprint, $fingerprint), 409, '이미 다른 내용으로 저장된 요청입니다. 목록을 확인하세요.');

                return ['success' => true, 'replayed' => true, 'request' => $this->serialize($existing, $user)];
            }
            $row = PurchaseRequest::create([
                'company_id' => $site->company_id, 'site_id' => $site->id, 'requested_by_id' => $user->id,
                'request_key' => $key, 'request_fingerprint' => $fingerprint,
                'status' => 'submitted', 'need_by' => $data['need_by'] ?? null, 'note' => $data['note'] ?? null,
            ]);
            foreach ($data['lines'] as $seq => $line) {
                $row->lines()->create($line + ['seq' => $seq]);
            }
            if (! empty($data['analysis_job_id'])) {
                $this->attachAnalysis($row, (int) $data['analysis_job_id'], $user, 'request');
            }
            $this->event($row, $user, 'submit', '구매 요청이 등록되었습니다.', ['analysis_job_id' => $data['analysis_job_id'] ?? null], notify: false);
            // A new request enters only the scoped buyer's inbox, never a site-wide chat.
            User::query()->where('account_status', 'active')->where(function ($q): void {
                $q->where('purchase_buy_enabled', true)->orWhere('access_role', 'super_admin');
            })->get()->filter(fn (User $buyer): bool => PurchaseAccess::eligible($buyer)
                && PurchaseAccess::canUseSite($buyer, $site) && $buyer->id !== $user->id)
                ->each(fn (User $buyer) => $this->notify($row, $buyer, '새 구매 요청', $user->name.' · '.$row->lines()->first()->name, true));

            return ['success' => true, 'request' => $this->serialize($row->fresh(), $user)];
        });
    }

    public function action(int $id, array $input): array
    {
        $user = $this->actor();
        $this->visible($id, $user);
        $data = Validator::make($input, [
            'action' => ['required', Rule::in(['review', 'needs_info', 'hold', 'out_of_stock', 'order', 'eta', 'receive', 'cancel', 'clarify', 'resolve', 'contact', 'supplier_confirm'])],
            'version' => 'required|integer|min:1', 'request_key' => 'nullable|uuid', 'reason' => 'nullable|string|max:80',
            'eta' => 'nullable|date_format:Y-m-d', 'note' => 'nullable|string|max:10000',
            'vendor' => 'nullable|string|max:255', 'order_number' => 'nullable|string|max:160',
            'amount' => 'nullable|numeric|min:0|max:9999999999999.99', 'currency' => ['nullable', Rule::in(['USD', 'KRW', 'EUR', 'CAD'])],
            'evidence_id' => 'nullable|integer|min:1', 'analysis_job_id' => 'nullable|integer|min:1',
            'order_lines' => 'nullable|array|min:1|max:100', 'order_lines.*.request_line_id' => 'required|integer|distinct',
            'order_lines.*.quantity' => 'required|numeric|min:0.001|max:99999999999.999',
            'receipt_id' => 'nullable|integer|min:1', 'allocations' => 'nullable|array|min:1|max:100',
            'allocations.*.request_line_id' => 'required|integer', 'allocations.*.receipt_line_id' => 'required|integer',
            'allocations.*.quantity' => 'required|numeric|min:0.001|max:99999999999.999',
        ] + $this->lineRules(false))->validate();
        $action = $data['action'];
        if ($action !== 'clarify') {
            PurchaseAccess::assertBuyer($user);
        }
        $key = ! empty($data['request_key']) ? $user->id.':'.strtolower($data['request_key']) : null;
        $fingerprint = $this->fingerprint(array_diff_key($data, array_flip(['version', 'request_key'])));

        return DB::transaction(function () use ($id, $data, $user, $action, $key, $fingerprint): array {
            $row = PurchaseRequest::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->visible($id, $user);
            if ($key && ($event = $row->events()->where('request_key', $key)->first())) {
                abort_unless(hash_equals((string) $event->fingerprint, $fingerprint), 409, '같은 처리번호로 다른 변경을 보낼 수 없습니다.');

                return ['success' => true, 'replayed' => true, 'request' => $this->serialize($row, $user)];
            }
            abort_unless($row->version === (int) $data['version'], 409, '다른 변경이 먼저 저장되었습니다. 새로고침 후 확인하세요.');
            $state = $this->serialize($row, $user);
            $status = $state['status'];
            $eventData = [];
            $message = '';
            $noOp = false;
            if ($action === 'supplier_confirm') {
                abort_unless($status === 'ordered' && filled($data['eta'] ?? null) && filled($data['note'] ?? null), 422, '주문 완료 후 업체가 확인한 납품일과 확인 내용을 입력하세요.');
                $row->status = 'supplier_confirmed';
                $row->eta = $data['eta'];
                $message = '업체 납품 확정: '.$data['eta'].' · '.$data['note'];
                $eventData = ['eta' => $data['eta'], 'confirmation' => $data['note']];
            } elseif ($action === 'contact') {
                abort_if(in_array($status, ['cancelled', 'received'], true), 422, '완료된 요청입니다.');
                abort_unless(filled($data['note'] ?? null), 422, '연락 결과를 입력하세요.');
                $message = '업체 연락: '.$data['note'];
            } elseif ($action === 'resolve') {
                abort_if($row->orders()->exists() || in_array($status, ['cancelled', 'received'], true), 422, '주문 전 요청만 확정할 수 있습니다.');
                abort_unless(! empty($data['lines']), 422, '품목을 입력하세요.');
                $confirmed = $this->cleanLines($data['lines']);
                foreach ($confirmed as $line) {
                    abort_unless($line['quantity'] !== null && filled($line['unit']), 422, '구매 전 수량과 단위를 확인하세요.');
                }
                $row->lines()->delete();
                foreach ($confirmed as $seq => $line) {
                    $row->lines()->create($line + ['seq' => $seq]);
                }
                $message = '구매 담당자가 품목과 수량을 확정했습니다.';
            } elseif ($action === 'clarify') {
                abort_unless(PurchaseAccess::canRequest($user) && $row->requested_by_id === $user->id && $row->status === 'needs_info', 403, '본인의 정보 요청에만 답할 수 있습니다.');
                abort_unless(filled($data['note'] ?? null) || ! empty($data['lines']), 422, '확인할 내용을 입력하세요.');
                if (! empty($data['lines'])) {
                    abort_if($row->orders()->exists(), 422, '이미 구매한 품목은 변경할 수 없습니다. 추가 내용으로 알려주세요.');
                    $row->lines()->delete();
                    foreach ($this->cleanLines($data['lines']) as $seq => $line) {
                        $row->lines()->create($line + ['seq' => $seq]);
                    }
                }
                $row->status = $row->orders()->exists() ? 'ordered' : 'submitted';
                $row->reason = null;
                $message = '요청자가 내용을 보완했습니다.'.(filled($data['note'] ?? null) ? ' '.$data['note'] : '');
            } elseif ($action === 'order') {
                abort_if(in_array($status, ['cancelled', 'received'], true), 422, '이 요청은 구매할 수 없습니다.');
                abort_if(collect($state['lines'])->contains(fn ($l) => $l['quantity'] === null || blank($l['unit'])), 422, '품목 수량과 단위를 먼저 확정하세요.');
                $order = $this->order($row, $data, $user, $state);
                $row->status = 'ordered';
                $row->reason = null;
                if (array_key_exists('eta', $data)) {
                    $row->eta = $data['eta'];
                }
                $eventData = ['order_id' => $order->id, 'analysis_job_id' => $data['analysis_job_id'] ?? null];
                $message = '구매 내역이 등록되었습니다.';
            } elseif ($action === 'receive') {
                abort_if(in_array($status, ['cancelled', 'received'], true) || ! $row->orders()->exists(), 422, '입고를 연결할 구매 내역이 없습니다.');
                $eventData = $this->receive($row, $data, $user, $state);
                $message = '현장 확인 입고가 연결되었습니다.';
            } elseif ($action === 'eta') {
                abort_if(in_array($status, ['cancelled', 'received'], true), 422, '완료된 요청은 납기를 변경할 수 없습니다.');
                abort_unless(array_key_exists('eta', $data), 422, '도착 예정일 또는 미정을 선택하세요.');
                $noOp = $row->eta?->toDateString() === ($data['eta'] ?? null);
                $row->eta = $data['eta'] ?? null;
                if (! $noOp && $row->status === 'supplier_confirmed') {
                    $row->status = 'ordered';
                }
                $message = $row->eta ? '도착 예정일: '.$row->eta->format('Y-m-d') : '도착 예정일을 확인 중입니다.';
            } else {
                abort_if(in_array($status, ['cancelled', 'received'], true), 422, '완료된 요청은 변경할 수 없습니다.');
                $target = ['review' => 'reviewing', 'needs_info' => 'needs_info', 'hold' => 'on_hold', 'out_of_stock' => 'out_of_stock', 'cancel' => 'cancelled'][$action];
                if ($action === 'review') {
                    abort_if($row->orders()->exists(), 422, '구매 내역이 있으므로 구매 단계를 되돌릴 수 없습니다.');
                }
                $reason = $action === 'review' ? null : ($data['reason'] ?? null);
                if ($action !== 'review') {
                    abort_unless(isset(self::REASONS[$action][$reason ?? '']), 422, '사유를 선택하세요.');
                }
                if ($action === 'cancel') {
                    abort_if(collect($state['lines'])->sum('received_quantity') > 0, 422, '이미 입고된 요청입니다. 입고 정정 후 처리하세요.');
                    abort_if($row->orders()->exists() && $reason !== 'supplier_cancelled', 422, '구매처의 주문 취소를 확인한 뒤 «주문 취소 확인»을 선택하세요. 환불 처리는 별도입니다.');
                }
                $noOp = $row->status === $target && $row->reason === $reason;
                $row->status = $target;
                $row->reason = $reason;
                $message = $action === 'review' ? '구매 담당자가 확인 중입니다.' : self::REASONS[$action][$reason];
                if (in_array($action, ['hold', 'out_of_stock'], true) && array_key_exists('eta', $data)) {
                    $noOp = $noOp && $row->eta?->toDateString() === ($data['eta'] ?? null);
                    $row->eta = $data['eta'];
                }
            }
            if ($noOp) {
                return ['success' => true, 'unchanged' => true, 'request' => $this->serialize($row, $user)];
            }
            $row->version++;
            $row->save();
            $row->unsetRelations();
            $this->event($row, $user, $action, $message, $eventData, $key, $fingerprint);

            return ['success' => true, 'request' => $this->serialize($row->fresh(), $user)];
        });
    }

    public function upload(int $id, UploadedFile $file, string $purpose): array
    {
        $user = $this->actor();
        $row = $this->visible($id, $user);
        abort_unless(in_array($purpose, ['request', 'order'], true), 422);
        if ($purpose === 'order') {
            PurchaseAccess::assertBuyer($user);
        } else {
            abort_unless(PurchaseAccess::canBuy($user) || ($row->requested_by_id === $user->id && PurchaseAccess::canRequest($user)), 403);
        }
        Validator::make(['file' => $file], ['file' => 'required|file|max:32768|mimes:pdf,jpg,jpeg,png,webp,docx,xlsx,txt,csv'])->validate();
        // Never accept an HTML/SVG/executable disguised by its file name.
        abort_unless(in_array(strtolower($file->getClientOriginalExtension()), ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'docx', 'xlsx', 'txt', 'csv'], true), 422, '지원하지 않는 파일 형식입니다.');
        $disk = (string) config('filesystems.wbs_photos_disk', 'local');
        // The public local disk is web-addressable regardless of the object visibility flag.
        abort_if($disk === 'public', 503, '비공개 파일 저장소 설정이 필요합니다.');
        $path = $file->store('purchase-requests/'.$row->id, ['disk' => $disk, 'visibility' => 'private']);
        abort_unless($path && Storage::disk($disk)->exists($path), 503, '첨부를 보관하지 못했습니다. 다시 시도하세요.');
        try {
            return DB::transaction(function () use ($id, $user, $purpose, $disk, $path, $file): array {
                $row = PurchaseRequest::whereKey($id)->lockForUpdate()->firstOrFail();
                $this->visible($id, $user);
                abort_if($row->status === 'cancelled', 422, '취소된 요청에는 첨부할 수 없습니다.');
                $attachment = $row->attachments()->create([
                    'uploaded_by_id' => $user->id, 'purpose' => $purpose, 'disk' => $disk, 'path' => $path,
                    'name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255),
                    'mime' => $file->getMimeType() ?: 'application/octet-stream', 'size' => $file->getSize(),
                ]);
                $row->increment('version');
                $this->event($row, $user, 'attachment', $purpose === 'order' ? '구매 증빙이 추가되었습니다.' : '요청 자료가 추가되었습니다.', ['attachment_id' => $attachment->id], notify: false);

                return ['success' => true, 'attachment' => $this->attachmentRow($attachment), 'request' => $this->serialize($row->fresh(), $user)];
            });
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($path);
            throw $e;
        }
    }

    public function download(int $id, int $attachmentId): PurchaseRequestAttachment
    {
        $row = $this->visible($id);
        $attachment = $row->attachments()->whereKey($attachmentId)->firstOrFail();
        abort_unless($attachment->purpose === 'request' || PurchaseAccess::canBuy($this->actor()), 404);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return $attachment;
    }

    public function receipts(int $id): array
    {
        $user = PurchaseAccess::assertBuyer($this->actor());
        $row = $this->visible($id, $user);
        $receipts = MaterialReceipt::query()->where('site_id', $row->site_id)->where('status', MaterialReceipt::STATUS_CONFIRMED)
            ->with('lines')->orderByDesc('received_on')->limit(100)->get()->map(fn (MaterialReceipt $r): array => [
                'id' => $r->id, 'received_on' => $r->received_on?->toDateString(), 'vendor' => $r->vendor, 'po_no' => $r->po_no,
                'lines' => $r->lines->map(fn (MaterialReceiptLine $l): array => [
                    'id' => $l->id, 'name' => $l->name, 'quantity' => (float) $l->quantity, 'unit' => $l->unit,
                    'available_quantity' => max(0, round((float) $l->quantity - (float) PurchaseReceiptAllocation::where('material_receipt_line_id', $l->id)->sum('quantity'), 3)),
                ])->all(),
            ]);

        return ['success' => true, 'receipts' => $receipts->all()];
    }

    public function serialize(PurchaseRequest $row, User $user, ?bool $buyerView = null): array
    {
        $buyerView ??= PurchaseAccess::canBuy($user);
        $row->loadMissing($this->relations());
        $lines = $row->lines->map(function (PurchaseRequestLine $line): array {
            $ordered = round((float) $line->orderLines->sum('quantity'), 3);
            $received = round((float) $line->allocations->filter(fn ($a): bool => $a->receiptLine?->receipt?->isConfirmed() === true)->sum('quantity'), 3);

            return ['id' => $line->id, 'name' => $line->name, 'specification' => $line->specification,
                'quantity' => $line->quantity !== null ? (float) $line->quantity : null, 'unit' => $line->unit, 'product_url' => $line->product_url,
                'ordered_quantity' => $ordered, 'remaining_to_order' => max(0, round((float) $line->quantity - $ordered, 3)),
                'received_quantity' => $received, 'remaining_to_receive' => max(0, round($ordered - $received, 3))];
        })->all();
        $orderedAny = collect($lines)->sum('ordered_quantity') > 0;
        $receivedAny = collect($lines)->sum('received_quantity') > 0;
        $orderedAll = $lines !== [] && collect($lines)->every(fn ($l): bool => $l['quantity'] !== null && filled($l['unit']) && $l['remaining_to_order'] <= 0);
        $receivedAll = $orderedAll && collect($lines)->every(fn ($l): bool => $l['received_quantity'] >= $l['quantity']);
        $status = $row->status;
        if ($status !== 'cancelled') {
            if ($receivedAll) {
                $status = 'received';
            } elseif ($receivedAny) {
                $status = 'partial';
            } elseif (! in_array($status, ['on_hold', 'out_of_stock', 'needs_info'], true)) {
                $status = $orderedAll ? ($status === 'supplier_confirmed' ? 'supplier_confirmed' : 'ordered') : ($orderedAny ? 'partially_ordered' : $status);
            }
        }
        $orders = $row->orders->map(function ($order) use ($buyerView): array {
            $data = ['id' => $order->id, 'order_number' => $order->order_number, 'vendor' => $order->vendor,
                'ordered_at' => $order->ordered_at?->toIso8601String(), 'lines' => $order->lines->map(fn ($l): array => ['request_line_id' => $l->purchase_request_line_id, 'quantity' => (float) $l->quantity])->all()];
            if ($buyerView) {
                $data += ['amount' => $order->amount !== null ? (float) $order->amount : null, 'currency' => $order->currency, 'evidence_id' => $order->evidence_id];
            }

            return $data;
        })->all();
        $actions = [];
        if ($buyerView && ! in_array($status, ['cancelled', 'received'], true)) {
            $actions = ['needs_info', 'hold', 'out_of_stock', 'eta', 'contact'];
            if (! $orderedAny) {
                $actions[] = 'review';
                $actions[] = 'resolve';
            }
            if (! $orderedAll) {
                $actions[] = 'order';
            }
            if ($status === 'ordered') {
                $actions[] = 'supplier_confirm';
            }
            if ($orderedAny && collect($lines)->sum('remaining_to_receive') > 0) {
                $actions[] = 'receive';
            }
            if (! $receivedAny) {
                $actions[] = 'cancel';
            }
        }
        if (PurchaseAccess::canRequest($user) && $row->requested_by_id === $user->id && $row->status === 'needs_info') {
            $actions[] = 'clarify';
        }
        $result = ['id' => $row->id, 'version' => $row->version, 'status' => $status, 'status_label' => PurchaseRequest::STATUSES[$status] ?? $status,
            'site_id' => $row->site_id, 'site_name' => $row->site?->name, 'site_address' => $row->site?->address, 'requester_name' => $row->requester?->name,
            'requested_by_id' => $row->requested_by_id, 'need_by' => $row->need_by?->toDateString(), 'note' => $row->note,
            'eta' => $row->eta?->toDateString(), 'reason' => $row->reason, 'lines' => $lines, 'orders' => $orders,
            'title' => $lines[0]['name'] ?? '구매 요청', 'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(), 'can_buy' => $buyerView, 'actions' => array_values(array_unique($actions)),
            'events' => $row->events->filter(fn ($e): bool => $buyerView || ! ($e->action === 'attachment' && $row->attachments->firstWhere('id', $e->data['attachment_id'] ?? null)?->purpose === 'order'))
                ->map(fn ($e): array => ['id' => $e->id, 'action' => $e->action, 'status' => $e->status, 'message' => $e->message, 'created_at' => $e->created_at?->toIso8601String()])->values()->all(),
            'attachments' => $row->attachments->filter(fn ($a): bool => $buyerView || $a->purpose === 'request')->map(fn ($a): array => $this->attachmentRow($a))->values()->all(),
        ];
        if ($buyerView) {
            $currencies = $row->orders->pluck('currency')->unique();
            $result['amount'] = $row->orders->isNotEmpty() && $currencies->count() === 1 && $row->orders->every(fn ($o): bool => $o->amount !== null) ? (float) $row->orders->sum('amount') : null;
            $result['currency'] = $currencies->count() === 1 ? $currencies->first() : null;
            $result['vendor'] = $row->orders->pluck('vendor')->unique()->implode(', ');
            $result['order_number'] = $row->orders->pluck('order_number')->implode(', ');
        }

        return $result;
    }

    private function order(PurchaseRequest $row, array $data, User $user, array $state): PurchaseRequestOrder
    {
        abort_unless(filled($data['vendor'] ?? null) && filled($data['order_number'] ?? null), 422, '주문번호와 구매처를 확인하세요.');
        abort_if($row->orders()->whereRaw('lower(vendor) = ?', [mb_strtolower(trim($data['vendor']))])
            ->whereRaw('lower(order_number) = ?', [mb_strtolower(trim($data['order_number']))])->exists(), 409, '이미 등록된 주문입니다.');
        $evidence = ! empty($data['analysis_job_id']) ? $this->attachAnalysis($row, (int) $data['analysis_job_id'], $user, 'order') : null;
        $evidence ??= $row->attachments()->where('purpose', 'order')
            ->when(! empty($data['evidence_id']), fn ($q) => $q->whereKey($data['evidence_id']),
                fn ($q) => $q->whereNotIn('id', $row->orders()->select('evidence_id')))
            ->latest('id')->first();
        abort_unless($evidence && Storage::disk($evidence->disk)->exists($evidence->path), 422, '주문 확인서 또는 영수증을 첨부하세요.');
        $wanted = $data['order_lines'] ?? collect($state['lines'])->filter(fn ($l): bool => $l['remaining_to_order'] > 0)
            ->map(fn ($l): array => ['request_line_id' => $l['id'], 'quantity' => $l['remaining_to_order']])->values()->all();
        abort_if($wanted === [], 422, '남은 구매 수량이 없습니다.');
        foreach ($wanted as $line) {
            $source = collect($state['lines'])->firstWhere('id', (int) $line['request_line_id']);
            $quantity = round((float) $line['quantity'], 3);
            abort_unless($source && $quantity > 0 && $quantity <= $source['remaining_to_order'], 422, '요청 품목과 남은 구매 수량을 확인하세요.');
        }
        $vendor = app(VendorResolver::class)->resolve($data['vendor'], $row->company_id);
        $order = $row->orders()->create(['ordered_by_id' => $user->id, 'evidence_id' => $evidence->id,
            'vendor_id' => $vendor?->id, 'vendor' => $vendor?->name ?? trim($data['vendor']), 'order_number' => trim($data['order_number']), 'amount' => $data['amount'] ?? null,
            'currency' => $data['currency'] ?? 'USD', 'ordered_at' => now()]);
        foreach ($wanted as $line) {
            $order->lines()->create(['purchase_request_line_id' => $line['request_line_id'], 'quantity' => round((float) $line['quantity'], 3)]);
        }

        return $order;
    }

    private function receive(PurchaseRequest $row, array $data, User $user, array $state): array
    {
        abort_unless(! empty($data['receipt_id']) && ! empty($data['allocations']), 422, '확정 입고와 연결할 수량을 선택하세요.');
        $receipt = MaterialReceipt::whereKey($data['receipt_id'])->lockForUpdate()->first();
        abort_unless($receipt && $receipt->site_id === $row->site_id && $receipt->isConfirmed(), 422, '같은 현장의 확정된 입고만 연결할 수 있습니다.');
        $sourceIds = collect($data['allocations'])->pluck('receipt_line_id')->unique()->sort()->values();
        $sources = MaterialReceiptLine::where('material_receipt_id', $receipt->id)->whereIn('id', $sourceIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $sourceUsed = [];
        $requestUsed = [];
        foreach ($data['allocations'] as $allocation) {
            $source = $sources->get($allocation['receipt_line_id']);
            $target = collect($state['lines'])->firstWhere('id', (int) $allocation['request_line_id']);
            $qty = round((float) $allocation['quantity'], 3);
            abort_unless($source && $target && $qty > 0, 422, '입고 품목과 구매 품목을 확인하세요.');
            abort_unless(mb_strtolower(trim((string) $source->unit)) === mb_strtolower(trim($target['unit'])), 422, '입고 단위가 다릅니다. 같은 단위로 확인 후 연결하세요.');
            $sourceUsed[$source->id] = ($sourceUsed[$source->id] ?? (float) PurchaseReceiptAllocation::where('material_receipt_line_id', $source->id)->sum('quantity')) + $qty;
            // A temporarily unconfirmed receipt still reserves its allocation. Otherwise
            // confirming it again after another delivery would count the same requirement twice.
            $requestUsed[$target['id']] = ($requestUsed[$target['id']] ?? (float) PurchaseReceiptAllocation::where('purchase_request_line_id', $target['id'])->sum('quantity')) + $qty;
            abort_unless(round($sourceUsed[$source->id], 3) <= (float) $source->quantity && round($requestUsed[$target['id']], 3) <= $target['ordered_quantity'], 422, '이미 연결한 입고 또는 구매 잔량을 초과합니다.');
        }
        foreach ($data['allocations'] as $allocation) {
            $link = PurchaseReceiptAllocation::firstOrNew(['purchase_request_line_id' => $allocation['request_line_id'], 'material_receipt_line_id' => $allocation['receipt_line_id']]);
            $link->quantity = round((float) ($link->quantity ?? 0) + (float) $allocation['quantity'], 3);
            $link->allocated_by_id = $user->id;
            $link->save();
        }

        return ['receipt_id' => $receipt->id, 'allocations' => $data['allocations']];
    }

    private function attachAnalysis(PurchaseRequest $row, int $jobId, User $user, string $mode): ?PurchaseRequestAttachment
    {
        $job = AiJob::whereKey($jobId)->lockForUpdate()->first();
        abort_unless($job && $job->kind === 'purchase_draft' && $job->status === 'done' && $job->user_id === $user->id
            && (int) ($job->params['site_id'] ?? 0) === $row->site_id && ($job->params['mode'] ?? null) === $mode, 422, '이 요청에서 사용할 수 없는 분석 결과입니다.');
        if ($mode === 'order') {
            abort_unless(in_array($job->result['doc_kind'] ?? null, ['purchase_order', 'order_confirmation'], true), 422,
                '견적·청구·배송 자료만으로 구매완료를 확인할 수 없습니다. 주문 확인서를 첨부하세요.');
        }
        $source = $job->params['source'] ?? null;
        if (! is_array($source) || empty($source['path'])) {
            return null;
        }
        abort_unless(is_string($source['disk'] ?? null) && $source['disk'] !== 'public'
            && is_string($source['path']) && str_starts_with($source['path'], 'purchase-analysis/'.$user->id.'/')
            && Storage::disk($source['disk'])->exists($source['path']), 422, '분석 원본을 확인할 수 없습니다. 다시 첨부하세요.');

        return $row->attachments()->firstOrCreate(['purpose' => $mode, 'disk' => $source['disk'], 'path' => $source['path']], [
            'uploaded_by_id' => $user->id, 'name' => mb_substr(basename((string) ($source['name'] ?? 'source')), 0, 255),
            'mime' => $source['mime'] ?? 'application/octet-stream', 'size' => Storage::disk($source['disk'])->size($source['path']),
        ]);
    }

    private function event(PurchaseRequest $row, User $actor, string $action, string $message, array $data = [], ?string $key = null, ?string $fingerprint = null, bool $notify = true): void
    {
        $status = $this->serialize($row->fresh(), $actor)['status'];
        $row->events()->create(['actor_id' => $actor->id, 'action' => $action, 'status' => $status,
            'message' => $message, 'data' => $data, 'request_key' => $key, 'fingerprint' => $fingerprint]);
        if ($notify && $actor->id !== $row->requested_by_id && ($recipient = User::find($row->requested_by_id))) {
            $this->notify($row, $recipient, PurchaseRequest::STATUSES[$status].' · 구매 요청 #'.$row->id, $message);
        }
        if ($action === 'clarify') {
            $site = $row->site;
            User::query()->where('account_status', 'active')->where(function ($q): void {
                $q->where('purchase_buy_enabled', true)->orWhere('access_role', 'super_admin');
            })->get()->filter(fn (User $u): bool => $u->id !== $actor->id && PurchaseAccess::eligible($u) && PurchaseAccess::canUseSite($u, $site))
                ->each(fn (User $u) => $this->notify($row, $u, '요청 내용 보완 · #'.$row->id, '요청자가 확인할 내용을 보완했습니다.', true));
        }
    }

    private function notify(PurchaseRequest $row, User $recipient, string $title, string $body, bool $buyer = false): void
    {
        $url = $buyer ? '/?view=purchase-requests&request='.$row->id : '/attendance-app/purchase-requests?request='.$row->id;
        $notification = new CommunicationNotification;
        $notification->forceFill(['user_id' => $recipient->id, 'employee_id' => $recipient->employee_id,
            'type' => 'purchase_request', 'title' => mb_substr($title, 0, 255), 'body' => mb_substr($body, 0, 1000), 'action_url' => $url])->save();
        SendPurchaseRequestPush::dispatch($notification->id, $row->id, $buyer)->afterCommit();
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user && PurchaseAccess::eligible($user), 403, '활성 관리자 계정이 필요합니다.');

        return $user;
    }

    private function lineRules(bool $required = true): array
    {
        return ['lines' => ($required ? 'required' : 'sometimes').'|array|min:1|max:100',
            'lines.*.name' => 'required|string|max:255', 'lines.*.specification' => 'nullable|string|max:4000',
            'lines.*.quantity' => 'nullable|numeric|min:0.001|max:99999999999.999',
            'lines.*.unit' => 'nullable|string|max:32', 'lines.*.product_url' => 'nullable|url:http,https|max:2048'];
    }

    private function cleanLines(array $lines): array
    {
        return array_map(function (array $line): array {
            $name = trim($line['name']);
            $unit = trim((string) ($line['unit'] ?? ''));
            if ($name === '') {
                throw ValidationException::withMessages(['lines' => '품명과 단위를 입력하세요.']);
            }

            return ['name' => $name, 'specification' => trim((string) ($line['specification'] ?? '')) ?: null,
                'quantity' => isset($line['quantity']) ? round((float) $line['quantity'], 3) : null, 'unit' => $unit ?: null,
                'product_url' => trim((string) ($line['product_url'] ?? '')) ?: null];
        }, $lines);
    }

    private function relations(): array
    {
        return ['site:id,name,code,address', 'requester:id,name', 'lines.orderLines', 'lines.allocations.receiptLine.receipt', 'events', 'attachments', 'orders.lines'];
    }

    /** Read-only source records; matching a name is not proof of an interchangeable product. */
    private function context(PurchaseRequest $row, User $user): array
    {
        $names = $row->lines->pluck('name')->map(fn ($n): string => mb_strtolower(trim($n)))->unique()->values()->all();
        if ($names === []) {
            return ['items' => [], 'stock_records' => [], 'related_requests' => []];
        }
        $sites = PurchaseAccess::sites($user)->pluck('id');
        $items = Item::query()->whereIn(DB::raw('lower(trim(name))'), $names)
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $row->company_id))
            ->where('status', 'active')->limit(20)->get(['id', 'code', 'name', 'unit', 'description'])
            ->map(fn ($item): array => ['id' => $item->id, 'code' => $item->code, 'name' => $item->name, 'unit' => $item->unit, 'description' => $item->description])->all();
        $assets = Equipment::query()->with('activeRental')->whereIn('site_id', $sites)
            ->whereIn(DB::raw('lower(trim(model))'), $names)
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $row->company_id))
            ->limit(20)->get()->map(function (Equipment $asset) use ($sites): ?array {
                $currentSiteId = $asset->activeRental?->site_id ?: $asset->site_id;
                if (! $sites->contains($currentSiteId)) {
                    return null;
                }

                return ['id' => $asset->id, 'code' => $asset->equipment_code, 'name' => $asset->model,
                    'quantity' => $asset->quantity !== null ? (int) $asset->quantity : null,
                    'status' => $asset->status, 'site_id' => $currentSiteId,
                    'available_in_register' => ! $asset->activeRental && in_array($asset->status, ['대기중', '창고보관'], true)];
            })->filter()->values()->all();
        $related = PurchaseRequest::query()->whereKeyNot($row->id)->whereIn('site_id', $sites)->where('status', '!=', 'cancelled')
            ->whereHas('lines', fn ($q) => $q->whereIn(DB::raw('lower(trim(name))'), $names))
            ->with(['lines', 'site:id,name'])->latest('id')->limit(10)->get()->map(fn ($r): array => [
                'id' => $r->id, 'site_name' => $r->site?->name, 'title' => $r->lines->first()?->name,
                'need_by' => $r->need_by?->toDateString(),
            ])->all();

        $vendors = \App\Models\Vendor::query()->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $row->company_id))
            ->orderBy('name')->limit(50)->get(['id', 'name', 'phone', 'email', 'address', 'trade'])->toArray();

        return ['vendors' => $vendors, 'items' => $items, 'stock_records' => $assets, 'related_requests' => $related,
            'match_basis' => '이름 일치 · 규격과 사용 가능 수량 확인 필요'];
    }

    private function attachmentRow(PurchaseRequestAttachment $file): array
    {
        return ['id' => $file->id, 'name' => $file->name, 'purpose' => $file->purpose,
            'download_url' => '/purchase-requests/'.$file->purchase_request_id.'/attachments/'.$file->id.'/download'];
    }

    private function fingerprint(array $data): string
    {
        $sort = function (array $value) use (&$sort): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = $sort($item);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($sort($data), JSON_THROW_ON_ERROR));
    }
}
