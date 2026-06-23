<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payment_type', 40)->index(); // 'personal', 'corporate'
            $table->string('category', 80)->index(); // Backward-compatible accounting account label.
            $table->string('class', 80)->nullable(); // Department/class only, not an accounting account.
            $table->text('description');
            $table->decimal('amount', 14, 2)->index();
            $table->date('expense_date')->index();
            $table->string('receipt_path')->nullable();
            $table->json('ocr_data')->nullable();
            $table->string('status', 30)->default('pending')->index(); // 'draft', 'pending', 'approved', 'rejected'
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_expenses');
    }
};
