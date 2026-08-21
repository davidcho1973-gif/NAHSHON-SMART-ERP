<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 이 기기로 알림을 받겠다는 등록/해지.
 *
 * 브라우저가 발급한 구독 주소를 받아 둔다. 키(VAPID)가 없는 배포에서는 공개키가
 * 비어 나가고, 화면은 알림 버튼 자체를 감춘다 — 눌러도 아무 일 없는 버튼을
 * 보여주는 것이 가장 나쁘다.
 */
class PushSubscriptionController extends Controller
{
    /** 화면이 구독을 만들려면 서버의 공개키가 필요하다(비밀키는 절대 나가지 않는다). */
    public function key(): JsonResponse
    {
        $key = trim((string) config('services.webpush.public_key'));

        return response()->json([
            'available' => $key !== '' && trim((string) config('services.webpush.private_key')) !== '',
            'publicKey' => $key,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'string', 'max:40'],
        ]);

        $subscription = PushSubscription::remember($request->user(), $data, (string) $request->userAgent());

        return response()->json(['success' => true, 'id' => $subscription->id]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $endpoint = (string) $request->input('endpoint');

        if ($endpoint !== '') {
            PushSubscription::query()
                ->where('endpoint_hash', hash('sha256', $endpoint))
                ->where('user_id', $request->user()->id)
                ->delete();
        }

        return response()->json(['success' => true]);
    }
}
