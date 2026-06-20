<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Employee;
use App\Models\SmartRecord;
use App\Models\Site;
use App\Models\Team;
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
        $companies = [];
        $sites = [];
        $teams = [];

        $nahshon = Company::query()->updateOrCreate(
            ['code' => 'NAHSHON-MEP'],
            ['name' => 'NAHSHON MEP', 'legal_name' => 'NAHSHON MEP', 'status' => 'active']
        );
        $companies[$nahshon->name] = $nahshon;

        foreach (SmartCompanyData::sites() as $code => $name) {
            $sites[$code] = Site::query()->updateOrCreate(
                ['code' => $code],
                [
                    'company_id' => $nahshon->id,
                    'name' => preg_replace('/\s+/', ' ', str_replace('â€”', '-', $name)),
                    'timezone' => 'America/Phoenix',
                    'status' => 'active',
                ]
            );
        }

        foreach (SmartCompanyData::personnel('ALL') as $person) {
            $companyName = $person['company'] ?? 'Unknown Company';
            $companyCode = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', $companyName));
            $company = $companies[$companyName] ??= Company::query()->updateOrCreate(
                ['code' => trim($companyCode, '-') ?: 'UNKNOWN'],
                ['name' => $companyName, 'status' => 'active']
            );

            $siteCode = $person['site'] ?? 'HFF-02';
            $site = $sites[$siteCode] ?? $sites['HFF-02'];
            $teamName = $person['team'] ?? 'General';
            $teamKey = $site->code . ':' . $teamName;
            $team = $teams[$teamKey] ??= Team::query()->updateOrCreate(
                ['site_id' => $site->id, 'code' => strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', $teamName)) ?: 'GENERAL'],
                ['name' => $teamName, 'status' => 'active']
            );

            Employee::query()->updateOrCreate(
                ['employee_number' => $person['id']],
                [
                    'company_id' => $company->id,
                    'team_id' => $team->id,
                    'badge_number' => $person['badgeId'] ?? null,
                    'name' => $person['nameEn'],
                    'nationality' => $companyName === 'Local Union' ? 'USA' : 'Korea',
                    'role' => $person['role'] ?? null,
                    'employment_status' => 'active',
                    'visa_expires_on' => isset($person['visaExpiry']) && $person['visaExpiry'] !== '-' ? $person['visaExpiry'] : null,
                    'payload' => $person,
                ]
            );
        }
    }
}
