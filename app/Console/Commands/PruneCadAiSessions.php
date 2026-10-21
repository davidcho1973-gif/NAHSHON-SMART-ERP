<?php

namespace App\Console\Commands;

use App\Services\Drawings\CadAiSessionService;
use Illuminate\Console\Command;

class PruneCadAiSessions extends Command
{
    protected $signature = 'cad-ai:prune';

    protected $description = 'Redact expired explicitly shared CAD browser snapshots and command payloads';

    public function handle(CadAiSessionService $sessions): int
    {
        $this->info('Expired CAD AI sessions redacted: '.$sessions->prune());

        return self::SUCCESS;
    }
}
