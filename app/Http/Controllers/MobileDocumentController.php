<?php

namespace App\Http\Controllers;

use App\Models\IntegratedDocument;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 폰으로 문서 올리기 — 도면·계약·시방서를 현장에서 그대로 문서함에 넣는다.
 *
 * 지금까지 문서 등록은 PC 문서함(SPA)에서만 됐다. 그런데 현장에 오는 서류는
 * 대부분 <b>현장에서</b> 손에 들어온다 — 원청이 건네준 도면, 업체가 두고 간
 * 시방서, 서명받은 계약서. 그것을 PC 앞에 앉을 때까지 들고 다니면, 대개
 * 들고 다니다 만다.
 *
 * 올리는 창구는 PC 와 같은 것(`/docs-api/upload`)을 쓴다. AI 분류·편철도 같다 —
 * 문이 하나 더 생겼을 뿐, 문서함은 여전히 한 곳이다.
 */
class MobileDocumentController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $siteId = $user?->employee?->site_id;

        return view('attendance-app.docs', [
            'user' => $user,
            'siteName' => $user?->employee?->site?->name,
            // 올린 것이 실제로 문서함에 들어갔는지 바로 보이게 — 올리고 나서
            // «들어갔나?» 를 확인할 데가 없으면 사람은 같은 것을 또 올린다.
            'recent' => $this->recent($user, $siteId),
        ]);
    }

    /**
     * 이 사람이 최근에 올린 것 — 자기가 올린 것만 본다.
     *
     * 폰 화면에서 남의 서류까지 보여 줄 이유가 없고, 문서함 전체는 권한이
     * 걸린 곳이라 여기서 흉내 내면 그 규칙이 두 곳으로 갈린다.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recent(?User $user, ?int $siteId): array
    {
        if (! $user) {
            return [];
        }

        return IntegratedDocument::query()
            ->where('uploaded_by_id', $user->id)
            ->when($siteId, fn ($q) => $q->where(fn ($w) => $w->whereNull('site_id')->orWhere('site_id', $siteId)))
            ->latest('id')
            ->limit(15)
            ->get()
            ->map(fn (IntegratedDocument $d): array => [
                'id' => $d->id,
                'name' => (string) ($d->title ?: $d->original_name ?: '이름 없는 문서'),
                'status' => (string) $d->status,
                'folder' => $d->folder_code ? $d->folderName() : '',
                'at' => $d->created_at?->format('m-d H:i'),
            ])
            ->all();
    }
}
