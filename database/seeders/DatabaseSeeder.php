<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\SmartRecord;
use App\Models\User;
use App\Support\SmartCompanyData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => config('smart_company.admin_email', 'admin@nahshonmep.com')],
            [
                'name' => config('smart_company.admin_name', 'Admin User'),
                'password' => Hash::make(config('smart_company.admin_password', 'change-this-password')),
                'access_role' => 'super_admin',
                'access_scope' => 'all_sites',
                'account_status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        foreach (SmartCompanyData::seedRecords() as $record) {
            SmartRecord::query()->updateOrCreate(
                ['module' => $record['module'], 'record_key' => $record['record_key']],
                $record
            );
        }

        $this->seedOperationalData();
    }

    private function seedOperationalData(): void
    {
        Company::query()->updateOrCreate(
            ['code' => 'NAHSHON-MEP'],
            ['name' => 'NAHSHON MEP', 'legal_name' => 'NAHSHON MEP', 'status' => 'active']
        );
    }
}
