<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\SmartRecord;
use App\Models\User;
use App\Support\SmartCompanyData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Support\Org;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => config('smart_company.admin_email') ?: (config('org.admin_email') ?: 'admin@example.test')],
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
        // 자사(자기 회사) 한 줄. 고객사마다 다르므로 이름을 코드에 두지 않는다 —
        // 두면 새 고객 배포에 남의 회사 이름이 먼저 들어가 앉는다.
        Company::query()->updateOrCreate(
            ['code' => Org::code()],
            [
                'name' => Org::name(),
                'legal_name' => Org::legalName(),
                'company_type' => Company::TYPE_OWN,
                'status' => 'active',
            ]
        );
    }
}
