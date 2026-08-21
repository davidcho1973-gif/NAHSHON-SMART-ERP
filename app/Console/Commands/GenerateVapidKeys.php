<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;
use Throwable;

/**
 * 푸시 알림에 쓸 열쇠 한 쌍을 만든다.
 *
 * 이 열쇠는 배포마다 달라야 하고(고객사별로 별개), 한 번 정하면 바꾸지 않는다 —
 * 바꾸면 이미 등록된 모든 기기의 구독이 한꺼번에 죽는다.
 */
class GenerateVapidKeys extends Command
{
    protected $signature = 'push:keys';

    protected $description = '웹 푸시(VAPID) 공개키·비밀키를 새로 만들어 보여줍니다.';

    public function handle(): int
    {
        if (trim((string) config('services.webpush.private_key')) !== '') {
            $this->warn('이미 열쇠가 설정돼 있습니다.');
            $this->line('새 열쇠로 바꾸면 지금까지 등록된 모든 기기의 알림이 끊깁니다 —');
            $this->line('정말 바꿔야 할 때만 아래 값을 쓰세요.');
            $this->newLine();
        }

        try {
            $keys = VAPID::createVapidKeys();
        } catch (Throwable $e) {
            $this->error('열쇠를 만들지 못했습니다: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('아래 세 값을 이 배포의 환경변수에 넣고 배포하세요.');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('VAPID_SUBJECT=mailto:관리자이메일@회사.com');
        $this->newLine();
        $this->warn('VAPID_PRIVATE_KEY 는 비밀입니다 — 코드나 대화에 남기지 마세요.');

        return self::SUCCESS;
    }
}
