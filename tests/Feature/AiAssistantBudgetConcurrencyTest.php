<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Real PostgreSQL processes contend for the final company slot. No external calls. */
class AiAssistantBudgetConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public static function limits(): array
    {
        return ['company' => [1, 10], 'user' => [10, 1]];
    }

    #[DataProvider('limits')]
    public function test_concurrent_workers_cannot_both_consume_the_last_budget_slot(int $companyLimit, int $userLimit): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $company = Company::create(['code' => 'BUDGET-RACE', 'name' => 'Race fixture', 'status' => 'active']);
        $actor = User::factory()->create(['access_role' => 'admin', 'account_status' => 'active', 'allowed_company_id' => $company->id]);
        $connection = config('database.connections.pgsql');
        $env = [
            'APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) $connection['host'],
            'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => (string) $connection['database'],
            'DB_USERNAME' => (string) $connection['username'], 'DB_PASSWORD' => (string) $connection['password'],
            'DB_URL' => '', 'ANTHROPIC_API_KEY' => '', 'GEMINI_API_KEY' => '',
        ];
        $script = <<<'CODE'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['ai_assistant.enabled' => true, 'ai_assistant.companies' => [],
    'ai_assistant.company_daily_requests' => (int) $argv[4], 'ai_assistant.company_monthly_requests' => 10,
    'ai_assistant.user_daily_requests' => (int) $argv[5], 'ai_assistant.user_monthly_requests' => 10]);
$actor = App\Models\User::findOrFail((int) $argv[1]);
while (microtime(true) < (float) $argv[3]) { usleep(1000); }
try {
    app(App\Support\AiAssistantBudget::class)->run($actor, (int) $argv[2], 'document_ask',
        ['max_tokens' => 16, 'messages' => [['role' => 'user', 'content' => 'synthetic race']]],
        function () { usleep(50000); return 'synthetic answer'; });
    echo 'allowed';
} catch (DomainException $e) {
    echo 'denied';
}
CODE;
        $startAt = (string) (microtime(true) + 2);
        $processes = [];
        try {
            for ($i = 0; $i < 4; $i++) {
                $process = new Process([PHP_BINARY, '-r', $script, (string) $actor->id, (string) $company->id, $startAt, (string) $companyLimit, (string) $userLimit], base_path(), $env);
                $process->setTimeout(30)->start();
                $processes[] = $process;
            }
            $outputs = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $outputs[] = trim($process->getOutput());
            }
            $this->assertSame(1, count(array_filter($outputs, fn (string $output): bool => $output === 'allowed')), implode(', ', $outputs));
            $this->assertSame(3, count(array_filter($outputs, fn (string $output): bool => $output === 'denied')));
            $this->assertDatabaseCount('ai_assistant_requests', 1);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }
}
