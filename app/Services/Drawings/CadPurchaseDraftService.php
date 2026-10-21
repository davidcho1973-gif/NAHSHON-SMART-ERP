<?php

namespace App\Services\Drawings;

use App\Models\CadDrawing;
use App\Models\CadPurchaseDraft;
use App\Services\Procurement\PurchaseRequestService;
use App\Support\PurchaseAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Reviewed CAD estimates enter the existing purchase-request service; never an order/receipt. */
class CadPurchaseDraftService
{
    public function __construct(private CadDrawingService $cad, private CadWorkflowService $workflow) {}

    public function basis(CadDrawing $drawing, int $revision, string $markupId): array
    {
        $this->cad->access(auth()->user(), $drawing);
        $source = $drawing->revisions()->where('revision', $revision)->firstOrFail();
        $mark = collect($source->markups ?? [])->firstWhere('id', $markupId);
        abort_unless($mark, 404);
        $measurement = $source->workspace['measurement'] ?? null;
        $quantity = null;
        $unit = null;
        if ($mark['kind'] === 'pin') {
            $quantity = 1;
            $unit = 'EA';
        } elseif ($measurement && in_array($mark['kind'], ['area', 'route'], true)) {
            $factor = $measurement['metersPerUnit'];
            $imperial = in_array($measurement['unit'], ['in', 'ft'], true);
            if ($imperial) {
                $factor /= .3048;
            }
            if ($mark['kind'] === 'route') {
                $quantity = 0;
                $points = $mark['points'];
                for ($i = 1; $i < count($points); $i++) {
                    $quantity += hypot($points[$i]['x'] - $points[$i - 1]['x'], $points[$i]['y'] - $points[$i - 1]['y']);
                }
                $quantity *= $factor;
                $unit = $imperial ? 'FT' : 'M';
            } else {
                $area = function (array $points): float {
                    $sum = 0;
                    $o = $points[0];
                    foreach ($points as $i => $p) {
                        $q = $points[($i + 1) % count($points)];
                        $sum += ($p['x'] - $o['x']) * ($q['y'] - $o['y']) - ($q['x'] - $o['x']) * ($p['y'] - $o['y']);
                    }

                    return abs($sum) / 2;
                };
                $quantity = ($area($mark['points']) - array_sum(array_map($area, $mark['holes'] ?? []))) * $factor * $factor;
                $unit = $imperial ? 'SF' : 'M2';
            }
        }

        return ['drawingId' => $drawing->id, 'revision' => $revision, 'markupId' => $markupId, 'kind' => $mark['kind'], 'description' => $mark['text'] ?: ($mark['label'] ?? $mark['symbol'] ?? ''), 'quantity' => $quantity, 'unit' => $unit,
            'measurement' => $measurement, 'notice' => '도면 참고 물량입니다. 구매 요청 수량은 규격·여유분·단위를 검토하여 별도로 확정하세요.'];
    }

    public function save(CadDrawing $drawing, array $input): array
    {
        $this->cad->access(auth()->user(), $drawing, true);
        abort_unless(PurchaseAccess::canRequest(auth()->user()), 403);
        PurchaseAccess::assertSite(auth()->user(), $drawing->site_id);
        $data = Validator::make($input, ['id' => 'required|uuid', 'version' => 'required|integer|min:0', 'revision' => 'required|integer|min:1', 'markupId' => 'required|string|max:80',
            'content' => 'required|array:need_by,note,lines', 'content.need_by' => 'nullable|date_format:Y-m-d', 'content.note' => 'nullable|string|max:8000', 'content.lines' => 'required|array|min:1|max:100',
            'content.lines.*' => 'required|array:name,specification,quantity,unit,product_url', 'content.lines.*.name' => 'required|string|max:255', 'content.lines.*.specification' => 'nullable|string|max:4000',
            'content.lines.*.quantity' => 'required|numeric|min:0.001|max:99999999999.999', 'content.lines.*.unit' => 'required|string|max:32', 'content.lines.*.product_url' => 'nullable|url:http,https|max:2048'])->validate();

        return DB::transaction(function () use ($drawing, $data): array {
            $drawing = CadDrawing::whereKey($drawing->id)->lockForUpdate()->firstOrFail();
            $location = $this->workflow->location($drawing, $data['revision'], $data['markupId']);
            $draft = CadPurchaseDraft::whereKey($data['id'])->lockForUpdate()->first();
            if ($draft) {
                abort_unless((int) $draft->user_id === (int) auth()->id() && $draft->cad_location_id === $location->id, 404);
                abort_if($draft->purchase_request_id, 409, '이미 구매 요청으로 제출한 초안입니다.');
                abort_unless($draft->version === (int) $data['version'], 409, '구매 초안이 변경되었습니다. 다시 열어 확인하세요.');
            } else {
                abort_unless((int) $data['version'] === 0, 409, '구매 초안을 찾을 수 없습니다.');
            }
            $content = $data['content'];
            $content['basis'] = $this->basis($drawing, $data['revision'], $data['markupId']);
            if ($draft) {
                $draft->update(['content' => $content, 'version' => $draft->version + 1]);
            } else {
                $draft = CadPurchaseDraft::create(['id' => $data['id'], 'cad_location_id' => $location->id, 'user_id' => auth()->id(), 'content' => $content, 'version' => 1]);
            }
            $this->workflow->event($drawing, $location, 'purchase_draft_saved', ['draftId' => $draft->id, 'version' => $draft->version]);

            return $this->workflow->draftRow($draft);
        });
    }

    public function submit(CadDrawing $drawing, CadPurchaseDraft $draft, int $version, bool $reviewed): array
    {
        $this->cad->access(auth()->user(), $drawing, true);
        abort_unless($reviewed, 422, '규격·수량·단위·필요일을 확인한 뒤 제출하세요.');
        abort_unless((int) $draft->user_id === (int) auth()->id() && (int) $draft->location->cad_drawing_id === (int) $drawing->id, 404);

        return DB::transaction(function () use ($drawing, $draft, $version): array {
            $drawing = CadDrawing::whereKey($drawing->id)->lockForUpdate()->firstOrFail();
            $draft = CadPurchaseDraft::whereKey($draft->id)->lockForUpdate()->firstOrFail();
            if ($draft->purchase_request_id) {
                return app(PurchaseRequestService::class)->detail($draft->purchase_request_id);
            }
            abort_unless($draft->version === $version, 409, '초안이 변경되었습니다. 다시 검토하세요.');
            $basis = $draft->content['basis'];
            $link = '/cad?drawing_id='.$drawing->id.'&revision='.$basis['revision'].'&markup_id='.rawurlencode($basis['markupId']);
            $result = app(PurchaseRequestService::class)->create(['site_id' => $drawing->site_id, 'request_key' => $draft->id, 'need_by' => $draft->content['need_by'] ?? null,
                'note' => ($draft->content['note'] ?? '')."\n도면 검토 근거: #".$drawing->id.' r'.$basis['revision'].' · '.$basis['markupId']."\n".$link, 'lines' => $draft->content['lines']]);
            $draft->update(['purchase_request_id' => $result['request']['id'], 'version' => $draft->version + 1]);
            $this->workflow->link($drawing, ['revision' => $drawing->revision, 'markupId' => $draft->location->markup_id, 'type' => 'purchase', 'targetId' => $draft->purchase_request_id]);
            $this->workflow->event($drawing, $draft->location, 'purchase_submitted', ['draftId' => $draft->id, 'requestId' => $draft->purchase_request_id]);

            return $result;
        });
    }
}
