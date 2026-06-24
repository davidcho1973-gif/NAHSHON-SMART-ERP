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
}
