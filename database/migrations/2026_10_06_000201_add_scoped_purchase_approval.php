<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table): void {
            $table->boolean('approval_required')->default(false);
            $table->string('approval_status')->nullable();
            $table->decimal('approved_budget', 15, 2)->nullable();
            $table->string('approval_currency', 3)->nullable();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by_id');
            $table->dropColumn(['approval_required', 'approval_status', 'approved_budget', 'approval_currency', 'approved_at']);
        });
    }
};
