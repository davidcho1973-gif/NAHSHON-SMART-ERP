<?php

use App\Support\FinanceChartOfAccounts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mobile_expenses')) {
            return;
        }

        Schema::table('mobile_expenses', function (Blueprint $table): void {
            if (! Schema::hasColumn('mobile_expenses', 'accounting_account')) {
                $table->string('accounting_account', 160)->nullable()->after('category')->index();
            }
        });

        DB::table('mobile_expenses')
            ->select(['id', 'category', 'class', 'description'])
            ->orderBy('id')
            ->get()
            ->each(function (object $expense): void {
                $account = FinanceChartOfAccounts::normalize(
                    (string) ($expense->class ?: $expense->category),
                    (string) $expense->description
                );
                $departmentClass = preg_match('/^\d{4}\s+/', (string) $expense->class) === 1
                    ? null
                    : $expense->class;

                DB::table('mobile_expenses')
                    ->where('id', $expense->id)
                    ->update([
                        'category' => $account,
                        'accounting_account' => $account,
                        'class' => $departmentClass,
                    ]);
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('mobile_expenses') || ! Schema::hasColumn('mobile_expenses', 'accounting_account')) {
            return;
        }

        Schema::table('mobile_expenses', function (Blueprint $table): void {
            $table->dropColumn('accounting_account');
        });
    }
};
