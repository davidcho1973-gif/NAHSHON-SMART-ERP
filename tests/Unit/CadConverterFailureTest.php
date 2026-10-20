<?php

namespace Tests\Unit;

use App\Services\Drawings\CadConverter;
use PHPUnit\Framework\TestCase;

class CadConverterFailureTest extends TestCase
{
    public function test_untrusted_native_error_details_never_reach_the_browser(): void
    {
        $converter = new CadConverter;
        foreach (['not JSON /secret/file.dwg', '{"message":{"secret":"/private/file"}}', '{"message":"<script>alert(1)</script>"}', '{"message":"Unsupported /secret/customer.dwg"}'] as $stderr) {
            $failure = $converter->conversionFailure($stderr);
            $this->assertSame('conversion_failed', $failure['reason']);
            $this->assertStringNotContainsString('secret', $failure['message']);
            $this->assertStringNotContainsString('<script>', $failure['message']);
        }
    }

    public function test_conflicting_colors_and_unsupported_objects_have_actionable_messages(): void
    {
        $converter = new CadConverter;
        $colors = $converter->conversionFailure(json_encode(['message' => 'Conversion refused because an object could not be preserved: Error when trying to add the entry PRIVATE NAME to ACAD_COLOR|99']));
        $this->assertSame('conflicting_book_colors', $colors['reason']);
        $this->assertStringContainsString('별색', $colors['message']);
        $this->assertStringNotContainsString('PRIVATE NAME', $colors['message']);
        $unsupported = $converter->conversionFailure(json_encode(['message' => 'Proxy objects or dynamic block metadata cannot be preserved by the writer. Keep the original drawing.']));
        $this->assertSame('unsupported_objects', $unsupported['reason']);
        $this->assertStringContainsString('일반 2D 객체', $unsupported['message']);
    }
}
