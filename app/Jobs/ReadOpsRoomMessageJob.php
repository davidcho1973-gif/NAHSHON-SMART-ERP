<?php

namespace App\Jobs;

use App\Models\CommunicationMessage;
use App\Services\Ops\OpsRoomAutoReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 현장 상황실 메시지 자동 판독 — 응답 후(->afterResponse())에 처리한다.
 *
 * AI 판독은 수십 초 걸릴 수 있어, 글 올리는 동작 자체를 붙잡으면 안 된다.
 */
class ReadOpsRoomMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public int $messageId)
    {
    }

    public function handle(OpsRoomAutoReader $reader): void
    {
        $message = CommunicationMessage::with('room')->find($this->messageId);
        if (! $message) {
            return;
        }

        config(['services.gemini.timeout' => max(120, (int) config('services.gemini.timeout'))]);

        $reader->handle($message);
    }
}
