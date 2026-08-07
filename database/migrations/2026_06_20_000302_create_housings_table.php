<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 숙소 관리 (Housing) — Antigravity 임대/숙소 모듈
        Schema::create('housings', function (Blueprint $table): void {
            // 접근제어 스코프 컬럼 (AGENTS.md §6-3)
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 60)->unique();
            $table->string('name');
            $table->string('address')->nullable();
            $table->unsignedSmallInteger('beds')->default(0);
            $table->unsignedSmallInteger('occupied')->default(0);
            $table->decimal('monthly_rent', 12, 2)->nullable();
            $table->string('status', 30)->default('available')->index();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('housings');
    }
};
