<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('member_registrations') || ! Schema::hasTable('employees')) {
            return;
        }

        DB::table('member_registrations')
            ->whereNotNull('employee_id')
            ->orderBy('id')
            ->select([
                'id',
                'employee_id',
                'company_id',
                'site_id',
                'team_id',
                'email',
                'full_name',
                'nationality',
                'role',
                'trade',
                'visa_expires_on',
                'safety_training_expires_on',
            ])
            ->chunkById(100, function ($registrations): void {
                foreach ($registrations as $registration) {
                    DB::table('employees')
                        ->where('id', $registration->employee_id)
                        ->update([
                            'company_id' => $registration->company_id,
                            'site_id' => $registration->site_id,
                            'team_id' => $registration->team_id,
                            'email' => $registration->email ? strtolower($registration->email) : null,
                            'name' => $registration->full_name,
                            'nationality' => $registration->nationality,
                            'role' => $registration->role ?: $registration->trade,
                            'visa_expires_on' => $registration->visa_expires_on,
                            'safety_training_expires_on' => $registration->safety_training_expires_on,
                            'employment_status' => 'active',
                            'updated_at' => now(),
                        ]);
                }
            }, 'id');
    }

    public function down(): void
    {
        // Backfilled employee data is intentionally preserved.
    }
};
