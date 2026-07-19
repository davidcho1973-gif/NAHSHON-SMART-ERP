<?php

namespace App\Providers;

use App\Models\Employee;
use App\Observers\EmployeePayrollProfileObserver;
use App\Services\Ocr\ClaudeOcrEngine;
use App\Services\Ocr\GeminiOcrEngine;
use App\Services\Ocr\OcrEngine;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 공통 OCR 엔진 선택: AI_OCR_ENGINE(config services.ai_ocr.engine) 가 'claude'/'gemini' 로
        // 지정되면 그대로, 미지정 시 ANTHROPIC_API_KEY 가 있으면 Claude, 없으면 Gemini 로 폴백.
        $this->app->bind(OcrEngine::class, function ($app): OcrEngine {
            $engine = strtolower(trim((string) config('services.ai_ocr.engine', '')));
            if ($engine === '') {
                $engine = ((string) config('services.anthropic.api_key') !== '') ? 'claude' : 'gemini';
            }

            return $engine === 'claude'
                ? $app->make(ClaudeOcrEngine::class)
                : $app->make(GeminiOcrEngine::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Provision a payroll wage profile whenever a new employee is created.
        Employee::observe(EmployeePayrollProfileObserver::class);
    }
}
