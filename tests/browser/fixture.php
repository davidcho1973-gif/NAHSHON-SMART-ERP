<?php

/** Synthetic local CI only. Never register this helper as a route, command, or production seeder. */

use App\Models\AssistantProposal;
use App\Models\Company;
use App\Models\IntelligentDocument;
use App\Models\OpsActionItem;
use App\Models\Site;
use App\Models\User;
use App\Models\WbsItem;
use App\Services\Assistant\AssistantProposalService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

// Check before Laravel boots or touches a database, including cached configuration.
check(getenv('ERP_BROWSER_SYNTHETIC') === '1', 'Set ERP_BROWSER_SYNTHETIC=1 only for synthetic local CI.');
check(getenv('APP_ENV') === 'testing', 'Only APP_ENV=testing is allowed.');
check(getenv('APP_URL') === 'http://127.0.0.1:8765', 'Only the fixed loopback application URL is allowed.');
check(getenv('DB_CONNECTION') === 'pgsql' && getenv('DB_HOST') === '127.0.0.1'
    && getenv('DB_DATABASE') === 'erp_assistant_browser_test' && ! getenv('DB_URL'), 'Only the disposable browser PostgreSQL database is allowed.');
check(! is_file(__DIR__.'/../../bootstrap/cache/config.php'), 'Refuse a cached application configuration.');
foreach (['OPENAI_API_KEY', 'GEMINI_API_KEY', 'ANTHROPIC_API_KEY', 'GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET',
    'MICROSOFT_MAIL_CLIENT_ID', 'MICROSOFT_MAIL_CLIENT_SECRET', 'GRAPH_MAIL_CLIENT_ID', 'GRAPH_MAIL_CLIENT_SECRET',
    'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_SESSION_TOKEN', 'POSTMARK_API_KEY', 'RESEND_API_KEY',
    'SLACK_BOT_USER_OAUTH_TOKEN', 'TELEGRAM_BOT_TOKEN', 'VAPID_PRIVATE_KEY'] as $name) {
    check(! getenv($name), "Provider credentials must be absent: {$name}");
}

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
// Laravel's console rendering is not an exit-code contract for a standalone script.
set_exception_handler(function (Throwable $error): void {
    fwrite(STDERR, get_class($error).': '.$error->getMessage().PHP_EOL);
    exit(1);
});
check($app->environment('testing') && config('app.url') === 'http://127.0.0.1:8765', 'Application configuration is not synthetic.');
check(config('database.default') === 'pgsql' && config('database.connections.pgsql.host') === '127.0.0.1'
    && config('database.connections.pgsql.database') === 'erp_assistant_browser_test'
    && ! config('database.connections.pgsql.url'), 'Database configuration is not synthetic.');
check(config('mail.default') === 'array' && config('queue.default') === 'database', 'Mail/queue must remain local and unprocessed.');
foreach (['openai.api_key', 'gemini.api_key', 'anthropic.api_key', 'google.client_secret'] as $key) {
    check(! config('services.'.$key), 'Provider key unexpectedly configured.');
}
check(config('ai_assistant.mutations_enabled') && config('ai_assistant.checks_enabled'), 'Synthetic test flags are required.');
Http::preventStrayRequests();
Mail::fake();

$command = $argv[1] ?? 'guard';
$fixturePath = storage_path('app/erp-browser-synthetic.json');
function baseline(): string
{
    return hash('sha256', json_encode([
        DB::table('wbs_items')->orderBy('id')->get(),
        DB::table('intelligent_documents')->orderBy('id')->get(),
    ], JSON_THROW_ON_ERROR));
}

if ($command === 'guard') {
    echo "Synthetic environment guard passed.\n";
    exit(0);
}

if ($command === 'seed') {
    check(! User::query()->exists() && ! Company::query()->exists(), 'Seed requires a new, empty database; it never deletes existing data.');
    $fixture = DB::transaction(function (): array {
        $companies = $sites = $wbs = [];
        foreach (['A', 'B'] as $key) {
            $companies[$key] = Company::create(['code' => 'SYNTHETIC-'.$key, 'name' => 'SYNTHETIC Company '.$key, 'status' => 'active']);
            $sites[$key] = Site::create(['company_id' => $companies[$key]->id, 'code' => 'SYNTHETIC-SITE-'.$key,
                'name' => 'SYNTHETIC Site '.$key, 'status' => 'active', 'timezone' => 'UTC']);
            $wbs[$key] = WbsItem::create(['company_id' => $companies[$key]->id, 'site_id' => $sites[$key]->id,
                'wbs_code' => 'SYNTHETIC-WBS-'.$key, 'project_code' => 'SYNTHETIC-PROJECT', 'level' => 'task',
                'name' => $key === 'A' ? '=HYPERLINK("https://example.invalid","SYNTHETIC formula")' : 'SYNTHETIC OTHER COMPANY DATA',
                'progress' => 10, 'planned_end' => now()->subDays(2)->toDateString()]);
        }
        $users = [];
        foreach (['owner' => 'super_admin', 'limited' => 'site_manager'] as $key => $role) {
            $user = new User;
            $user->forceFill(['name' => 'SYNTHETIC '.$key, 'email' => $key.'@example.invalid',
                'password' => 'SyntheticBrowserOnly-937!Password', 'password_set_at' => now(), 'email_verified_at' => now(),
                'account_status' => 'active', 'access_role' => $role, 'access_scope' => $key === 'owner' ? 'all_sites' : 'site',
                'allowed_company_id' => $companies['A']->id, 'allowed_site_id' => $sites['A']->id])->save();
            $user->companies()->attach($companies['A']);
            $users[$key] = $user;
        }
        $documents = [];
        foreach (['shared', 'private'] as $key) {
            $documents[$key] = IntelligentDocument::create(['uuid' => (string) Str::uuid(), 'company_id' => $companies['A']->id,
                'site_id' => $sites['A']->id, 'uploaded_by' => $users['limited']->id, 'owner_user_id' => $users['limited']->id,
                'access_level' => $key === 'private' ? 'private' : 'scope', 'confidentiality' => 'internal',
                'disk' => 'local', 'file_path' => 'synthetic/'.$key.'.pdf', 'original_file_name' => $key.'.pdf',
                'stored_file_name' => $key.'.pdf', 'extension' => 'pdf', 'mime_type' => 'application/pdf', 'file_size' => 0,
                'sha256' => hash('sha256', 'SYNTHETIC '.$key),
                'title' => 'SYNTHETIC '.strtoupper($key).' DOCUMENT', 'category' => 'general', 'document_type' => 'drawing',
                'ai_status' => 'ready', 'extracted_text' => 'SYNTHETIC '.strtoupper($key).' CONTENT']);
        }
        $todo = OpsActionItem::create(['site_id' => $sites['A']->id, 'kind' => 'todo', 'title' => 'SYNTHETIC stale original',
            'status' => 'open', 'is_blocker' => false]);
        $service = app(AssistantProposalService::class);
        $stale = $service->create($users['owner'], $service::UPDATE_TODO, $sites['A']->id,
            ['title' => 'SYNTHETIC stale forbidden overwrite'], $todo->id);
        $todo->update(['title' => 'SYNTHETIC concurrent edit retained']);
        // Build the normal immutable envelope at an earlier clock; do not tamper with its signed expiry.
        Carbon::setTestNow(now()->subMinutes(16));
        try {
            $expired = $service->create($users['owner'], $service::CREATE_TODO, $sites['A']->id,
                ['title' => 'SYNTHETIC expired must never exist']);
        } finally {
            Carbon::setTestNow();
        }

        return ['synthetic' => true, 'password' => 'SyntheticBrowserOnly-937!Password',
            'users' => array_map(fn ($u) => ['id' => $u->id, 'email' => $u->email], $users),
            'companies' => array_map(fn ($c) => $c->id, $companies), 'sites' => array_map(fn ($s) => $s->id, $sites),
            'wbs' => array_map(fn ($w) => $w->id, $wbs), 'documents' => array_map(fn ($d) => $d->id, $documents),
            'stale' => $stale['id'], 'expired' => $expired['id'], 'stale_record' => $todo->id];
    });
    $fixture['baseline'] = baseline();
    file_put_contents($fixturePath, json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "Seeded only synthetic users, two scopes, documents, WBS and proposal edge cases.\n";
    exit(0);
}

$fixture = json_decode(file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
check(($fixture['synthetic'] ?? false) === true, 'Missing synthetic fixture marker.');

if ($command === 'inspect') {
    echo json_encode(['todos' => OpsActionItem::query()->orderBy('id')->get(['id', 'site_id', 'title'])->toArray(),
        'proposals' => AssistantProposal::query()->orderBy('created_at')->get()->map(fn ($p) => [
            'id' => $p->id, 'status' => $p->status, 'events' => array_column($p->audit_events, 'event'),
        ])->all()], JSON_THROW_ON_ERROR);
    exit(0);
}

if ($command === 'assert-final') {
    check(hash_equals($fixture['baseline'], baseline()), 'Reports/proposals changed source WBS or document records.');
    $expected = ['SYNTHETIC concurrent edit retained', 'SYNTHETIC confirm once', 'SYNTHETIC lost response'];
    $actual = OpsActionItem::query()->pluck('title')->all();
    sort($expected);
    sort($actual);
    check($expected === $actual, 'Unexpected todo creation, duplication, cancellation, or stale overwrite.');
    foreach (AssistantProposal::query()->where('status', 'applied')->get() as $proposal) {
        check(count(array_filter($proposal->audit_events, fn ($e) => $e['event'] === 'applied')) === 1, 'Repeated apply audit event.');
    }
    check(AssistantProposal::findOrFail($fixture['expired'])->status === 'expired', 'Expired state not observed.');
    check(AssistantProposal::findOrFail($fixture['stale'])->status === 'stale', 'Stale state not observed.');
    check(DB::table('jobs')->count() === 0, 'Unexpected queued work.');
    echo "Final database assertions passed; only two explicitly confirmed synthetic todos were added.\n";
    exit(0);
}

if ($command === 'xlsx') {
    $path = realpath($argv[2] ?? '');
    $artifacts = realpath(base_path('test-results/assistant-browser'));
    check($path && $artifacts && str_starts_with($path, $artifacts.DIRECTORY_SEPARATOR), 'Only a local browser artifact can be read.');
    $book = IOFactory::load($path);
    $meta = $book->getSheetByName('Report');
    $records = $book->getSheetByName('Records');
    check($book->getSheetCount() === 2 && $meta && $records, 'Missing workbook provenance/records sheets.');
    check($meta->getCell('B2')->getValue() === (string) $fixture['companies']['A'], 'Export company mismatch.');
    check($meta->getCell('B3')->getValue() === (string) $fixture['sites']['A'], 'Export site mismatch.');
    check($meta->getCell('B4')->getValue() === 'wbs_items' && $meta->getCell('B6')->getValue() === '1', 'Export dataset/row mismatch.');
    check($records->getHighestDataRow() === 2, 'Export includes another scope.');
    $headers = $records->rangeToArray('A1:'.$records->getHighestDataColumn().'1')[0];
    $name = array_search('name', $headers, true);
    check($name !== false, 'Missing name column.');
    $cell = $records->getCell([$name + 1, 2]);
    check($cell->getDataType() === DataType::TYPE_STRING && str_starts_with($cell->getValue(), '=HYPERLINK'), 'Formula-looking text was interpreted as a formula.');
    check(! str_contains(json_encode($records->toArray()), 'OTHER COMPANY'), 'Export leaked another company.');
    $book->disconnectWorksheets();
    echo "Downloaded XLSX provenance, scope and formula-as-string checks passed.\n";
    exit(0);
}

throw new RuntimeException('Unknown fixture command.');
