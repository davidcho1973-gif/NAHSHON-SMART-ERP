<?php

namespace App\Services\Inventory;

use App\Models\Item;
use App\Models\MaterialReceipt;
use App\Models\MaterialReceiptLine;
use App\Models\Site;
use App\Models\User;
use App\Services\Vendors\VendorResolver;
use App\Support\AccessPolicy;
use Illuminate\Support\Facades\DB;

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

        $id = (int) ($patch['id'] ?? 0);
        $receipt = $id > 0
            ? MaterialReceipt::query()->visibleTo($user)->whereKey($id)->first()
            : new MaterialReceipt;

        if ($receipt === null) {
            return ['success' => false, 'error' => '입고 기록을 찾을 수 없습니다.'];
        }

        // 확정된 장은 그대로 둔다. 확정은 «이 숫자를 내가 봤다» 는 뜻이라, 뒤에서
        // 조용히 바뀌면 그 말이 거짓이 된다. 고치려면 먼저 확정을 푼다.
        if ($receipt->exists && $receipt->isConfirmed()) {
            return ['success' => false, 'error' => '확정된 입고는 수정할 수 없습니다. 먼저 «확정 해제» 하세요.'];
        }

        $site = $this->siteFor($patch['site_id'] ?? null, $siteId, $user);
        if ($site === null) {
            return ['success' => false, 'error' => '현장을 선택하세요.'];
        }

        $receivedOn = $this->date($patch['received_on'] ?? null) ?? now()->toDateString();

        $lines = $this->cleanLines(is_array($patch['lines'] ?? null) ? $patch['lines'] : []);
        if ($lines === []) {
            return ['success' => false, 'error' => '품목을 한 줄 이상 적어주세요. 수량이 없는 줄은 저장되지 않습니다.'];
        }

        $vendor = app(VendorResolver::class)->resolve((string) ($patch['vendor'] ?? ''), $site->company_id);

        DB::transaction(function () use ($receipt, $site, $receivedOn, $vendor, $patch, $lines, $userId): void {
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
                $receipt->created_by_id = $userId;
            }

            // 사진과 AI 판독 결과는 «왜 이 숫자냐» 의 근거다. 새로 올라온 것이 없으면
            // 예전 근거를 지우지 않는다 — 줄 하나 고쳤다고 근거가 사라지면 안 된다.
            if (is_array($patch['photo'] ?? null) && filled($patch['photo']['path'] ?? null)) {
                $receipt->photo_disk = (string) ($patch['photo']['disk'] ?? 'public');
                $receipt->photo_path = (string) $patch['photo']['path'];
                $receipt->photo_name = $this->text($patch['photo']['name'] ?? null);
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
        });

        return ['success' => true, 'id' => $receipt->id, 'status' => $receipt->status];
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

        $receipt = MaterialReceipt::query()->visibleTo($user)->whereKey($id)->first();
        if (! $receipt) {
            return ['success' => false, 'error' => '입고 기록을 찾을 수 없습니다.'];
        }

        if ($confirmed && $receipt->lines()->count() === 0) {
            return ['success' => false, 'error' => '품목이 없는 입고는 확정할 수 없습니다.'];
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

        return ['success' => true, 'id' => $receipt->id, 'status' => $receipt->status];
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

        $receipt = MaterialReceipt::query()->visibleTo($user)->whereKey($id)->first();
        if (! $receipt) {
            return ['success' => false, 'error' => '입고 기록을 찾을 수 없습니다.'];
        }
        if ($receipt->isConfirmed()) {
            return ['success' => false, 'error' => '확정된 입고는 삭제할 수 없습니다. 먼저 «확정 해제» 하세요.'];
        }

        $receipt->delete();

        return ['success' => true];
    }

    /** 현장 운영자면 기록·확정할 수 있다(출퇴근 수정·문서와 같은 등급). */
    private function canManage(?User $user): bool
    {
        return AccessPolicy::canManageSite($user);
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
        $id = is_numeric($wanted) ? (int) $wanted : $this->siteId((string) ($wanted ?: $fallbackSiteId));
        if ($id === null) {
            return null;
        }

        $site = Site::query()->whereKey($id)->first();
        if ($site === null) {
            return null;
        }

        // 현장 범위가 걸린 사람이 남의 현장에 입고를 적을 수는 없다.
        $allowed = collect($this->siteOptions($user))->pluck('value')->map(fn ($v): int => (int) $v);

        return $allowed->contains($site->id) ? $site : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function siteOptions(?User $user): array
    {
        $query = Site::query()->where('status', 'active');

        if ($user && ! in_array($user->access_role, AccessPolicy::SYSTEM_ROLES, true)
            && $user->access_scope !== 'all_sites') {
            match ($user->access_scope) {
                'company' => $user->allowed_company_id
                    ? $query->where('company_id', $user->allowed_company_id)
                    : $query->whereRaw('1 = 0'),
                'site', 'team' => $user->allowed_site_id
                    ? $query->whereKey($user->allowed_site_id)
                    : $query->whereRaw('1 = 0'),
                default => $query->whereRaw('1 = 0'),
            };
        }

        return $query->orderBy('name')->get(['id', 'code', 'name'])
            ->map(fn (Site $s): array => [
                'value' => $s->id,
                'label' => trim($s->code.' — '.$s->name, ' —'),
            ])->all();
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

        return $v !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }

    private function text(mixed $v): ?string
    {
        $v = is_scalar($v) ? trim((string) $v) : '';

        return $v !== '' ? $v : null;
    }
}
