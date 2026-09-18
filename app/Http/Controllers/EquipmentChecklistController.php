<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Services\Equipment\EquipmentChecklistService;
use App\Support\AppLocale;
use App\Support\QrSvg;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * 장비 QR 점검 — 폰 기본 카메라로 스티커를 찍으면 이 길로 들어온다.
 *
 * ── 왜 앱 안의 스캐너를 안 쓰나 ────────────────────────────────────────
 * 앱 안에서 QR 을 읽으려면 BarcodeDetector 가 필요한데 아이폰 사파리에는 없다.
 * 반면 <b>폰 기본 카메라</b>는 어느 기종이든 QR 을 읽고 주소를 연다. 출퇴근
 * 게이트 QR 이 이미 그 방식이고, 현장에서 그것이 되는 것을 확인했다.
 *
 * ── 왜 로그인을 요구하나 ───────────────────────────────────────────────
 * 이 기록의 값어치는 «누가 봤는가» 에 있다. 익명으로 받으면 사고 조사에서
 * 아무 소용이 없는 종이가 된다. 로그인이 안 돼 있으면 로그인시키고 <b>다시
 * 이 화면으로 돌려보낸다</b> — 로그인 뒤 홈으로 떨어뜨리면 그 사람은 장비 앞에
 * 서서 길을 잃는다.
 */
class EquipmentChecklistController extends Controller
{
    public function __construct(private readonly EquipmentChecklistService $checklists)
    {
    }

    public function show(Request $request, string $token): View|RedirectResponse
    {
        $equipment = Equipment::forQrToken($token);

        if (! $equipment) {
            return redirect()->route('attendance-app.index')
                ->with('attendance_error', '이 장비 QR 은 더 이상 쓰이지 않습니다. 반장에게 알려 주세요.');
        }

        if (! $request->user()) {
            // 로그인 뒤 여기로 돌아온다.
            $request->session()->put('url.intended', $request->fullUrl());

            return redirect()->route('login');
        }

        // 언어는 SetLocale 미들웨어가 이미 정했다(쿠키 → 가입 언어 → 배포 기본).
        // 여기서 다시 정하면 규칙이 두 벌이 되고, 두 벌은 언젠가 갈라진다.
        $lang = AppLocale::normalize(app()->getLocale()) ?? 'ko';

        return view('equipment-checklist.show', [
            'token' => $token,
            'lang' => $lang,
            'langPicked' => $request->cookie(AppLocale::COOKIE) !== null,
            'screen' => $this->checklists->screen($equipment, $request->user(), $lang),
        ]);
    }

    public function submit(Request $request, string $token): JsonResponse
    {
        $equipment = Equipment::forQrToken($token);

        if (! $equipment) {
            return response()->json(['success' => false, 'error' => '이 장비 QR 은 더 이상 쓰이지 않습니다.'], 404);
        }

        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'error' => '로그인이 필요합니다.'], 401);
        }

        $data = $request->validate([
            'stage' => ['required', 'in:pre_use,post_use'],
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.ok' => ['required', 'boolean'],
            'answers.*.note' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lang' => ['nullable', 'in:ko,en,es'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        /*
         * 폼으로 오는 답은 '1' / '0' 같은 <b>글자</b>다. 규칙을 가진 쪽(서비스)은
         * 진짜 참/거짓만 받는다 — 거기서 '0' 을 그냥 받으면 PHP 가 그것을 참으로
         * 읽어서, 「아니다」 를 누른 항목이 조용히 「맞다」 로 저장된다. 그 기록은
         * 화면상 멀쩡해서 눈으로는 절대 못 잡는다.
         *
         * 그래서 <b>여기서</b> 바꾼다. 바깥에서 들어온 것을 안쪽 타입으로 옮기는 일은
         * 경계의 몫이고, 규칙은 한 가지 모양만 알면 된다.
         */
        $answers = [];
        foreach ($data['answers'] as $itemId => $answer) {
            $answers[(string) $itemId] = [
                'ok' => filter_var($answer['ok'], FILTER_VALIDATE_BOOLEAN),
                'note' => $answer['note'] ?? null,
            ];
        }

        // 이상 항목 사진은 파일로 온다. 항목 번호를 그대로 칸 이름에 쓴다(photo_12).
        foreach ($request->allFiles() as $field => $file) {
            if (! str_starts_with($field, 'photo_')) {
                continue;
            }
            $itemId = substr($field, 6);
            if (! isset($answers[$itemId]) || ! $file->isValid()) {
                continue;
            }
            $answers[$itemId]['photo'] = $file->store('equipment-checks', 'public');
        }

        return response()->json($this->checklists->submit(
            $equipment,
            $user,
            $data['stage'],
            $answers,
            ['lat' => $data['lat'] ?? null, 'lng' => $data['lng'] ?? null, 'accuracy' => $data['accuracy'] ?? null],
            $data['lang'] ?? (AppLocale::normalize(app()->getLocale()) ?? 'ko'),
            $data['notes'] ?? null,
        ));
    }

    /**
     * 장비에 붙일 스티커 — 인쇄해서 <b>조작부 근처</b>에 붙인다.
     *
     * QR 밑에 장비 번호를 크게 같이 찍는다. 옥외 장비의 QR 은 흙과 기름으로
     * 금방 안 읽히는데, 그때 번호를 손으로 넣을 길이 없으면 스티커가 죽는 순간
     * 그 장비는 점검 대상에서 조용히 빠진다.
     */
    public function sticker(Request $request, Equipment $equipment): View
    {
        abort_unless($this->mayPrint($request), 403);

        $token = $equipment->ensureQrToken();
        $url = route('equipment-checklist.show', ['token' => $token]);

        return view('equipment-checklist.sticker', [
            'stickers' => [[
                'equipment' => $equipment,
                'url' => $url,
                'qr' => QrSvg::dataUri($url, 320),
            ]],
        ]);
    }

    /** 현장 장비 스티커를 한 장에 모아 인쇄한다 — 한 대씩 뽑으면 아무도 안 붙인다. */
    public function stickerSheet(Request $request): View
    {
        abort_unless($this->mayPrint($request), 403);

        $query = Equipment::query()->visibleTo($request->user())
            // 대량 자재(볼트 한 상자)는 개별 점검 대상이 아니다. 스티커를 붙일 몸통이 없다.
            ->where(fn ($q) => $q->where('is_bulk', false)->orWhereNull('is_bulk'))
            ->orderBy('equipment_code');

        if ($request->filled('site')) {
            $query->where('site_id', (int) $request->input('site'));
        }
        if ($request->filled('trade')) {
            $query->where('trade', (string) $request->input('trade'));
        }

        $stickers = $query->limit(300)->get()->map(function (Equipment $equipment): array {
            $token = $equipment->ensureQrToken();
            $url = route('equipment-checklist.show', ['token' => $token]);

            return ['equipment' => $equipment, 'url' => $url, 'qr' => QrSvg::dataUri($url, 320)];
        })->all();

        return view('equipment-checklist.sticker', ['stickers' => $stickers]);
    }

    /** 이상 사진 — 점검 기록에 붙은 것만, 볼 권한이 있는 사람에게만. */
    public function photo(Request $request, string $path): mixed
    {
        abort_unless($request->user() !== null, 403);
        abort_unless(str_starts_with($path, 'equipment-checks/'), 404);
        abort_unless(Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path);
    }

    private function mayPrint(Request $request): bool
    {
        return in_array($request->user()?->access_role, [
            'super_admin', 'admin', 'site_manager', 'safety_manager', 'hr_manager',
        ], true);
    }
}
