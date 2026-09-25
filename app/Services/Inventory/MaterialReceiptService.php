<?php

namespace App\Services\Inventory;

use App\Models\Item;
use App\Models\MaterialReceipt;
use App\Models\MaterialReceiptLine;
use App\Models\Site;
use App\Models\User;
use App\Services\Finance\MaterialReceiptExpenseConnector;
use App\Services\Finance\ReceiptClaimConnector;
use App\Services\Vendors\VendorResolver;
use App\Support\MaterialReceiptAccess;
use App\Support\MaterialReceiptUpload;
use App\Support\WorkerDeviceSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * 자재 입고 — 트럭이 왔고 무엇이 몇 개 왔는가.
 *
 * ── 왜 공정표를 묻지 않는가 ───────────────────────────────────────────
 * 조달 추적(`ProcurementService`)은 공정표 한 줄에 매달려 있다. 그래서 공정표에
 * 그 자재 줄이 없으면 <b>적을 자리 자체가 없다</b>. 그런데 물건은 공정표와 무관하게
 * 도착한다. 적을 자리가 없으면 사람은 종이에 적고, 종이는 ERP 로 오지 않는다.
 *
 * 여기서는 현장과 날짜만 있으면 적을 수 있다. 공정표는 나중에 붙일 수 있는 것이지
 * 기록의 <b>전제</b>가 아니다.
 *
 * ── 확정 전에는 장부가 아니다 ─────────────────────────────────────────
 * 사진에서 AI 가 읽은 값은 `draft` 로 들어온다. 사람이 확정해야 숫자가 된다.
 * 번진 글씨의 10 이 100 으로 읽히는 일은 반드시 생기고, 그게 조용히 재고와 원가가
 * 되면 나중에 아무도 그 숫자의 출처를 설명하지 못한다.
 */
class MaterialReceiptService
{
    /**
     * 입고 목록 + 이 사람이 할 수 있는 것.
     *
     * @return array<string, mixed>
     */
    public function list(string $siteId = 'ALL', ?string $from = null, ?string $to = null): array
    {
        $user = auth()->user();

        if (! $this->canManage($user)) {
            return ['success' => false, 'canManage' => false, 'sites' => [], 'items' => [], 'total' => 0,
                'draftCount' => 0, 'error' => '자재 입고를 조회할 권한이 없습니다.'];
        }

        $query = MaterialReceipt::query()
            ->visibleTo($user)
            ->with(['lines', 'site:id,code,name', 'createdBy:id,name', 'confirmedBy:id,name']);

        $resolved = $this->siteId($siteId);
        if ($resolved !== null) {
            $query->where('site_id', $resolved);
        }
        if ($this->date($from) !== null) {
            $query->whereDate('received_on', '>=', $this->date($from));
        }
        if ($this->date($to) !== null) {
            $query->whereDate('received_on', '<=', $this->date($to));
        }

        $rows = $query->orderByDesc('received_on')->orderByDesc('id')->limit(500)->get();

        return [
            'success' => true,
            'canManage' => $this->canManage($user),
            'sites' => $this->siteOptions($user),
            'items' => $rows->map(fn (MaterialReceipt $r) => $this->row($r))->all(),
            'total' => $rows->count(),
            'draftCount' => $rows->where('status', MaterialReceipt::STATUS_DRAFT)->count(),
        ];
    }

    /**
     * 입고 한 장을 저장한다(새로 만들거나 고친다).
     *
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    public function save(array $patch, string $siteId = 'ALL', ?int $userId = null): array
    {
        $user = auth()->user();
        if (! $this->canManage($user)) {
            return ['success' => false, 'error' => '자재 입고를 기록할 권한이 없습니다.'];
        }

        $site = $this->siteFor($patch['site_id'] ?? null, $siteId, $user);
        if ($site === null) {
            return ['success' => false, 'error' => '현장을 선택하세요.'];
        }

        $validator = Validator::make($patch, [
            'id' => 'nullable|integer|min:1', 'request_key' => 'nullable|uuid',
            'received_on' => 'nullable|date_format:Y-m-d', 'vendor' => 'nullable|string|max:160',
            'po_no' => 'nullable|string|max:80', 'delivery_no' => 'nullable|string|max:80',
            'note' => 'nullable|string|max:10000', 'photo' => 'nullable|array',
            'lines' => 'required|array|min:1|max:100', 'lines.*.name' => 'nullable|string|max:255',
            'lines.*.unit' => 'nullable|string|max:32', 'lines.*.note' => 'nullable|string|max:2000',
            'lines.*.quantity' => 'nullable|numeric|max:99999999999.999',
            'lines.*.unit_price' => 'nullable|numeric|min:0|max:999999999999.99',
        ]);
        if ($validator->fails()) {
            return ['success' => false, 'error' => $validator->errors()->first()];
        }
        $receivedOn = $this->date($patch['received_on'] ?? null) ?? now($site->timezone ?: config('app.timezone'))->toDateString();
        $lines = $this->cleanLines(is_array($patch['lines'] ?? null) ? $patch['lines'] : []);
        if ($lines === []) {
            return ['success' => false, 'error' => '품목을 한 줄 이상 적어주세요. 수량이 없는 줄은 저장되지 않습니다.'];
        }

        $id = (int) ($patch['id'] ?? 0);
        $requestKey = ! $id && filled($patch['request_key'] ?? null)
            ? $user->id.':'.strtolower((string) $patch['request_key']) : null;

        return DB::transaction(function () use ($id, $requestKey, $user, $site, $receivedOn, $patch, $lines): array {
            // Serialise a user's retried creates; the unique key is also a database backstop.
            if ($requestKey) {
                User::query()->whereKey($user->id)->lockForUpdate()->first();
                $existing = MaterialReceipt::query()->where('request_key', $requestKey)->first();
                if ($existing) {
                    return $existing->site_id === $site->id
                        ? ['success' => true, 'id' => $existing->id, 'status' => $existing->status, 'replayed' => true]
                        : ['success' => false, 'error' => '다른 현장에서 사용한 요청입니다. 새 입고로 등록해 주세요.'];
                }
            }
            $receipt = $id > 0
                ? MaterialReceipt::query()->visibleTo($user)->whereKey($id)->lockForUpdate()->first()
                : new MaterialReceipt;
            if (! $receipt) {
                return ['success' => false, 'error' => '입고 기록을 찾을 수 없습니다.'];
            }
            if ($receipt->exists && $receipt->isConfirmed()) {
                return ['success' => false, 'error' => '확정된 입고는 수정할 수 없습니다. 먼저 «확정 해제» 하세요.'];
            }
            if ($receipt->exists && filled($receipt->photo_path) && $receipt->site_id !== $site->id) {
                return ['success' => false, 'error' => '첨부 근거가 있는 입고의 현장은 변경할 수 없습니다.'];
            }
            $photo = null;
            if (! empty($patch['photo'])) {
                try {
                    $photo = MaterialReceiptUpload::resolve($patch['photo'], $user, $site);
                } catch (ValidationException $e) {
                    return ['success' => false, 'error' => collect($e->errors())->flatten()->first()];
                }
            }
            $vendor = app(VendorResolver::class)->resolve((string) ($patch['vendor'] ?? ''), $site->company_id);
            $receipt->fill([
                'company_id' => $site->company_id,
                'site_id' => $site->id,
                'received_on' => $receivedOn,
                'vendor' => $vendor?->name,
                'vendor_id' => $vendor?->id,
                'po_no' => $this->text($patch['po_no'] ?? null),
                'delivery_no' => $this->text($patch['delivery_no'] ?? null),
                'note' => $this->text($patch['note'] ?? null),
            ]);

            if (! $receipt->exists) {
                $receipt->status = MaterialReceipt::STATUS_DRAFT;
                $receipt->created_by_id = $user->id;
                $receipt->request_key = $requestKey;
            }

            // 사진과 AI 판독 결과는 «왜 이 숫자냐» 의 근거다. 새로 올라온 것이 없으면
            // 예전 근거를 지우지 않는다 — 줄 하나 고쳤다고 근거가 사라지면 안 된다.
            if ($photo) {
                $receipt->photo_disk = $photo['disk'];
                $receipt->photo_path = $photo['path'];
                $receipt->photo_name = $photo['name'];
            }
            if (is_array($patch['analysis'] ?? null) && $patch['analysis'] !== []) {
                $receipt->analysis = $patch['analysis'];
            }

            $receipt->save();

            // 줄은 통째로 다시 쓴다. 지운 줄·순서 바뀐 줄을 짝지어 맞추는 코드는
            // 길고, 틀리면 수량이 두 번 들어간다 — 한 장은 통째로 한 사실이다.
            $receipt->lines()->delete();
            foreach ($lines as $seq => $line) {
                $receipt->lines()->create($line + ['seq' => $seq]);
            }

            return ['success' => true, 'id' => $receipt->id, 'status' => $receipt->status];
        });
    }

    /**
     * 확정하거나(사람이 눈으로 봤다), 확정을 푼다.
     *
     * @return array<string, mixed>
     */
    public function confirm(int $id, bool $confirmed = true): array
    {
        $user = auth()->user();
        if (! $this->canManage($user)) {
            return ['success' => false, 'error' => '입고를 확정할 권한이 없습니다.'];
        }

        return DB::transaction(function () use ($user, $id, $confirmed): array {
            $receipt = MaterialReceipt::query()->visibleTo($user)->whereKey($id)->lockForUpdate()->first();
            if (! $receipt) {
                return ['success' => false, 'error' => '입고 기록을 찾을 수 없습니다.'];
            }

            if ($confirmed && $receipt->lines()->count() === 0) {
                return ['success' => false, 'error' => '품목이 없는 입고는 확정할 수 없습니다.'];
            }

            if ($receipt->isConfirmed() === $confirmed) {
                return ['success' => true, 'id' => $receipt->id, 'status' => $receipt->status];
            }

            // 이 송장으로 만든 반입 기록이 이미 확인(기성)됐으면 확정을 풀 수 없다 — 근거가 사라진다.
            if (! $confirmed && ($blocked = app(ReceiptClaimConnector::class)->blocksUnconfirm($receipt))) {
                return ['success' => false, 'error' => $blocked];
            }

            $receipt->forceFill($confirmed ? [
                'status' => MaterialReceipt::STATUS_CONFIRMED,
                'confirmed_by_id' => $user?->id,
                'confirmed_at' => now(),
            ] : [
                'status' => MaterialReceipt::STATUS_DRAFT,
                'confirmed_by_id' => null,
                'confirmed_at' => null,
            ])->save();

            // 확정된 입고는 회계 대기(경비 원장 pending)로, 풀린 입고는 거기서 빠진다.
            // 실패해도 확정은 살아야 한다 — 부가 목적지가 주 기능을 막으면 안 된다.
            $finance = null;
            $financeWarning = null;
            try {
                $finance = app(MaterialReceiptExpenseConnector::class)->sync($receipt);
            } catch (\Throwable $e) {
                report($e);
                $financeWarning = '입고는 확정됐지만 회계 대기 연결에 실패했습니다. 확정을 풀었다 다시 확정하거나 관리자에게 확인하세요.';
            }

            // 확정된 송장은 기성의 «반입» 기록(확인 대기)으로, 풀린 송장은 거기서 거둔다.
            // 역시 부가 목적지 — 실패해도 입고 확정은 산다.
            $claims = null;
            try {
                $claims = app(ReceiptClaimConnector::class)->sync($receipt);
            } catch (\Throwable $e) {
                report($e);
                $claims = ['created' => 0, 'unmatched' => 0, 'warning' => '입고는 확정됐지만 반입 기록 연결에 실패했습니다. 입고 화면에서 직접 연결하세요.'];
            }

            return ['success' => true, 'id' => $receipt->id, 'status' => $receipt->status,
                'finance' => $finance, 'financeWarning' => $financeWarning, 'claims' => $claims];
        });
    }

    /**
     * 입고 기록을 지운다. 확정된 것은 못 지운다 — 먼저 확정을 푼다.
     *
     * @return array<string, mixed>
     */
    public function delete(int $id): array
    {
        $user = auth()->user();
        if (! $this->canManage($user)) {
            return ['success' => false, 'error' => '입고를 삭제할 권한이 없습니다.'];
        }

        return DB::transaction(function () use ($user, $id): array {
            $receipt = MaterialReceipt::query()->visibleTo($user)->whereKey($id)->lockForUpdate()->first();
            if (! $receipt) {
                return ['success' => false, 'error' => '입고 기록을 찾을 수 없습니다.'];
            }
            if ($receipt->isConfirmed()) {
                return ['success' => false, 'error' => '확정된 입고는 삭제할 수 없습니다. 먼저 «확정 해제» 하세요.'];
            }

            $receipt->delete();

            return ['success' => true];
        });
    }

    /** 현장 운영자면 기록·확정할 수 있다(출퇴근 수정·문서와 같은 등급). */
    private function canManage(?User $user): bool
    {
        // The ERP adapter can invoke this service without the mobile route's PIN middleware.
        return MaterialReceiptAccess::canManage($user)
            && (! request()->hasSession() || ! WorkerDeviceSession::isDeviceOnly(request()));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(MaterialReceipt $r): array
    {
        $lines = $r->lines->map(function (MaterialReceiptLine $l): array {
            return [
                'id' => $l->id,
                'itemId' => $l->item_id,
                'name' => $l->name,
                'quantity' => (float) $l->quantity,
                'unit' => $l->unit,
                'unitPrice' => $l->unit_price !== null ? (float) $l->unit_price : null,
                'amount' => $l->amount(),
                'note' => $l->note,
            ];
        })->all();

        $amount = array_sum(array_map(fn (array $l): float => (float) ($l['amount'] ?? 0), $lines));
        $priced = array_filter($lines, fn (array $l): bool => $l['amount'] !== null);

        $analysis = is_array($r->analysis) ? $r->analysis : [];

        return [
            'id' => $r->id,
            'siteId' => $r->site_id,
            'site' => $r->site?->name ?? '',
            'siteCode' => $r->site?->code ?? '',
            'receivedOn' => $r->received_on?->toDateString(),
            'vendor' => $r->vendor ?? '',
            'vendorId' => $r->vendor_id,
            'poNo' => $r->po_no ?? '',
            'deliveryNo' => $r->delivery_no ?? '',
            'note' => $r->note ?? '',
            'status' => $r->status,
            'statusLabel' => $r->isConfirmed() ? '확정' : '확인 대기',
            'lines' => $lines,
            'lineCount' => count($lines),
            'quantityTotal' => round(array_sum(array_map(fn (array $l): float => $l['quantity'], $lines)), 3),
            // 단가가 하나도 없으면 «0 달러» 가 아니라 «모름» 이다.
            'amount' => $priced === [] ? null : round($amount, 2),
            'photoUrl' => filled($r->photo_path) ? route('material-receipts.file', ['receipt' => $r->id]) : null,
            'photoName' => $r->photo_name,
            'aiConfidence' => isset($analysis['confidence']) && is_numeric($analysis['confidence'])
                ? (float) $analysis['confidence'] : null,
            'aiSummary' => is_string($analysis['summary'] ?? null) ? $analysis['summary'] : null,
            'createdBy' => $r->createdBy?->name,
            'confirmedBy' => $r->confirmedBy?->name,
            'confirmedAt' => $r->confirmed_at?->format('Y-m-d H:i'),
        ];
    }

    /**
     * 저장할 수 있는 줄만 남긴다. 이름이 없거나 수량이 0 이하인 줄은 입고가 아니다.
     *
     * @param  array<int, mixed>  $raw
     * @return array<int, array<string, mixed>>
     */
    private function cleanLines(array $raw): array
    {
        $lines = [];

        foreach ($raw as $line) {
            if (! is_array($line)) {
                continue;
            }
            $name = $this->text($line['name'] ?? null);
            $qty = isset($line['quantity']) && is_numeric($line['quantity']) ? (float) $line['quantity'] : null;
            if ($name === null || $qty === null || $qty <= 0) {
                continue;
            }

            $lines[] = [
                'item_id' => $this->itemIdFor($line, $name),
                'name' => $name,
                'quantity' => $qty,
                'unit' => $this->text($line['unit'] ?? null),
                'unit_price' => isset($line['unit_price']) && is_numeric($line['unit_price'])
                    ? (float) $line['unit_price'] : null,
                'note' => $this->text($line['note'] ?? null),
            ];
        }

        return $lines;
    }

    /**
     * 품목 마스터에 <b>이미 있는</b> 이름이면 붙인다.
     *
     * 없으면 붙이지 않고 이름만 둔다 — 여기서 품목을 새로 만들면 AI 가 잘못 읽은
     * 글자("EMT 1/2\"" 가 "EMI 1/2")가 마스터에 영구히 남는다. 마스터는 사람이
     * 정하는 기준이지 판독 결과가 아니다.
     *
     * @param  array<string, mixed>  $line
     */
    private function itemIdFor(array $line, string $name): ?int
    {
        $given = is_numeric($line['item_id'] ?? null) ? (int) $line['item_id'] : null;
        if ($given && Item::query()->whereKey($given)->exists()) {
            return $given;
        }

        return Item::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($name))])
            ->value('id');
    }

    /** 이 사람이 입고를 적을 수 있는 현장들. */
    private function siteFor(mixed $wanted, string $fallbackSiteId, ?User $user): ?Site
    {
        return MaterialReceiptAccess::site($user, $wanted ?: $fallbackSiteId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function siteOptions(?User $user): array
    {
        return MaterialReceiptAccess::siteOptions($user);
    }

    private function siteId(string $siteId): ?int
    {
        $siteId = trim($siteId);
        if ($siteId === '' || in_array(strtoupper($siteId), ['ALL', 'GLOBAL'], true)) {
            return null;
        }
        if (is_numeric($siteId)) {
            return (int) $siteId;
        }

        return Site::query()->whereRaw('upper(code) = ?', [strtoupper($siteId)])->value('id');
    }

    private function date(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : null;

        return $v !== null && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $parts)
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $v : null;
    }

    private function text(mixed $v): ?string
    {
        $v = is_scalar($v) ? trim((string) $v) : '';

        return $v !== '' ? $v : null;
    }
}
