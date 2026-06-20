<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->foreignId('site_id')->nullable()->after('company_id')->constrained()->nullOnDelete();
            $table->string('email')->nullable()->after('name')->unique();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('employee_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('allowed_company_id')->nullable()->after('password')->constrained('companies')->nullOnDelete();
            $table->foreignId('allowed_site_id')->nullable()->after('allowed_company_id')->constrained('sites')->nullOnDelete();
            $table->foreignId('allowed_team_id')->nullable()->after('allowed_site_id')->constrained('teams')->nullOnDelete();
            $table->string('google_id')->nullable()->after('email')->unique();
            $table->string('access_role', 40)->default('worker')->after('password')->index();
            $table->string('access_scope', 40)->default('self')->after('access_role')->index();
            $table->string('account_status', 40)->default('active')->after('access_scope')->index();
            $table->timestamp('last_login_at')->nullable()->after('account_status');
            $table->text('access_notes')->nullable()->after('last_login_at');
        });

        DB::table('users')
            ->where('email', config('smart_company.admin_email', ''))
            ->update([
                'access_role' => 'super_admin',
                'access_scope' => 'all_sites',
                'account_status' => 'active',
                'email_verified_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['access_notes', 'last_login_at', 'account_status', 'access_scope', 'access_role']);
            $table->dropUnique(['google_id']);
            $table->dropColumn('google_id');
            $table->dropConstrainedForeignId('allowed_team_id');
            $table->dropConstrainedForeignId('allowed_site_id');
            $table->dropConstrainedForeignId('allowed_company_id');
            $table->dropConstrainedForeignId('employee_id');
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropUnique(['email']);
            $table->dropColumn('email');
            $table->dropConstrainedForeignId('site_id');
        });
    }
};
