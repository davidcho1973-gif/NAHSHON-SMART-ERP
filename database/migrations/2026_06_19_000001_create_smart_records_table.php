<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smart_records', function (Blueprint $table): void {
            $table->id();
            $table->string('module', 60)->index();
            $table->string('record_key', 100)->index();
            $table->string('name');
            $table->string('category')->nullable()->index();
            $table->string('site')->nullable()->index();
            $table->string('status')->nullable()->index();
            $table->decimal('amount', 14, 2)->nullable();
            $table->date('occurred_on')->nullable()->index();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['module', 'record_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_records');
    }
};
