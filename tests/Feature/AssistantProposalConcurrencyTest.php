<?php

namespace Tests\Feature;

use App\Models\AssistantProposal;
use App\Models\Company;
use App\Models\IntelligentDocument;
use App\Models\OpsActionItem;
use App\Models\Site;
use App\Models\User;
use App\Services\Assistant\AssistantProposalService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Separate PostgreSQL connections exercise the confirmation locks, not just sequential retries. */
class AssistantProposalConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private function fixtures(): array
    {
        config(['ai_assistant.mutations_enabled' => true]);
        $company = Company::create(['code' => 'PROPOSAL-RACE', 'name' => 'Race fixture', 'status' => 'active']);
        $site = Site::create(['company_id' => $company->id, 'code' => 'PROPOSAL-RACE', 'name' => 'Race site', 'status' => 'active']);
        $actor = User::factory()->create(['access_role' => 'admin', 'access_scope' => 'all_sites', 'account_status' => 'active', 'allowed_company_id' => $company->id]);

        return [$actor, $site];
    }

    public function test_concurrent_confirmations_apply_one_record_and_return_one_saved_result(): void
    {
        [$actor, $site] = $this->fixtures();
        $preview = app(AssistantProposalService::class)->create($actor, AssistantProposalService::CREATE_TODO, $site->id, ['title' => 'Coordinate site drawing review']);
        $outputs = $this->race($actor, [$preview, $preview, $preview, $preview]);
        $this->assertCount(1, array_unique($outputs));
        $this->assertStringStartsWith('applied:', $outputs[0]);
        $this->assertDatabaseCount('ops_action_items', 1);
        $proposal = AssistantProposal::findOrFail($preview['id']);
        $this->assertSame(['created', 'applied'], array_column($proposal->audit_events, 'event'));
    }

    public function test_concurrent_proposals_for_one_original_allow_only_one_change(): void
    {
        [$actor, $site] = $this->fixtures();
        $todo = OpsActionItem::create(['site_id' => $site->id, 'kind' => 'todo', 'title' => 'Original drawing review', 'status' => 'open', 'is_blocker' => false]);
        $service = app(AssistantProposalService::class);
        $first = $service->create($actor, AssistantProposalService::UPDATE_TODO, $site->id, ['title' => 'First drawing revision'], $todo->id);
        $second = $service->create($actor, AssistantProposalService::UPDATE_TODO, $site->id, ['title' => 'Second drawing revision'], $todo->id);
        $outputs = $this->race($actor, [$first, $second]);
        $this->assertSame(1, count(array_filter($outputs, fn (string $output) => str_starts_with($output, 'applied:'))));
        $this->assertSame(1, count(array_filter($outputs, fn (string $output) => $output === 'denied:409')));
        $this->assertSame(1, AssistantProposal::where('status', 'applied')->count());
        $this->assertSame(1, AssistantProposal::where('status', 'stale')->count());
        $this->assertDatabaseCount('ops_action_items', 1);
        $winner = AssistantProposal::where('status', 'applied')->firstOrFail();
        $this->assertSame($winner->after_snapshot['title'], $todo->fresh()->title);
    }

    public function test_concurrent_daily_report_creation_reserves_one_canonical_day(): void
    {
        [$actor, $site] = $this->fixtures();
        $service = app(AssistantProposalService::class);
        $payload = ['report_date' => '2026-10-06', 'work_title' => 'Site coordination', 'work_today' => 'Reviewed drawings'];
        $first = $service->create($actor, AssistantProposalService::CREATE_DAILY_REPORT, $site->id, $payload);
        $second = $service->create($actor, AssistantProposalService::CREATE_DAILY_REPORT, $site->id, $payload);
        $outputs = $this->race($actor, [$first, $second]);
        $this->assertSame(1, count(array_filter($outputs, fn ($out) => str_starts_with($out, 'applied:'))), implode(', ', $outputs));
        $this->assertSame(1, count(array_filter($outputs, fn ($out) => $out === 'denied:409')));
        $this->assertDatabaseCount('daily_closing_reports', 1);
        $this->assertDatabaseHas('daily_closing_reports', ['status' => 'open', 'field_status' => 'draft', 'field_submitted_at' => null, 'closed_at' => null]);
        $this->assertDatabaseCount('report_dispatches', 0);
    }

    public function test_concurrent_receipt_registrations_keep_one_exact_pending_expense(): void
    {
        [$actor, $site] = $this->fixtures();
        $receiptDisk = 'assistant-receipt-race-'.Str::uuid();
        Storage::fake($receiptDisk);
        Storage::fake('public');
        $bytes = "%PDF-1.4\nSynthetic concurrency receipt";
        Storage::disk($receiptDisk)->put('safe-receipt.pdf', $bytes);
        $source = IntelligentDocument::create([
            'uuid' => (string) Str::uuid(), 'company_id' => $site->company_id, 'site_id' => $site->id,
            'uploaded_by' => $actor->id, 'owner_user_id' => $actor->id, 'access_level' => 'scope', 'confidentiality' => 'internal',
            'document_type' => 'receipt', 'ai_status' => 'ready', 'disk' => $receiptDisk, 'file_path' => 'safe-receipt.pdf',
            'original_file_name' => 'safe-receipt.pdf', 'stored_file_name' => 'safe-receipt.pdf', 'extension' => 'pdf',
            'mime_type' => 'application/pdf', 'file_size' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
        ]);
        $service = app(AssistantProposalService::class);
        $payload = ['source_document_id' => $source->id, 'description' => 'Reviewed receipt', 'amount' => '15.99',
            'currency' => 'USD', 'expense_date' => '2026-10-06', 'accounting_account' => '6601 Office Supplies', 'payment_type' => 'corporate'];
        $first = $service->create($actor, AssistantProposalService::CREATE_EXPENSE, $site->id, $payload);
        $second = $service->create($actor, AssistantProposalService::CREATE_EXPENSE, $site->id, $payload);
        $outputs = $this->race($actor, [$first, $second], Storage::disk($receiptDisk)->path(''), $receiptDisk);
        $this->assertSame(1, count(array_filter($outputs, fn ($out) => str_starts_with($out, 'applied:'))), implode(', ', $outputs));
        $this->assertSame(1, count(array_filter($outputs, fn ($out) => $out === 'denied:409')));
        $this->assertDatabaseCount('mobile_expenses', 1);
        $this->assertDatabaseCount('integrated_documents', 1);
        $this->assertDatabaseHas('mobile_expenses', ['source_ref' => 'document:'.$source->id, 'amount' => '15.99', 'status' => 'pending', 'paid_at' => null, 'reviewed_at' => null]);
        $this->assertSame(1, AssistantProposal::where('status', 'stale')->count());
    }

    private function race(User $actor, array $previews, ?string $receiptRoot = null, ?string $receiptDisk = null): array
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $connection = config('database.connections.pgsql');
        $env = [
            'APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) $connection['host'],
            'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => (string) $connection['database'],
            'DB_USERNAME' => (string) $connection['username'], 'DB_PASSWORD' => (string) $connection['password'],
            'DB_URL' => '', 'ANTHROPIC_API_KEY' => '', 'GEMINI_API_KEY' => '',
            'ASSISTANT_TEST_RECEIPT_ROOT' => $receiptRoot ?? '',
            'ASSISTANT_TEST_RECEIPT_DISK' => $receiptDisk ?? '',
        ];
        $script = <<<'CODE'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['ai_assistant.mutations_enabled' => true]);
if (getenv('ASSISTANT_TEST_RECEIPT_ROOT')) {
    config(['filesystems.disks.'.getenv('ASSISTANT_TEST_RECEIPT_DISK') => ['driver' => 'local', 'root' => getenv('ASSISTANT_TEST_RECEIPT_ROOT'), 'throw' => true]]);
}
Illuminate\Support\Facades\Http::preventStrayRequests();
$actor = App\Models\User::findOrFail((int) $argv[1]);
$preview = json_decode(base64_decode($argv[2]), true, 512, JSON_THROW_ON_ERROR);
while (microtime(true) < (float) $argv[3]) { usleep(1000); }
try {
    $result = app(App\Services\Assistant\AssistantProposalService::class)->confirm($actor, $preview['id'], $preview['preview_token'], $preview['version'], true);
    echo 'applied:'.hash('sha256', json_encode($result));
} catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
    echo 'denied:'.$e->getStatusCode();
}
CODE;
        $startAt = (string) (microtime(true) + 2);
        $processes = [];
        try {
            foreach ($previews as $preview) {
                $process = new Process([PHP_BINARY, '-r', $script, (string) $actor->id,
                    base64_encode(json_encode($preview, JSON_THROW_ON_ERROR)), $startAt], base_path(), $env);
                $process->setTimeout(30)->start();
                $processes[] = $process;
            }
            $outputs = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $outputs[] = trim($process->getOutput());
            }

            return $outputs;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }
}
