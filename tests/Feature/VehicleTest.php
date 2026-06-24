<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleRental;
use App\Services\GeminiVehicleAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VehicleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Company $company;
    private Site $site;
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

        $this->employee = Employee::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
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
            'plate_number' => 'AZ-999',
            'model' => 'Chevrolet Tahoe',
            'vendor' => 'Enterprise',
            'rent_start' => '2026-06-01',
            'rent_end' => '2026-12-01',
            'insurance_expiry' => '2027-06-01',
            'current_mileage' => 12500,
            'model_name' => 'gemini-2.5-flash',
        ];

        $this->mock(GeminiVehicleAnalyzer::class, function ($mock) use ($mockAnalysis) {
            $mock->shouldReceive('analyze')
                ->once()
                ->andReturn($mockAnalysis);
        });

        $contract = UploadedFile::fake()->create('contract.pdf', 500, 'application/pdf');
        $front = UploadedFile::fake()->create('front.jpg', 500, 'image/jpeg');

        $response = $this->postJson(route('vehicle.scan-rental'), [
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

    public function test_save_vehicle_stores_data_in_database(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(route('vehicle.save'), [
            'plate_number' => 'AZ-777',
            'model' => 'Ford F-150',
            'vendor' => 'Hertz',
            'rent_start' => '2026-06-01',
            'rent_end' => '2026-11-01',
            'insurance_expiry' => '2027-06-01',
            'current_mileage' => 5000,
            'contract_path' => '/storage/vehicles/contract.pdf',
            'photo_front' => '/storage/vehicles/front.jpg',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('vehicles', [
            'plate_number' => 'AZ-777',
            'model' => 'Ford F-150',
            'vendor' => 'Hertz',
            'current_mileage' => 5000,
            'status' => '대기중',
            'registration_method' => 'AI자동분석',
        ]);
    }

    public function test_assign_vehicle_creates_active_rental_and_updates_status(): void
    {
        $this->actingAs($this->user);

        $vehicle = Vehicle::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'model' => 'Toyota Sienna',
            'current_mileage' => 15000,
            'status' => '대기중',
        ]);

        $response = $this->postJson(route('vehicle.assign'), [
            'vehicle_id' => $vehicle->id,
            'employee_id' => $this->employee->id,
            'notes' => 'Assigning for field operations',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->id,
            'status' => '운행중',
        ]);

        $this->assertDatabaseHas('vehicle_rentals', [
            'vehicle_id' => $vehicle->id,
            'employee_id' => $this->employee->id,
            'status' => 'active',
            'notes' => 'Assigning for field operations',
        ]);
    }

    public function test_return_vehicle_ends_rental_and_updates_mileage(): void
    {
        $this->actingAs($this->user);

        $vehicle = Vehicle::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'model' => 'Ford Transit',
            'current_mileage' => 20000,
            'status' => '운행중',
        ]);

        $rental = VehicleRental::create([
            'vehicle_id' => $vehicle->id,
            'employee_id' => $this->employee->id,
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'rented_at' => now(),
            'start_mileage' => 20000,
            'status' => 'active',
        ]);

        $response = $this->postJson(route('vehicle.return'), [
            'vehicle_id' => $vehicle->id,
            'current_mileage' => 20500,
            'notes' => 'Returned clean',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->id,
            'current_mileage' => 20500,
            'status' => '대기중',
        ]);

        $this->assertDatabaseHas('vehicle_rentals', [
            'id' => $rental->id,
            'status' => 'returned',
            'end_mileage' => 20500,
        ]);
    }

    public function test_get_rental_history_returns_correct_chronology(): void
    {
        $this->actingAs($this->user);

        $vehicle = Vehicle::create([
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'model' => 'Jeep Wrangler',
            'current_mileage' => 30000,
            'status' => '대기중',
        ]);

        VehicleRental::create([
            'vehicle_id' => $vehicle->id,
            'employee_id' => $this->employee->id,
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'rented_at' => now()->subDays(5),
            'returned_at' => now()->subDays(4),
            'start_mileage' => 29000,
            'end_mileage' => 29500,
            'status' => 'returned',
            'notes' => 'First trip',
        ]);

        VehicleRental::create([
            'vehicle_id' => $vehicle->id,
            'employee_id' => $this->employee->id,
            'company_id' => $this->company->id,
            'site_id' => $this->site->id,
            'rented_at' => now()->subDays(2),
            'start_mileage' => 29500,
            'status' => 'active',
            'notes' => 'Second trip',
        ]);

        $response = $this->getJson(route('vehicle.history', $vehicle->id));

        $response->assertOk();
        $response->assertJsonPath('success', true);
        
        $history = $response->json('history');
        $this->assertCount(2, $history);
        $this->assertSame('Second trip', $history[0]['notes']);
        $this->assertSame('First trip', $history[1]['notes']);
    }

    public function test_serve_file_serves_valid_file_and_denies_unauthorized_paths(): void
    {
        Storage::fake('public');
        $this->actingAs($this->user);

        // Put a fake file in vehicles/ directory
        Storage::disk('public')->put('vehicles/test_photo.jpg', 'fake-image-contents');

        // Verify successful retrieval
        $response = $this->get(route('vehicle.file', ['path' => 'vehicles/test_photo.jpg']));
        $response->assertOk();
        $this->assertSame('fake-image-contents', $response->streamedContent());

        // Verify rejection of path outside vehicles/
        $badResponse = $this->get(route('vehicle.file', ['path' => 'outside/test_photo.jpg']));
        $badResponse->assertStatus(403);

        // Verify rejection of directory traversal
        $traversalResponse = $this->get(route('vehicle.file', ['path' => 'vehicles/../../test_photo.jpg']));
        $traversalResponse->assertStatus(403);
    }
}
