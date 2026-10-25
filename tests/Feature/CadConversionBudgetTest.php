<?php

namespace Tests\Feature;

use App\Services\Drawings\CadConversionBudget;
use App\Services\Drawings\CadConverter;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class CadConversionBudgetTest extends TestCase
{
    public function test_container_headroom_limits_heap_and_accounts_for_erp_and_runtime(): void
    {
        config(['cad.converter_heap_mib' => 2048]);
        $budget = new class extends CadConversionBudget
        {
            public array $state = ['limit' => 512 * 1048576, 'used' => 160 * 1048576];

            public function memory(): ?array
            {
                return $this->state;
            }
        };
        $this->assertSame(160 * 1048576, $budget->heapBytes());
        $this->assertSame('a000000', $budget->environment($budget->heapBytes())['DOTNET_GCHeapHardLimit']);
        $this->assertFalse($budget->pressured());
        $budget->state['used'] = 450 * 1048576;
        $this->assertSame(0, $budget->heapBytes());
        $this->assertTrue($budget->pressured());
    }

    public function test_conversion_lock_prevents_two_native_children_and_is_reusable_after_failure(): void
    {
        $dir = storage_path('app/private/cad-tmp');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $held = fopen($dir.'/conversion.lock', 'c');
        flock($held, LOCK_EX);
        try {
            $this->assertFailure(429);
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }
        config(['cad.converter_binary' => null]);
        $this->assertFailure(503);
        $this->assertFailure(503); // The failed request released the lock.
    }

    public function test_memory_pressure_stops_child_cleans_partial_output_and_preserves_input(): void
    {
        $budget = new class extends CadConversionBudget
        {
            public function memory(): ?array
            {
                return null;
            }

            public function pressured(): bool
            {
                return true;
            }
        };
        $this->app->instance(CadConversionBudget::class, $budget);
        $temp = tempnam(sys_get_temp_dir(), 'cad-budget-');
        $fake = $temp.'.dll';
        file_put_contents($fake, '<?php
if ($argv[1] === "capabilities") { echo json_encode(["dwgRead"=>true,"dwgWrite"=>true]); exit; }
file_put_contents($argv[5], "partial"); sleep(30);');
        config(['cad.converter_binary' => $fake, 'cad.dotnet_binary' => PHP_BINARY]);
        try {
            $start = microtime(true);
            $this->assertFailure(422, '변환 메모리 한도');
            $this->assertLessThan(5, microtime(true) - $start);
            $this->assertSame([], glob(storage_path('app/private/cad-tmp/*/input.dwg')) ?: []);
            $this->assertSame([], glob(storage_path('app/private/cad-tmp/*/output.dxf')) ?: []);
        } finally {
            unlink($fake);
            unlink($temp);
        }
    }

    private function assertFailure(int $status, string $message = ''): void
    {
        try {
            app(CadConverter::class)->convert('AC1032 synthetic input', 'dwg', 'dxf');
            $this->fail('Conversion should have been refused.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame($status, $e->getStatusCode());
            if ($message !== '') {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }
}
