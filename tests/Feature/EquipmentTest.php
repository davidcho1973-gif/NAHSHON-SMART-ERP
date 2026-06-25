<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\EquipmentRental;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use App\Services\GeminiEquipmentAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EquipmentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Company $company;
    private Site $site;
    private Team $team;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'code' => 'NAHSHON',
            'name' => 'NAHSHON MEP',
            'status' => 'active',
        ]);

        $this->site = Site::create([
            'company_id' => $this->company->id,
            'code' => 'LGES-AZ',
            'name' => 'LGES Arizona',
            'status' => 'active',
        ]);

        $this->team = Team::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'code' => 'ELEC_A',
            'name' => 'Electrical A',
            'status' => 'active',
        ]);

        $this->employee = Employee::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'team_id' => $this->team->id,
            'name' => 'John Doe',
            'employee_number' => 'EMP-101',
            'badge_number' => 'NFC-101',
            'employment_status' => 'active',
        ]);

        $this->user = User::create([
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'password' => bcrypt('password'),
            'access_role' => 'admin',
            'access_scope' => 'all_sites',
            'account_status' => 'active',
            'employee_id' => $this->employee->id,
        ]);
    }

    public function test_scan_rental_calls_gemini_analyzer_and_saves_files(): void
    {
        Storage::fake('public');

        $this->actingAs($this->user);

        $mockAnalysis = [
            'equipment_type' => 'Forklift',
            'model' => 'JLG 2505H',
            'vendor' => 'United Rentals',
            'rent_start' => '2026-06-01',
            'rent_end' => '2026-12-01',
            'daily_rate' => 350,
            'delivery_fee' => 150,
            'model_name' => 'gemini-2.5-flash',
        ];

        $this->mock(GeminiEquipmentAnalyzer::class, function ($mock) use ($mockAnalysis) {
            $mock->shouldReceive('analyze')
                ->once()
                ->andReturn($mockAnalysis);
        });

        $contract = UploadedFile::fake()->create('contract.pdf', 500, 'application/pdf');
        $front = UploadedFile::fake()->create('front.jpg', 500, 'image/jpeg');

        $response = $this->postJson(route('equipment.scan-rental'), [
            'contract' => $contract,
            'photo_front' => $front,
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'data' => $mockAnalysis,
        ]);

        $responseData = $response->json();
        $this->assertNotNull($responseData['files']['contract']);
        $this->assertNotNull($responseData['files']['photo_front']);

        Storage::disk('public')->assertExists(str_replace('/storage/', '', $responseData['files']['contract']));
        Storage::disk('public')->assertExists(str_replace('/storage/', '', $responseData['files']['photo_front']));
    }

    public function test_save_equipment_stores_data_in_database(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(route('equipment.save'), [
            'equipment_type' => 'Forklift',
            'model' => 'JLG 2505H',
            'vendor' => 'United Rentals',
            'rent_start' => '2026-06-01',
            'rent_end' => '2026-12-01',
            'daily_rate' => 350,
            'delivery_fee' => 150,
            'contract_path' => '/storage/equipments/contract.pdf',
            'photo_front' => '/storage/equipments/front.jpg',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('equipments', [
            'equipment_type' => 'Forklift',
            'model' => 'JLG 2505H',
            'vendor' => 'United Rentals',
            'daily_rate' => 350,
            'delivery_fee' => 150,
            'status' => '대기중',
            'registration_method' => 'AI자동분석',
        ]);
    }

    public function test_save_equipment_creates_one_record_per_quantity_and_stores_details(): void
    {
        $this->actingAs($this->user);

        $details = [
            'quote_no' => 'Q-2391330',
            'ship_to_address' => '335 E Pecos Rd Queen Creek, AZ 85140',
            'lessee' => ['name' => 'NAHSHON MEP LLC', 'contact_email' => 'lili@outlook.com'],
            'pricing' => ['total_with_tax' => 3683.62],
        ];

        $response = $this->postJson(route('equipment.save'), [
            'equipment_type' => 'Storage Container',
            'model' => "40' CONTAINER",
            'vendor' => 'WillScot',
            'quantity' => 2,
            'rent_start' => '2026-06-30',
            'daily_rate' => 160,
            'delivery_fee' => 273,
            'return_fee' => 273,
            'rate_period' => '28-day cycle',
            'details' => $details,
        ]);

        $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('count', 2);

        // A "2 containers" order becomes 2 individual assets (previously registered as 1).
        $this->assertSame(2, Equipment::where('model', "40' CONTAINER")->count());

        $unit = Equipment::where('model', "40' CONTAINER")->first();
        $this->assertSame('Q-2391330', $unit->payload['details']['quote_no']);
        $this->assertSame('28-day cycle', $unit->payload['rate_period']);
        $this->assertSame(273, $unit->payload['return_fee']);
    }

    public function test_scan_inventory_calls_gemini_analyzer_and_saves_files(): void
    {
        Storage::fake('public');

        $this->actingAs($this->user);

        $mockAnalysis = [
            'equipment_type' => 'Power Tool (전동공구)',
            'model' => 'Hilti TE 70',
            'vendor' => 'Home Depot',
            'rent_start' => '2026-06-25',
            'rent_end' => '',
            'daily_rate' => 1200,
            'delivery_fee' => 0,
            'model_name' => 'gemini-2.5-flash',
            'quantity' => 1,
            'details' => [
                'quote_no' => 'REC-9928112',
                'lessor' => ['name' => 'Home Depot'],
            ],
        ];

        $this->mock(GeminiEquipmentAnalyzer::class, function ($mock) use ($mockAnalysis) {
            $mock->shouldReceive('analyze')
                ->once()
                ->andReturn($mockAnalysis);
        });

        $contract = UploadedFile::fake()->create('receipt.pdf', 500, 'application/pdf');
        $front = UploadedFile::fake()->create('front.jpg', 500, 'image/jpeg');

        $response = $this->postJson(route('equipment.scan-inventory'), [
            'contract' => $contract,
            'photo_front' => $front,
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'data' => $mockAnalysis,
        ]);

        $responseData = $response->json();
        $this->assertNotNull($responseData['files']['contract']);
        $this->assertNotNull($responseData['files']['photo_front']);

        Storage::disk('public')->assertExists(str_replace('/storage/', '', $responseData['files']['contract']));
        Storage::disk('public')->assertExists(str_replace('/storage/', '', $responseData['files']['photo_front']));
    }

    public function test_save_inventory_stores_data_in_database(): void
    {
        $this->actingAs($this->user);

        $details = [
            'quote_no' => 'REC-9928112',
            'lessor' => ['name' => 'Home Depot'],
        ];

        $response = $this->postJson(route('equipment.save-inventory'), [
            'equipment_type' => 'Power Tool (전동공구)',
            'model' => 'Hilti TE 70',
            'vendor' => 'Home Depot',
            'quantity' => 3,
            'rent_start' => '2026-06-25',
            'daily_rate' => 1200,
            'delivery_fee' => 50,
            'details' => $details,
            'contract_path' => '/storage/equipments/receipt.pdf',
            'photo_front' => '/storage/equipments/front.jpg',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('count', 3);

        $this->assertSame(3, Equipment::where('model', 'Hilti TE 70')->count());

        $first = Equipment::where('model', 'Hilti TE 70')->first();
        $this->assertSame('Power Tool (전동공구)', $first->equipment_type);
        $this->assertSame('Home Depot', $first->vendor);
        $this->assertSame(1200, $first->daily_rate);
        $this->assertSame(50, $first->delivery_fee);
        $this->assertSame('REC-9928112', $first->payload['details']['quote_no']);
        $this->assertSame('AI자동분석', $first->registration_method);
        $this->assertSame('대기중', $first->status);
    }

    public function test_analyzer_extracts_rich_contract_metadata(): void
    {
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'gemini-2.5-flash']);

        \Illuminate\Support\Facades\Http::fake([
            'generativelanguage.googleapis.com/*' => \Illuminate\Support\Facades\Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'equipment_type' => 'Storage Container',
                    'model' => "40' CONTAINER",
                    'vendor' => 'WillScot',
                    'quantity' => 2,
                    'rent_start' => '2026-06-30',
                    'rent_end' => '',
                    'rate_amount' => 160,
                    'rate_period' => '28-day cycle',
                    'daily_rate' => 0,
                    'delivery_fee' => 273,
                    'return_fee' => 273,
                    'details' => [
                        'quote_no' => 'Q-2391330',
                        'ship_to_address' => '335 E Pecos Rd Queen Creek, AZ 85140',
                        'sales_rep' => ['name' => 'Daniel Carrillo Gonzalez', 'phone' => '6023259147'],
                        'lessee' => ['name' => 'NAHSHON MEP LLC', 'contact_email' => 'lili@outlook.com'],
                        'terms' => ['payment_terms' => 'Net 10 Days', 'min_lease_term' => '4 cycles'],
                    ],
                ])]]]]],
            ]),
        ]);

        $tmp = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($tmp, '%PDF-1.4 fake');

        $result = app(GeminiEquipmentAnalyzer::class)->analyze([['path' => $tmp, 'mime_type' => 'application/pdf']]);
        @unlink($tmp);

        $this->assertSame(2, $result['quantity']);
        $this->assertSame('28-day cycle', $result['rate_period']);
        // daily_rate was 0 but recurring rate is 160 → falls back so the form never shows 0.
        $this->assertSame(160, $result['daily_rate']);
        $this->assertSame('Q-2391330', $result['details']['quote_no']);
        $this->assertSame('Daniel Carrillo Gonzalez', $result['details']['sales_rep']['name']);
        $this->assertStringContainsString('Queen Creek', $result['details']['ship_to_address']);
    }

    public function test_assign_equipment_creates_active_rental_and_updates_status(): void
    {
        $this->actingAs($this->user);

        $equipment = Equipment::create([
            'site_id' => $this->site->id,
            'equipment_type' => 'Boom Lift',
            'model' => 'Genie S-65',
            'status' => '대기중',
        ]);

        $response = $this->postJson(route('equipment.assign'), [
            'equipment_id' => $equipment->id,
            'company_id' => $this->company->id,
            'team_id' => $this->team->id,
            'employee_id' => $this->employee->id,
            'notes' => 'Assigning boom lift to team Electrical A',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('equipments', [
            'id' => $equipment->id,
            'company_id' => $this->company->id,
            'team_id' => $this->team->id,
            'employee_id' => $this->employee->id,
            'status' => '사용중',
        ]);

        $this->assertDatabaseHas('equipment_rentals', [
            'equipment_id' => $equipment->id,
            'company_id' => $this->company->id,
            'team_id' => $this->team->id,
            'employee_id' => $this->employee->id,
            'status' => 'active',
            'notes' => 'Assigning boom lift to team Electrical A',
        ]);
    }

    public function test_return_equipment_ends_rental_and_sets_status_available(): void
    {
        $this->actingAs($this->user);

        $equipment = Equipment::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'team_id' => $this->team->id,
            'employee_id' => $this->employee->id,
            'equipment_type' => 'Boom Lift',
            'model' => 'Genie S-65',
            'status' => '사용중',
        ]);

        $rental = EquipmentRental::create([
            'equipment_id' => $equipment->id,
            'company_id' => $this->company->id,
            'team_id' => $this->team->id,
            'employee_id' => $this->employee->id,
            'site_id' => $this->site->id,
            'rented_at' => now(),
            'status' => 'active',
        ]);

        $response = $this->postJson(route('equipment.return'), [
            'equipment_id' => $equipment->id,
            'notes' => 'Returned clean and fully functional',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('equipments', [
            'id' => $equipment->id,
            'company_id' => null,
            'team_id' => null,
            'employee_id' => null,
            'status' => '대기중',
        ]);

        $this->assertDatabaseHas('equipment_rentals', [
            'id' => $rental->id,
            'status' => 'returned',
        ]);
    }

    public function test_get_rental_history_returns_correct_chronology(): void
    {
        $this->actingAs($this->user);

        $equipment = Equipment::create([
            'site_id' => $this->site->id,
            'equipment_type' => 'Scissor Lift',
            'model' => 'JLG 1930ES',
            'status' => '대기중',
        ]);

        EquipmentRental::create([
            'equipment_id' => $equipment->id,
            'company_id' => $this->company->id,
            'team_id' => $this->team->id,
            'employee_id' => $this->employee->id,
            'site_id' => $this->site->id,
            'rented_at' => now()->subDays(5),
            'returned_at' => now()->subDays(4),
            'status' => 'returned',
            'notes' => 'First rental',
        ]);

        EquipmentRental::create([
            'equipment_id' => $equipment->id,
            'company_id' => $this->company->id,
            'team_id' => $this->team->id,
            'employee_id' => $this->employee->id,
            'site_id' => $this->site->id,
            'rented_at' => now()->subDays(2),
            'status' => 'active',
            'notes' => 'Second rental',
        ]);

        $response = $this->getJson(route('equipment.history', $equipment->id));

        $response->assertOk();
        $response->assertJsonPath('success', true);
        
        $history = $response->json('history');
        $this->assertCount(2, $history);
        $this->assertSame('Second rental', $history[0]['notes']);
        $this->assertSame('First rental', $history[1]['notes']);
    }

    public function test_serve_file_serves_valid_file_and_denies_unauthorized_paths(): void
    {
        Storage::fake('public');
        $this->actingAs($this->user);

        // Put a fake file in equipments/ directory
        Storage::disk('public')->put('equipments/test_photo.jpg', 'fake-image-contents');

        // Verify successful retrieval
        $response = $this->get(route('equipment.file', ['path' => 'equipments/test_photo.jpg']));
        $response->assertOk();
        $this->assertSame('fake-image-contents', $response->streamedContent());

        // Verify rejection of path outside equipments/
        $badResponse = $this->get(route('equipment.file', ['path' => 'outside/test_photo.jpg']));
        $badResponse->assertStatus(403);

        // Verify rejection of directory traversal
        $traversalResponse = $this->get(route('equipment.file', ['path' => 'equipments/../../test_photo.jpg']));
        $traversalResponse->assertStatus(403);
    }

    public function test_smart_company_data_equipment_list_and_dashboard_use_real_database(): void
    {
        $this->actingAs($this->user);

        Equipment::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'equipment_type' => 'Generator (발전기)',
            'model' => 'EU3000is',
            'vendor' => 'Honda',
            'status' => '사용중',
            'photo_front' => '/storage/equipments/test_honda.jpg',
            'quantity' => 1,
        ]);

        // Assert SmartCompanyData::equipmentList returns the real database item
        $list = \App\Support\SmartCompanyData::equipmentList();
        $this->assertCount(1, $list);
        $this->assertSame('EU3000is', $list[0]['name']);
        $this->assertSame('Generator (발전기)', $list[0]['category']);
        $this->assertNotNull($list[0]['photoUrl']);
        $this->assertStringContainsString('equipment-api/file', $list[0]['photoUrl']);

        // Assert SmartCompanyData::equipmentStats returns DB-accurate counts
        $stats = \App\Support\SmartCompanyData::equipmentStats();
        $this->assertSame(1, $stats['total']);
        $this->assertSame(1, $stats['operable']);
        $this->assertSame(0, $stats['inoperable']);

        // Assert SmartCompanyData::inventoryDashboard returns DB-accurate structures
        $dashboard = \App\Support\SmartCompanyData::inventoryDashboard();
        $this->assertTrue($dashboard['success']);
        $this->assertSame(1, $dashboard['totals']['assets']);
        $this->assertSame(1, $dashboard['totals']['inUse']);
        $this->assertSame(0, $dashboard['totals']['inStorage']);
        $this->assertContains('Generator (발전기)', $dashboard['matrix']['categories']);
        $this->assertSame(1, $dashboard['matrix']['cells']['Generator (발전기)']['LGES-AZ']);
    }

    public function test_desktop_equipment_update(): void
    {
        $this->actingAs($this->user);

        $equipment = Equipment::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'equipment_type' => 'Generator (발전기)',
            'model' => 'Old Model',
            'vendor' => 'Old Vendor',
            'status' => '대기중',
            'quantity' => 1,
        ]);

        $payload = [
            'equipment_type' => 'Power Tool (전동공구)',
            'model' => 'New Model',
            'vendor' => 'New Vendor',
            'rent_start' => '2026-06-20',
            'rent_end' => '2026-06-25',
            'daily_rate' => 150,
            'delivery_fee' => 50,
            'status' => '사용중',
        ];

        $response = $this->post(route('equipment.update', $equipment), $payload);
        $response->assertOk();
        $response->assertJsonPath('success', true);

        $equipment->refresh();
        $this->assertSame('Power Tool (전동공구)', $equipment->equipment_type);
        $this->assertSame('New Model', $equipment->model);
        $this->assertSame('New Vendor', $equipment->vendor);
        $this->assertSame(150, $equipment->daily_rate);
        $this->assertSame(50, $equipment->delivery_fee);
        $this->assertSame('사용중', $equipment->status);
    }

    public function test_desktop_equipment_update_with_assignments(): void
    {
        $this->actingAs($this->user);

        $equipment = Equipment::create([
            'company_id' => null,
            'site_id' => $this->site->id,
            'equipment_type' => 'Generator (발전기)',
            'model' => 'Old Model',
            'status' => '대기중',
            'quantity' => 1,
        ]);

        // 1. Assign to Company, Team, and Employee
        $payload = [
            'equipment_type' => 'Generator (발전기)',
            'model' => 'Old Model',
            'status' => '사용중',
            'company_id' => $this->company->id,
            'team_id' => $this->team->id,
            'employee_id' => $this->employee->id,
        ];

        $response = $this->post(route('equipment.update', $equipment), $payload);
        $response->assertOk();
        $response->assertJsonPath('success', true);

        $equipment->refresh();
        $this->assertEquals($this->company->id, $equipment->company_id);
        $this->assertEquals($this->team->id, $equipment->team_id);
        $this->assertEquals($this->employee->id, $equipment->employee_id);
        $this->assertArrayNotHasKey('custom_operator', $equipment->payload ?? []);

        // 2. Clear employee and assign a custom operator name instead
        $payload = [
            'equipment_type' => 'Generator (발전기)',
            'model' => 'Old Model',
            'status' => '사용중',
            'company_id' => $this->company->id,
            'team_id' => $this->team->id,
            'employee_id' => '',
            'custom_operator' => 'John Doe',
        ];

        $response = $this->post(route('equipment.update', $equipment), $payload);
        $response->assertOk();

        $equipment->refresh();
        $this->assertNull($equipment->employee_id);
        $this->assertSame('John Doe', $equipment->payload['custom_operator']);
    }

    public function test_desktop_equipment_delete(): void
    {
        $this->actingAs($this->user);

        $equipment = Equipment::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'equipment_type' => 'Generator (발전기)',
            'model' => 'To Delete',
            'status' => '대기중',
            'quantity' => 1,
        ]);

        $response = $this->post(route('equipment.delete', $equipment));
        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertNull(Equipment::find($equipment->id));
    }
}

