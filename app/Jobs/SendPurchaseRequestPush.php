<?php

namespace App\Jobs;

use App\Models\CommunicationNotification;
use App\Models\PurchaseRequest;
use App\Services\Push\WebPushSender;
use App\Support\PurchaseAccess;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** The inbox row is durable; unavailable browser push must never roll back a purchase update. */
class SendPurchaseRequestPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 45;

    public function __construct(public int $notificationId, public int $requestId, public bool $buyer = false)
    {
        $this->onConnection('document-analysis')->onQueue('purchase-notifications');
    }

    public function handle(WebPushSender $sender): void
    {
        $notification = CommunicationNotification::with('user')->find($this->notificationId);
        $row = PurchaseRequest::with('site')->find($this->requestId);
        $user = $notification?->user;
        if (! $row || ! $user || ! PurchaseAccess::eligible($user) || ! PurchaseAccess::canUseSite($user, $row->site)
            || ($this->buyer ? ! ($user->access_role === 'super_admin' || $user->purchase_buy_enabled) : ! PurchaseAccess::canRequest($user))) {
            return;
        }
        $sender->sendToUsers([$user], [
            'title' => '구매 요청 업데이트', 'body' => '구매 요청 #'.$row->id.'의 변경 내용을 확인하세요.',
            'url' => $notification->action_url, 'tag' => 'purchase-request-'.$row->id,
        ]);
    }
}
