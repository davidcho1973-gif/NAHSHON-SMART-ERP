<?php

namespace App\Mcp\Read;

use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ErpReadBoundary
{
    /** PostgreSQL enforces the promise, including accidental model/service writes. */
    public function run(Closure $read): mixed
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'pgsql' || $connection->transactionLevel() !== 0) {
            throw new LogicException('ERP MCP requires an independent PostgreSQL read-only transaction.');
        }

        return $connection->transaction(function () use ($connection, $read): mixed {
            $connection->statement('SET TRANSACTION READ ONLY');
            $connection->statement("SET LOCAL statement_timeout = '5000ms'");

            return $read();
        }, 1);
    }
}
