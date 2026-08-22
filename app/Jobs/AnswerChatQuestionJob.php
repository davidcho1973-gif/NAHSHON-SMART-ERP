<?php

namespace App\Jobs;

use App\Models\CommunicationMessage;
use App\Services\Communication\ChatAssistant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 방에서 @AI 를 부른 글에 답한다 — 응답 후(->afterResponse())에 처리한다.
 *
 * 답을 만드는 데 몇십 초가 걸릴 수 있다. 그 사이 글쓰기가 붙잡혀 있으면 사람은
 * 전송이 실패한 줄 알고 같은 말을 두 번 올린다.
 *
 * 다시 시도하지 않는다(tries = 1). 실패한 질문을 재시도하면 사람이 이미 다른
 * 방법으로 해결한 뒤에 뒤늦게 답이 붙는다 — 그게 더 헷갈린다.
 */
class AnswerChatQuestionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public int $messageId)
    {
    }

    public function handle(ChatAssistant $assistant): void
    {
        $message = CommunicationMessage::with(['room', 'senderUser'])->find($this->messageId);

        if (! $message) {
            return;
        }

        $assistant->answer($message);
    }
}
