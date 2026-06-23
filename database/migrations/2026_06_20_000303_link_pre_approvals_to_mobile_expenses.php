<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_expenses', function (Blueprint $table): void {
            $table->foreignId('expense_pre_approval_id')
                ->nullable()
                ->after('employee_id')
                ->constrained('expense_pre_approvals')
                ->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('status');
            $table->foreignId('reviewed_by_user_id')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable()->after('reviewed_by_user_id');
            $table->timestamp('paid_at')->nullable()->after('rejection_reason');
            $table->foreignId('paid_by_user_id')->nullable()->after('paid_at')->constrained('users')->nullOnDelete();
            $table->string('payment_reference')->nullable()->after('paid_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('mobile_expenses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('expense_pre_approval_id');
            $table->dropConstrainedForeignId('reviewed_by_user_id');
            $table->dropConstrainedForeignId('paid_by_user_id');
            $table->dropColumn([
                'reviewed_at',
                'rejection_reason',
                'paid_at',
                'payment_reference',
            ]);
        });
    }
};
