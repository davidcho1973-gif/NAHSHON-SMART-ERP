<?php

namespace App\Services\Drawings;

/** Native DWG expansion shares the web container, not PHP's memory_limit. */
class CadConversionBudget
{
    public function memory(): ?array
    {
        foreach ([['/sys/fs/cgroup/memory.max', '/sys/fs/cgroup/memory.current'],
            ['/sys/fs/cgroup/memory/memory.limit_in_bytes', '/sys/fs/cgroup/memory/memory.usage_in_bytes']] as [$limitPath, $usedPath]) {
            if (! is_readable($limitPath) || ! is_readable($usedPath)) {
                continue;
            }
            $limit = trim(file_get_contents($limitPath));
            $used = trim(file_get_contents($usedPath));
            if (ctype_digit($limit) && ctype_digit($used) && (float) $limit < PHP_INT_MAX / 2) {
                return ['limit' => (int) $limit, 'used' => (int) $used];
            }
        }

        return null;
    }

    public function heapBytes(): int
    {
        // Reserve native/runtime overhead separately from headroom for ERP traffic.
        $heap = max(32, min(4096, (int) config('cad.converter_heap_mib', 256))) * 1048576;
        if ($memory = $this->memory()) {
            $heap = min($heap, $memory['limit'] - $memory['used'] - 192 * 1048576);
        }

        return max(0, $heap);
    }

    public function pressured(): bool
    {
        $memory = $this->memory();

        return $memory !== null && $memory['used'] >= $memory['limit'] - 96 * 1048576;
    }

    public function environment(int $heap): array
    {
        // .NET environment values are hexadecimal, even without a 0x prefix.
        // Clear inherited per-heap settings, which take precedence over this cap.
        return ['DOTNET_GCHeapHardLimit' => dechex($heap), 'DOTNET_GCServer' => '0',
            'DOTNET_GCHeapHardLimitSOH' => false, 'DOTNET_GCHeapHardLimitLOH' => false,
            'DOTNET_GCHeapHardLimitPOH' => false, 'COMPlus_GCHeapHardLimitSOH' => false,
            'COMPlus_GCHeapHardLimitLOH' => false, 'COMPlus_GCHeapHardLimitPOH' => false,
            'DOTNET_GCHeapHardLimitSOHPercent' => false, 'DOTNET_GCHeapHardLimitLOHPercent' => false,
            'DOTNET_GCHeapHardLimitPOHPercent' => false, 'COMPlus_GCHeapHardLimitSOHPercent' => false,
            'COMPlus_GCHeapHardLimitLOHPercent' => false, 'COMPlus_GCHeapHardLimitPOHPercent' => false];
    }
}
