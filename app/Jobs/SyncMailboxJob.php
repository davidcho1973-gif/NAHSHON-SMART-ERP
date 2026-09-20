<?php

namespace App\Jobs;

use App\Models\MailboxConnection;
use App\Services\Mail\MailboxSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncMailboxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 3;

    public function __construct(public int $connectionId)
    {
        $this->onConnection('document-analysis')->onQueue('documents');
    }

    public function handle(MailboxSyncService $sync): void
    {
        $connection = MailboxConnection::query()->find($this->connectionId);
        if (! $connection || $connection->status === 'disconnected') {
            return;
        }
        $result = $sync->sync($connection);
        if ($result['more']) {
            static::dispatch($connection->id)->delay(now()->addSeconds(5));
        }
    }
}
