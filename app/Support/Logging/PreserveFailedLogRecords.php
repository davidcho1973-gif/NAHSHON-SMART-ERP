<?php

namespace App\Support\Logging;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\FallbackGroupHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger as Monolog;
use Monolog\LogRecord;
use Throwable;

class PreserveFailedLogRecords
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();
        if (! $monolog instanceof Monolog) {
            return;
        }

        // Use direct handlers: resolving another Laravel channel here could return
        // to the same failed Cloud socket. Keep seven private, daily fallback files.
        $fallback = new FallbackGroupHandler([
            new RotatingFileHandler(storage_path('logs/logging-fallback.log'), 7, filePermission: 0600),
            new ErrorLogHandler,
        ]);
        $fallback->setFormatter(new JsonFormatter(includeStacktraces: true));
        $previous = $monolog->getExceptionHandler();

        $monolog->setExceptionHandler(static function (Throwable $error, LogRecord $record) use ($fallback, $previous): void {
            // Retain the original exception/context. Transport messages may contain
            // endpoint credentials, so record only the transport exception's type.
            $fallback->handle($record->with(extra: [
                ...$record->extra,
                'logging_transport_failure' => $error::class,
            ]));

            if ($previous !== null) {
                try {
                    $previous($error, $record);
                } catch (Throwable) {
                    // The record is already preserved; a reporting callback is not a job failure.
                }
            }
        });
    }
}
