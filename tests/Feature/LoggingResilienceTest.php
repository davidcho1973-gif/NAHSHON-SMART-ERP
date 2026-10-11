<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\CloudBootstrapper;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\Handler\SocketHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use RuntimeException;
use Tests\TestCase;

class LoggingResilienceTest extends TestCase
{
    private string $logStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logStorage = storage_path('framework/testing/logging-'.Str::uuid());
        mkdir($this->logStorage.'/logs', 0700, true);
        $this->app->useStoragePath($this->logStorage);

        // Match Cloud's injection before application providers register; no live socket.
        CloudBootstrapper::configureCloudLogging($this->app);
        config(['logging.default' => 'laravel-cloud-socket']);
        $this->app->bind(SocketHandler::class, fn () => new class('unix:///unused') extends SocketHandler
        {
            protected function write(LogRecord $record): void
            {
                throw new RuntimeException('Write timed-out: fake-transport-credential');
            }
        });
        (new AppServiceProvider($this->app))->register();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        gc_collect_cycles();
        (new Filesystem)->deleteDirectory($this->logStorage);
    }

    public function test_cloud_default_and_configured_stack_preserve_original_records_and_callbacks(): void
    {
        $callbackCalled = false;
        // A configured custom creator still works and its failure callback is retained.
        Log::extend('test-custom', function () use (&$callbackCalled) {
            $logger = new Logger('custom', [app(SocketHandler::class)]);
            $logger->setExceptionHandler(function () use (&$callbackCalled): void {
                $callbackCalled = true;
                throw new RuntimeException('Previous reporting callback failed');
            });

            return $logger;
        });
        config(['logging.channels.custom' => ['driver' => 'test-custom']]);
        (new AppServiceProvider($this->app))->register();
        config(['logging.channels.stack.channels' => ['laravel-cloud-socket']]);

        report(new RuntimeException('Original analysis failure'));
        Log::channel('stack')->warning('Original stack warning', ['document_id' => 42]);
        Log::channel('custom')->error('Original custom-channel failure');

        $records = array_map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($this->fallbackPath()));
        $this->assertCount(3, $records);
        $this->assertSame('Original analysis failure', $records[0]['context']['exception']['message']);
        $this->assertSame(42, $records[1]['context']['document_id']);
        $this->assertSame('Original custom-channel failure', $records[2]['message']);
        $this->assertTrue($callbackCalled);
        $this->assertSame(RuntimeException::class, $records[0]['extra']['logging_transport_failure']);
        $this->assertStringNotContainsString('fake-transport-credential', file_get_contents($this->fallbackPath()));
    }

    public function test_healthy_primary_preserves_level_filter_and_does_not_write_fallback(): void
    {
        $handler = new TestHandler('warning');
        $this->app->bind(SocketHandler::class, fn () => $handler);

        Log::info('Below threshold');
        Log::error('Original healthy error', ['document_id' => 42]);

        $this->assertCount(1, $handler->getRecords());
        $this->assertSame('Original healthy error', $handler->getRecords()[0]->message);
        $this->assertSame(42, $handler->getRecords()[0]->context['document_id']);
        $this->assertFileDoesNotExist($this->fallbackPath());
    }

    public function test_unwritable_fallback_uses_php_error_log_without_masking_original_error(): void
    {
        rmdir($this->logStorage.'/logs');
        file_put_contents($this->logStorage.'/logs', 'A file cannot hold a log directory');
        $errorLog = $this->logStorage.'/php-error.log';
        $previous = ini_set('error_log', $errorLog);

        try {
            report(new RuntimeException('Original failure despite two unavailable transports'));
        } finally {
            ini_set('error_log', $previous);
        }

        $this->assertStringContainsString('Original failure despite two unavailable transports', file_get_contents($errorLog));
        $this->assertStringNotContainsString('fake-transport-credential', file_get_contents($errorLog));
    }

    private function fallbackPath(): string
    {
        return $this->logStorage.'/logs/logging-fallback-'.date('Y-m-d').'.log';
    }
}
