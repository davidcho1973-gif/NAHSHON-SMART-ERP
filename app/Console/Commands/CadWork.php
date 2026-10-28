<?php

namespace App\Console\Commands;

use App\Services\Drawings\CadConversionBudget;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CadWork extends Command
{
    protected $signature = 'cad:work {--once : Process at most one queued conversion}';

    protected $description = 'Run CAD conversions on dedicated compute (minimum 4 GiB)';

    public function handle(CadConversionBudget $budget): int
    {
        $memory = $budget->memory();
        if (! $memory || $memory['limit'] < config('cad.worker_min_memory_mib') * 1048576) {
            $this->error('CAD worker needs a measured, isolated container with at least 4 GiB. Do not run on the ERP app cluster.');

            return self::FAILURE;
        }
        $root = storage_path('app/private/cad-worker');
        File::ensureDirectoryExists($root, 0700);
        $lock = fopen($root.'/worker.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            $this->error('A CAD worker already owns this container. Configure one process per cluster.');

            return self::FAILURE;
        }
        try {
            // SIGKILL cannot run finally(). Only this worker's old UUID scratch
            // folders are reclaimed; never traverse links or ERP document temp.
            $resolvedRoot = realpath($root).DIRECTORY_SEPARATOR;
            foreach (File::directories($root) as $path) {
                $resolved = realpath($path);
                if (! is_link($path) && $resolved && str_starts_with($resolved, $resolvedRoot) &&
                    preg_match('/^[a-f0-9-]{36}$/D', basename($path)) && filemtime($path) < time() - 3600) {
                    File::deleteDirectory($resolved);
                }
            }
            // Process-local capability, never an environment flag: ordinary ERP
            // queue workers cannot accidentally start the native conversion.
            config(['cad.worker_active' => true, 'cad.converter_heap_mib' => config('cad.worker_heap_mib'),
                'cad.converter_timeout' => config('cad.worker_timeout')]);
            $this->info(json_encode(['memoryLimitMiB' => round($memory['limit'] / 1048576), 'usedMiB' => round($memory['used'] / 1048576), 'heapMiB' => round($budget->heapBytes() / 1048576)]));

            return $this->call('queue:work', ['connection' => 'cad-conversion', '--queue' => 'cad-conversion',
                '--tries' => 1, '--timeout' => 660, '--sleep' => 3, '--memory' => 512,
                '--max-jobs' => 1, '--max-time' => 3600, '--once' => $this->option('once')]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
