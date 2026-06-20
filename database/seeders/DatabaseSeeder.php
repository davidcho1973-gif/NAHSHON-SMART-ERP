<?php

namespace Database\Seeders;

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
            ['email' => env('SMART_COMPANY_ADMIN_EMAIL', 'admin@nahshonmep.com')],
            [
                'name' => env('SMART_COMPANY_ADMIN_NAME', 'Admin User'),
                'password' => Hash::make(env('SMART_COMPANY_ADMIN_PASSWORD', 'change-this-password')),
            ]
        );

        foreach (SmartCompanyData::seedRecords() as $record) {
            SmartRecord::query()->updateOrCreate(
                ['module' => $record['module'], 'record_key' => $record['record_key']],
                $record
            );
        }
    }
}
