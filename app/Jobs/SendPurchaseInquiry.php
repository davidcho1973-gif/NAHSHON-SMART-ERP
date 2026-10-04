<?php

namespace App\Jobs;

use App\Models\PurchaseRequestEvent;
use App\Models\User;
use App\Services\Procurement\PurchaseRequestService;
use App\Support\PurchaseAccess;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SendPurchaseInquiry implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $eventId) {}

    public function handle(PurchaseRequestService $service): void
    {
        $event = PurchaseRequestEvent::findOrFail($this->eventId);
        $data = $event->data;
        if (($data['delivery'] ?? '') !== 'queued') {
            return;
        }
        try {
            $user = User::findOrFail($event->actor_id);
            PurchaseAccess::assertBuyer($user);
            $row = $service->visible($event->purchase_request_id, $user);
            abort_if($row->status === 'cancelled', 422, '취소된 요청입니다.');
            $files = $row->attachments()->whereIn('id', $data['attachment_ids'])->get();
            $event->update(['data' => array_merge($data, ['delivery' => 'sending'])]);
            Mail::raw($data['body'], function ($mail) use ($data, $files, $user): void {
                $mail->to($data['to'])->subject($data['subject'])->replyTo($user->email, $user->name);
                foreach ($files as $file) {
                    $mail->attachData(Storage::disk($file->disk)->get($file->path), $file->name, ['mime' => $file->mime]);
                }
            });
            $event->update(['message' => '업체 이메일 전송 완료: '.$data['to'], 'data' => array_merge($data, ['delivery' => 'sent', 'sent_at' => now()->toIso8601String()])]);
        } catch (Throwable $e) {
            $event->update(['message' => '업체 이메일 전송 확인 필요: '.$data['to'], 'data' => array_merge($data, ['delivery' => 'failed'])]);
            report($e);
        }
    }
}
