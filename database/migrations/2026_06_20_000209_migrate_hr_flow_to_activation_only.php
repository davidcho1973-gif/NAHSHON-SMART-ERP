<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('member_registrations')
            ->where('onboarding_status', 'employee_registration')
            ->where('safety_training_status', 'completed')
            ->update(['onboarding_status' => 'safety_completed']);

        DB::table('member_registrations')
            ->where('onboarding_status', 'employee_registration')
            ->update(['onboarding_status' => 'interview_passed']);
    }

    public function down(): void
    {
        // Intentionally no-op: the old employee draft state cannot be safely reconstructed.
    }
};
