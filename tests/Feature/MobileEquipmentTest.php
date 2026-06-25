<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Services\GeminiEquipmentPhotoAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MobileEquipmentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Employee $employee;
    private Company $company;
    private Site $site;
    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'code' => 'TEST-COMP',
            'name' => 'Test Company',
            'status' => 'active',
        ]);

        $this->site = Site::create([
            'company_id' => $this->company->id,
            'code' => 'TEST-SITE',
            'name' => 'Test Site',
            'status' => 'active',
        ]);

        $this->team = Team::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'code' => 'TEST-TEAM',
            'name' => 'Test Team',
            'status' => 'active',
        ]);

        $this->employee = Employee::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'team_id' => $this->team->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'employment_status' => 'active',
        ]);

        $this->user = User::factory()->create([
            'employee_id' => $this->employee->id,
            'email' => 'john.doe@example.com',
            'access_role' => 'worker',
            'access_scope' => 'self',
            'account_status' => 'active',
        ]);
    }

    public function test_mobile_equipment_routes_require_auth(): void
    {
        $this->get(route('mobile-equipment.index'))->assertRedirect(route('login'));
        $this->get(route('mobile-equipment.wizard'))->assertRedirect(route('login'));
        $this->post(route('mobile-equipment.scan-photo'))->assertRedirect(route('login'));
        $this->post(route('mobile-equipment.store'))->assertRedirect(route('login'));
    }

    public function test_mobile_equipment_index_lists_equipments_and_stats(): void
    {
        // 1. Stored/Waiting (대기중) equipment visible under employee's site
        Equipment::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'equipment_type' => 'Generator (발전기)',
            'model' => 'EU2200i',
            'vendor' => 'Honda',
            'status' => '대기중',
        ]);

        // 2. Active (사용중) equipment
        Equipment::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'equipment_type' => 'Power Tool (전동공구)',
            'model' => 'DCD771',
            'vendor' => 'DeWalt',
            'status' => '사용중',
        ]);

        // 3. Maintenance (정비중) equipment under different site - won't be visible to 'self' worker scope if self scope restricts to same site/user context
        // Wait, the worker's access_scope is 'self', let's check scopeVisibleTo on the Equipment model.
        // It says:
        // 'self' => $query->whereRaw('1 = 0') // Wait, does the scope handle self?
        // Let's check App\Models\Equipment:
        // 'self' => $user->employee_id ? $query->where('employee_id', $user->employee_id) : ...
        // Wait! Let's check Equipment.php L120-132:
        // 'self' => $query->whereRaw('1 = 0') (Wait! L130 is default => $query->whereRaw('1 = 0')).
        // Let's verify by setting the user access_scope to 'site' or 'all_sites' so they can see the site equipment.
        // Let's set 'access_scope' => 'site' and 'allowed_site_id' => $this->site->id on $this->user first, or let's create a test user with access_scope 'site'.

        $this->user->update([
            'access_scope' => 'site',
            'allowed_site_id' => $this->site->id,
            'allowed_company_id' => $this->company->id,
        ]);

        $response = $this->actingAs($this->user)->get(route('mobile-equipment.index'));

        $response->assertStatus(200);
        $response->assertViewHas('totalCount', 2);
        $response->assertViewHas('availableCount', 1); // 대기중
        $response->assertViewHas('operableCount', 1); // 사용중
        $response->assertSee('EU2200i');
        $response->assertSee('DCD771');
    }

    public function test_mobile_equipment_index_filters_by_site(): void
    {
        $otherSite = Site::create([
            'company_id' => $this->company->id,
            'code' => 'OTHER-SITE',
            'name' => 'Other Site',
            'status' => 'active',
        ]);

        Equipment::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'equipment_type' => 'Hand Tool (수공구)',
            'model' => 'Hammer-A',
            'status' => '대기중',
        ]);

        Equipment::create([
            'company_id' => $this->company->id,
            'site_id' => $otherSite->id,
            'equipment_type' => 'Hand Tool (수공구)',
            'model' => 'Wrench-B',
            'status' => '대기중',
        ]);

        // User allowed to view all sites
        $this->user->update([
            'access_scope' => 'all_sites',
        ]);

        // View ALL
        $response = $this->actingAs($this->user)->get(route('mobile-equipment.index', ['site' => 'ALL']));
        $response->assertStatus(200);
        $response->assertViewHas('totalCount', 2);
        $response->assertSee('Hammer-A');
        $response->assertSee('Wrench-B');

        // View only specific site
        $responseFiltered = $this->actingAs($this->user)->get(route('mobile-equipment.index', ['site' => 'OTHER-SITE']));
        $responseFiltered->assertStatus(200);
        $responseFiltered->assertViewHas('totalCount', 1);
        $responseFiltered->assertDontSee('Hammer-A');
        $responseFiltered->assertSee('Wrench-B');
    }

    public function test_mobile_equipment_wizard_renders_correctly(): void
    {
        $response = $this->actingAs($this->user)->get(route('mobile-equipment.wizard'));

        $response->assertStatus(200);
        $response->assertViewHas('sites');
        $response->assertViewHas('teams');
        $response->assertViewHas('employees');
        $response->assertViewHas('selectedSiteId', $this->site->id);
    }

    public function test_mobile_equipment_scan_photo_analyzes_image(): void
    {
        Storage::fake('public');

        $this->mock(GeminiEquipmentPhotoAnalyzer::class, function ($mock): void {
            $mock->shouldReceive('analyze')
                ->once()
                ->andReturn([
                    'equipment_type' => 'Generator (발전기)',
                    'model' => 'EU2200i',
                    'vendor' => 'Honda',
                    'model_name' => 'gemini-mock',
                ]);
        });

        $file = UploadedFile::fake()->create('generator.jpg', 800, 'image/jpeg');

        $response = $this->actingAs($this->user)->postJson(route('mobile-equipment.scan-photo'), [
            'photo' => $file,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'equipment_type' => 'Generator (발전기)',
                'model' => 'EU2200i',
                'vendor' => 'Honda',
                'model_name' => 'gemini-mock',
            ],
        ]);

        $path = $response->json('photo_path');
        $this->assertNotNull($path);
        $storedFilename = basename($path);
        Storage::disk('public')->assertExists('equipments/' . $storedFilename);
    }

    public function test_mobile_equipment_store_saves_and_redirects(): void
    {
        $ocrPayload = [
            'equipment_type' => 'Generator (발전기)',
            'model' => 'EU2200i',
            'vendor' => 'Honda',
        ];

        $postData = [
            'equipment_type' => 'Generator (발전기)',
            'model' => 'EU2200i',
            'vendor' => 'Honda',
            'site_id' => $this->site->id,
            'team_id' => $this->team->id,
            'employee_id' => $this->employee->id,
            'photo_front' => '/storage/equipments/test_generator.jpg',
            'ocr_data' => json_encode($ocrPayload),
            'status' => '대기중',
            'quantity' => 1,
        ];

        $response = $this->actingAs($this->user)->post(route('mobile-equipment.store'), $postData);

        $response->assertRedirect(route('mobile-equipment.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('equipments', [
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'team_id' => $this->team->id,
            'employee_id' => $this->employee->id,
            'equipment_type' => 'Generator (발전기)',
            'model' => 'EU2200i',
            'vendor' => 'Honda',
            'status' => '대기중',
            'photo_front' => '/storage/equipments/test_generator.jpg',
            'registration_method' => 'AI자동분석',
        ]);

        // Assert equipment_code starts with 'EQ-'
        $eq = Equipment::latest('id')->first();
        $this->assertNotNull($eq);
        $this->assertStringStartsWith('EQ-', $eq->equipment_code);
    }

    public function test_mobile_equipment_store_saves_manually_corrected_fields(): void
    {
        $postData = [
            'equipment_type' => 'Other (기타)',
            'model' => 'Custom Drill X',
            'vendor' => 'My Brand',
            'site_id' => $this->site->id,
            'status' => '대기중',
            'quantity' => 1,
        ];

        $response = $this->actingAs($this->user)->post(route('mobile-equipment.store'), $postData);

        $response->assertRedirect(route('mobile-equipment.index'));

        $this->assertDatabaseHas('equipments', [
            'equipment_type' => 'Other (기타)',
            'model' => 'Custom Drill X',
            'vendor' => 'My Brand',
            'quantity' => 1,
            'is_bulk' => false,
        ]);
    }

    public function test_mobile_equipment_store_creates_multiple_records_for_serialized_quantity(): void
    {
        $postData = [
            'equipment_type' => 'Power Tool (전동공구)',
            'model' => 'DCD771',
            'vendor' => 'DeWalt',
            'site_id' => $this->site->id,
            'status' => '대기중',
            'quantity' => 3,
            'is_bulk' => '0',
        ];

        $response = $this->actingAs($this->user)->post(route('mobile-equipment.store'), $postData);

        $response->assertRedirect(route('mobile-equipment.index'));

        // Check that 3 separate rows were created
        $records = Equipment::where('model', 'DCD771')->get();
        $this->assertCount(3, $records);

        foreach ($records as $index => $record) {
            $this->assertStringStartsWith('EQ-', $record->equipment_code);
            $this->assertSame(1, $record->quantity);
            $this->assertFalse($record->is_bulk);
        }
    }

    public function test_mobile_equipment_store_saves_single_record_with_quantity_for_bulk_materials(): void
    {
        $postData = [
            'equipment_type' => 'Other (기타)',
            'model' => 'Drywall Screws 2in',
            'vendor' => 'Grip-Rite',
            'site_id' => $this->site->id,
            'status' => '대기중',
            'quantity' => 50,
            'is_bulk' => 'on',
        ];

        $response = $this->actingAs($this->user)->post(route('mobile-equipment.store'), $postData);

        $response->assertRedirect(route('mobile-equipment.index'));

        // Check that only 1 row was created with quantity 50
        $records = Equipment::where('model', 'Drywall Screws 2in')->get();
        $this->assertCount(1, $records);

        $record = $records->first();
        $this->assertSame(50, $record->quantity);
        $this->assertTrue($record->is_bulk);
        $this->assertStringStartsWith('EQ-', $record->equipment_code);
    }
}
