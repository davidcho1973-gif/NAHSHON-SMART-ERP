<?php

namespace App\Console\Commands;

use App\Services\Assistant\AssistantCheckService;
use Illuminate\Console\Command;

class RunAssistantChecks extends Command
{
    protected $signature = 'assistant:check';

    protected $description = 'Run explicitly activated private ERP checks (no AI calls or external notifications)';

    public function handle(AssistantCheckService $checks): int
    {
        $this->info('Private checks completed: '.$checks->runDue());

        return self::SUCCESS;
    }
}
