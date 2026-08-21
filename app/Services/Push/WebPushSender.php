<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Collection;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * 폰이 주머니에 있어도 소식이 닿게 한다.
 *
 * 현장 작업자는 화면을 계속 보고 있지 않다. 긴급 지시가 방에 올라와도 앱을 열어야만
 * 보인다면 그 지시는 전달된 것이 아니다. 그래서 실시간 수신보다 <b>푸시가 먼저</b>다.
 *
 * 지키는 것:
 *  - 키(VAPID)가 없으면 조용히 꺼진다. 알림이 없다고 메시지 전송이 실패하면 안 된다.
 *  - 죽은 구독(앱 삭제·브라우저 데이터 삭제)은 보내다 404/410 이 오면 그 자리에서 지운다.
 *    안 지우면 매번 실패를 반복하며 발송이 느려진다.
 *  - 한 사람의 여러 기기(폰·태블릿·PC)에 모두 보낸다.
 */
class WebPushSender
{
    public function available(): bool
    {
        return trim((string) config('services.webpush.public_key')) !== ''
            && trim((string) config('services.webpush.private_key')) !== '';
    }

    /**
     * @param  Collection<int, User>|array<int, User>  $users
     * @param  array<string, mixed>  $payload  title/body/url/tag
     * @return int 실제로 보낸 기기 수
     */
    public function sendToUsers(iterable $users, array $payload): int
    {
        $ids = collect($users)->map(fn ($u): int => (int) ($u instanceof User ? $u->id : $u))->filter()->unique();

        if ($ids->isEmpty() || ! $this->available()) {
            return 0;
        }

        $subscriptions = PushSubscription::query()->whereIn('user_id', $ids->all())->get();

        return $this->send($subscriptions, $payload);
    }

    /**
     * @param  Collection<int, PushSubscription>  $subscriptions
     * @param  array<string, mixed>  $payload
     */
    public function send(Collection $subscriptions, array $payload): int
    {
        if ($subscriptions->isEmpty() || ! $this->available()) {
            return 0;
        }

        try {
            $webPush = new WebPush(['VAPID' => [
                'subject' => (string) config('services.webpush.subject'),
                'publicKey' => (string) config('services.webpush.public_key'),
                'privateKey' => (string) config('services.webpush.private_key'),
            ]]);
            $webPush->setReuseVAPIDHeaders(true);
        } catch (Throwable $e) {
            report($e); // 키 형식이 잘못된 경우 — 알림만 포기하고 나머지는 계속 간다.

            return 0;
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $byEndpoint = [];

        foreach ($subscriptions as $row) {
            try {
                $byEndpoint[$row->endpoint] = $row;
                $webPush->queueNotification(Subscription::create([
                    'endpoint' => $row->endpoint,
                    'publicKey' => $row->public_key,
                    'authToken' => $row->auth_token,
                    'contentEncoding' => $row->content_encoding ?: 'aes128gcm',
                ]), $body);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $sent = 0;
        $expired = [];

        try {
            foreach ($webPush->flush() as $report) {
                $endpoint = $report->getRequest()->getUri()->__toString();

                if ($report->isSuccess()) {
                    $sent++;

                    continue;
                }

                // 410 Gone / 404 Not Found = 이 기기의 구독은 죽었다.
                if ($report->isSubscriptionExpired() && isset($byEndpoint[$endpoint])) {
                    $expired[] = $byEndpoint[$endpoint]->id;
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        if ($expired !== []) {
            PushSubscription::query()->whereIn('id', $expired)->delete();
        }

        if ($sent > 0) {
            PushSubscription::query()
                ->whereIn('endpoint_hash', $subscriptions->pluck('endpoint_hash')->all())
                ->update(['last_used_at' => now()]);
        }

        return $sent;
    }
}
