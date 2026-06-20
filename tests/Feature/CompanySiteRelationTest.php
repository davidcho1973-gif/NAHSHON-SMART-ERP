<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanySiteRelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_many_to_many_relation_works(): void
    {
        $site1 = Site::query()->create([
            'code' => 'TEST-SITE-1',
            'name' => 'Test Site 1',
            'timezone' => 'America/Phoenix',
            'status' => 'active',
        ]);
        $site2 = Site::query()->create([
            'code' => 'TEST-SITE-2',
            'name' => 'Test Site 2',
            'timezone' => 'America/Phoenix',
            'status' => 'active',
        ]);

        $company1 = Company::query()->create([
            'code' => 'TEST-COMPANY-1',
            'name' => 'Test Company 1',
            'status' => 'active',
        ]);
        $company2 = Company::query()->create([
            'code' => 'TEST-COMPANY-2',
            'name' => 'Test Company 2',
            'status' => 'active',
        ]);

        // Attach companies to sites
        $site1->companies()->attach([$company1->id, $company2->id]);
        $site2->companies()->attach([$company1->id]);

        $this->assertCount(2, $site1->fresh()->companies);
        $this->assertCount(1, $site2->fresh()->companies);

        $this->assertCount(2, $company1->fresh()->sites);
        $this->assertCount(1, $company2->fresh()->sites);

        $this->assertEquals('Test Company 1', $site1->companies->first()->name);
    }
}
