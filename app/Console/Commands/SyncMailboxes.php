<?php

namespace App\Console\Commands;

use App\Jobs\SyncMailboxJob;
use App\Models\MailboxConnection;
use Illuminate\Console\Command;

class SyncMailboxes extends Command
{
    protected $signature = 'mailboxes:sync';
    protected $description = 'Queue incremental synchronization for connected business mailboxes';

    public function handle(): int
    {
        MailboxConnection::query()->whereIn('status', ['active', 'error'])->pluck('id')
            ->each(fn (int $id) => SyncMailboxJob::dispatch($id));
        return self::SUCCESS;
    }
}
