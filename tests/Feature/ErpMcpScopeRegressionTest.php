<?php

namespace Tests\Feature;

use App\Mcp\Read\ErpReadBoundary;
use App\Mcp\Read\ErpReadContext;
use App\Mcp\Read\ErpReadQuery;
use App\Models\CommunicationMessage;
use App\Models\CommunicationNotification;
use App\Models\CommunicationRoom;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\EquipmentChecklistTemplate;
use App\Models\EquipmentRental;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Site;
use App\Models\Team;
use App\Models\UnifiedAlert;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleRental;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/**
 * Populated regressions for the read boundary. Lower-role contexts exercise
 * defense in depth; they do not enable those roles in the super-admin-only pilot.
 */
class ErpMcpScopeRegressionTest extends TestCase
{
    use DatabaseMigrations;

    private function fixture(): array
    {
        $a = Company::create(['code' => 'MCP-SCOPE-A', 'name' => 'Scope Company A', 'status' => 'active']);
        $b = Company::create(['code' => 'MCP-SCOPE-B', 'name' => 'Scope Company B', 'status' => 'active']);
        $siteA = Site::create(['company_id' => $a->id, 'code' => 'MCP-SCOPE-A1', 'name' => 'A first site', 'status' => 'active']);
        $siteA2 = Site::create(['company_id' => $a->id, 'code' => 'MCP-SCOPE-A2', 'name' => 'A second site', 'status' => 'active']);
        $siteB = Site::create(['company_id' => $b->id, 'code' => 'MCP-SCOPE-B1', 'name' => 'B first site', 'status' => 'active']);
        $user = User::factory()->create(['access_role' => 'super_admin', 'access_scope' => 'all_sites', 'account_status' => 'active']);

        return [$a, $b, $siteA, $siteA2, $siteB, $user];
    }

    private function employee(Company $company, Site $site, string $code, ?Team $team = null): Employee
    {
        // Tests need stored facts, not account/room/payroll provisioning observers.
        return Employee::withoutEvents(fn () => Employee::create([
            'company_id' => $company->id, 'site_id' => $site->id, 'team_id' => $team?->id,
            'employee_number' => $code, 'name' => $code, 'employment_status' => 'active',
        ]));
    }

    private function payrollRun(string $code, float $gross = 100): PayrollRun
    {
        return PayrollRun::create([
            'code' => $code, 'period_start' => '2026-09-21', 'period_end' => '2026-10-04',
            'site_scope' => 'ALL', 'status' => 'calculated', 'total_gross' => $gross,
            'total_net' => $gross, 'headcount' => 1,
        ]);
    }

    /** @param array<int|null> $sites */
    private function payslip(PayrollRun $run, Employee $employee, array $sites, float $gross = 100): Payslip
    {
        $slip = Payslip::create([
            'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
            'company_id' => $employee->company_id, 'snap_pay_type' => 'hourly',
            'snap_base_rate' => 25, 'gross_pay' => $gross, 'net_pay' => $gross,
            'currency' => 'USD', 'status' => 'calculated',
        ]);
        foreach ($sites as $siteId) {
            $slip->lines()->create([
                'site_id' => $siteId, 'hour_type' => 'REG', 'hours' => 4,
                'rate_applied' => 25, 'amount' => $gross / count($sites),
            ]);
        }

        return $slip;
    }

    private function rows(string $dataset, ErpReadContext $context): array
    {
        return app(ErpReadBoundary::class)->run(
            fn () => app(ErpReadQuery::class)->read($dataset, $context, ['limit' => 100])['records']
        );
    }

    private function ids(string $dataset, ErpReadContext $context): array
    {
        return array_column($this->rows($dataset, $context), 'id');
    }

    public function test_mixed_site_payslip_totals_and_child_lines_are_withheld_from_a_single_site(): void
    {
        [$a, , $siteA, $siteA2, , $user] = $this->fixture();
        $employee = $this->employee($a, $siteA, 'MCP-SCOPE-PAY-1');
        $pure = $this->payslip($this->payrollRun('MCP-PURE'), $employee, [$siteA->id]);
        $mixed = $this->payslip($this->payrollRun('MCP-MIXED', 1000), $employee, [$siteA->id, $siteA2->id], 1000);
        $unassigned = $this->payslip($this->payrollRun('MCP-UNASSIGNED', 500), $employee, [$siteA->id, null], 500);
        $context = new ErpReadContext($user, $a->id, $siteA->id);

        $this->assertSame([$pure->id], $this->ids('payslips', $context));
        $this->assertSame($pure->lines()->pluck('id')->all(), $this->ids('payslip_lines', $context));
        $this->assertNotContains($mixed->id, $this->ids('payslips', $context));
        $this->assertNotContains($unassigned->id, $this->ids('payslips', $context));
        // A valid all-company selection can see the complete two-site slip.
        $this->assertSame([$pure->id, $mixed->id], $this->ids('payslips', new ErpReadContext($user, $a->id)));
    }

    public function test_payroll_run_totals_require_every_payslip_in_the_authorized_selection(): void
    {
        [$a, $b, $siteA, $siteA2, $siteB, $user] = $this->fixture();
        $employeeA = $this->employee($a, $siteA, 'MCP-SCOPE-PAY-A');
        $employeeA2 = $this->employee($a, $siteA2, 'MCP-SCOPE-PAY-A2');
        $employeeB = $this->employee($b, $siteB, 'MCP-SCOPE-PAY-B');
        $pureRun = $this->payrollRun('MCP-RUN-PURE');
        $this->payslip($pureRun, $employeeA, [$siteA->id]);
        $mixedSiteRun = $this->payrollRun('MCP-RUN-SITES', 1000);
        $visibleSiteSlip = $this->payslip($mixedSiteRun, $employeeA, [$siteA->id], 200);
        $this->payslip($mixedSiteRun, $employeeA2, [$siteA2->id], 800);
        $mixedCompanyRun = $this->payrollRun('MCP-RUN-COMPANIES', 2000);
        $visibleCompanySlip = $this->payslip($mixedCompanyRun, $employeeA, [$siteA->id], 300);
        $this->payslip($mixedCompanyRun, $employeeB, [$siteB->id], 1700);
        $this->payrollRun('MCP-RUN-EMPTY', 9999);
        $siteContext = new ErpReadContext($user, $a->id, $siteA->id);

        $this->assertSame([$pureRun->id], $this->ids('payroll_runs', $siteContext));
        $this->assertContains($visibleSiteSlip->id, $this->ids('payslips', $siteContext));
        $this->assertContains($visibleCompanySlip->id, $this->ids('payslips', $siteContext));
        $rows = $this->rows('payroll_runs', $siteContext);
        $this->assertSame(100.0, (float) $rows[0]['total_gross']);
        $this->assertSame([$pureRun->id, $mixedSiteRun->id], $this->ids('payroll_runs', new ErpReadContext($user, $a->id)));
    }

    public function test_checklist_templates_and_child_items_distinguish_global_company_and_site_scope(): void
    {
        [$a, $b, $siteA, $siteA2, $siteB, $user] = $this->fixture();
        $template = fn (string $name, ?int $companyId, ?int $siteId, string $scope, bool $default = false) => EquipmentChecklistTemplate::create([
            'name' => $name, 'company_id' => $companyId, 'site_id' => $siteId,
            'scope_type' => $scope, 'scope_value' => '*', 'is_default' => $default,
            'stage' => 'pre_use', 'status' => 'active',
        ]);
        $global = $template('True global default', null, null, 'global', true);
        $companyA = $template('Company A custom', $a->id, null, 'trade');
        $localA = $template('Site A custom', $a->id, $siteA->id, 'equipment');
        $otherSite = $template('Second A site custom', $a->id, $siteA2->id, 'equipment');
        $companyB = $template('Company B custom', $b->id, null, 'trade');
        $forgedGlobal = $template('B cannot become global by label', $b->id, $siteB->id, 'global', true);
        $unownedSite = $template('Null company is not global', null, $siteB->id, 'global', true);
        $unownedCustom = $template('Unscoped custom is not global', null, null, 'equipment');
        $items = [];
        foreach ([$global, $companyA, $localA, $otherSite, $companyB, $forgedGlobal, $unownedSite, $unownedCustom] as $row) {
            $items[$row->id] = $row->items()->create([
                'label_ko' => $row->name, 'label_en' => $row->name, 'label_es' => $row->name,
                'sort_order' => 1, 'stage' => 'pre_use', 'status' => 'active',
            ])->id;
        }
        $context = new ErpReadContext($user, $a->id, $siteA->id);

        $this->assertSame([$global->id, $companyA->id, $localA->id], $this->ids('equipment_check_templates', $context));
        $this->assertSame([$items[$global->id], $items[$companyA->id], $items[$localA->id]], $this->ids('equipment_check_items', $context));
        $this->assertSame([$global->id, $companyA->id, $localA->id, $otherSite->id],
            $this->ids('equipment_check_templates', new ErpReadContext($user, $a->id)));
    }

    public function test_cached_notification_text_is_withheld_after_private_room_membership_is_revoked(): void
    {
        [$a, , $siteA, , , $user] = $this->fixture();
        $room = CommunicationRoom::create([
            'company_id' => $a->id, 'site_id' => $siteA->id, 'type' => CommunicationRoom::TYPE_GROUP,
            'scope' => 'site', 'name' => 'Private source room', 'status' => 'active',
        ]);
        $membership = $room->members()->create(['user_id' => $user->id, 'role' => 'member', 'status' => 'active']);
        $message = CommunicationMessage::create([
            'communication_room_id' => $room->id, 'company_id' => $a->id, 'site_id' => $siteA->id,
            'body' => 'Private source text', 'status' => 'active', 'kind' => CommunicationMessage::KIND_MESSAGE,
        ]);
        $notification = CommunicationNotification::create([
            'user_id' => $user->id, 'communication_room_id' => $room->id,
            'communication_message_id' => $message->id, 'type' => 'mention',
            'title' => 'Private cached title', 'body' => 'Private source text',
        ]);
        $context = new ErpReadContext($user, $a->id, $siteA->id);
        $this->assertSame([$notification->id], $this->ids('personal_notifications', $context));
        $this->assertNull($notification->fresh()->read_at);

        $membership->update(['status' => 'left']);

        // Reuse the same context: a prior successful read cannot cache access.
        $this->assertSame([], $this->ids('communication_rooms', $context));
        $this->assertSame([], $this->ids('communication_messages', $context));
        $this->assertSame([], $this->ids('personal_notifications', $context));
        $this->assertNull($notification->fresh()->read_at);
        $this->assertSame('left', $membership->fresh()->status);
    }

    public function test_personal_alerts_cannot_cross_the_selected_company_site_or_owner(): void
    {
        [$a, $b, $siteA, $siteA2, $siteB, $user] = $this->fixture();
        $other = User::factory()->create(['access_role' => 'super_admin', 'account_status' => 'active']);
        $employee = $this->employee($a, $siteA, 'MCP-SCOPE-ALERT-EMP');
        $make = fn (string $code, int $companyId, ?int $siteId, int $userId) => UnifiedAlert::create([
            'alert_code' => $code, 'fingerprint' => $code, 'company_id' => $companyId,
            'site_id' => $siteId, 'user_id' => $userId, 'employee_id' => $employee->id,
            'source_module' => 'HR', 'source_type' => Employee::class, 'source_id' => (string) $employee->id,
            'event_type' => 'employee_expiry', 'title' => $code, 'content' => 'Unprojected private details',
            'occurred_at' => now(), 'status' => 'unresolved',
        ]);
        $mine = $make('MCP-ALERT-A', $a->id, $siteA->id, $user->id);
        $make('MCP-ALERT-B', $b->id, $siteB->id, $user->id);
        $make('MCP-ALERT-A2', $a->id, $siteA2->id, $user->id);
        $make('MCP-ALERT-OTHER', $a->id, $siteA->id, $other->id);
        $make('MCP-ALERT-NULL-SITE', $a->id, null, $user->id);
        $context = new ErpReadContext($user, $a->id, $siteA->id);

        $this->assertSame([$mine->id], $this->ids('personal_alerts', $context));
        $this->assertArrayNotHasKey('content', $this->rows('personal_alerts', $context)[0]);
    }

    public function test_self_and_team_rental_rows_are_scoped_even_when_the_parent_asset_is_accessible(): void
    {
        [$a, , $siteA] = $this->fixture();
        $team = Team::create(['company_id' => $a->id, 'site_id' => $siteA->id, 'code' => 'MCP-OWN-TEAM', 'name' => 'Own team']);
        $otherTeam = Team::create(['company_id' => $a->id, 'site_id' => $siteA->id, 'code' => 'MCP-OTHER-TEAM', 'name' => 'Other team']);
        $mine = $this->employee($a, $siteA, 'MCP-RENT-MINE', $team);
        $teammate = $this->employee($a, $siteA, 'MCP-RENT-TEAMMATE', $team);
        $outsider = $this->employee($a, $siteA, 'MCP-RENT-OTHER', $otherTeam);
        $equipment = Equipment::create([
            'equipment_code' => 'MCP-SCOPE-EQ', 'company_id' => $a->id, 'site_id' => $siteA->id,
            'team_id' => $team->id, 'employee_id' => $mine->id, 'equipment_type' => 'Tool', 'model' => 'Synthetic tool',
        ]);
        $vehicle = Vehicle::create([
            'vehicle_code' => 'MCP-SCOPE-VEH', 'company_id' => $a->id, 'site_id' => $siteA->id,
            'team_id' => $team->id, 'model' => 'Synthetic vehicle',
        ]);
        $equipmentRentals = [];
        $vehicleRentals = [];
        foreach ([$mine, $teammate, $outsider] as $employee) {
            $attributes = ['employee_id' => $employee->id, 'company_id' => $a->id,
                'site_id' => $siteA->id, 'rented_at' => now(), 'status' => 'active'];
            $equipmentRentals[$employee->id] = EquipmentRental::create($attributes + [
                'equipment_id' => $equipment->id, 'team_id' => $employee->team_id,
            ])->id;
            $vehicleRentals[$employee->id] = VehicleRental::create($attributes + ['vehicle_id' => $vehicle->id])->id;
        }
        foreach (['self', 'team'] as $scope) {
            $actor = User::factory()->create([
                'employee_id' => $mine->id, 'access_role' => 'site_manager', 'account_status' => 'active',
                'access_scope' => $scope, 'allowed_company_id' => $a->id,
                'allowed_site_id' => $siteA->id, 'allowed_team_id' => $team->id,
            ]);
            $actor->companies()->attach($a->id);
            $context = new ErpReadContext($actor, $a->id, $siteA->id);
            $this->assertSame([$equipment->id], $this->ids('equipment', $context), $scope);
            $this->assertSame([$vehicle->id], $this->ids('vehicles', $context), $scope);
            $expectedEmployees = $scope === 'self' ? [$mine->id] : [$mine->id, $teammate->id];
            $this->assertSame(array_map(fn (int $id) => $equipmentRentals[$id], $expectedEmployees),
                $this->ids('equipment_rentals', $context), $scope.' equipment children');
            $this->assertSame(array_map(fn (int $id) => $vehicleRentals[$id], $expectedEmployees),
                $this->ids('vehicle_rentals', $context), $scope.' vehicle children');
        }
    }
}
