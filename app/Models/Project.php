<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'site_id',
        'team_id',
        'employee_id',
        'project_code',
        'name',
        'construction_type',
        'end_client_company_id',
        'project_stage',
        'vendor_tier',
        'upper_contractor_company_id',
        'epc_company_id',
        'po_number',
        'contract_type',
        'scope_of_work',
        'change_order_code_prefix',
        'site_address',
        'state',
        'jurisdiction',
        'sales_use_tax_code',
        'sales_use_tax_rate',
        'ntp_date',
        'mobilization_date',
        'planned_completion_date',
        'actual_completion_date',
        'milestone_plan',
        'contract_amount',
        'currency',
        'budget_labor_amount',
        'budget_material_amount',
        'budget_equipment_amount',
        'budget_expense_amount',
        'retainage_percent',
        'payment_terms',
        'cost_code_system',
        'wbs_code',
        'prevailing_wage_required',
        'davis_bacon_required',
        'union_status',
        'certified_payroll_required',
        'insurance_requirements',
        'ocip_ccip_status',
        'bonding_required',
        'osha_plan_status',
        'lien_notice_required',
        'preliminary_notice_due_on',
        'workforce_plan',
        'korean_dispatch_plan',
        'per_diem_policy',
        'equipment_plan',
        'subcontractor_plan',
        'master_data_links',
        'payload',
    ];

    public const CONSTRUCTION_TYPE_OPTIONS = [
        'mechanical_installation' => '기계설치 (Mechanical Installation)',
        'equipment_setting' => '장비설치 (Equipment Setting)',
        'piping' => '배관 (Piping)',
        'structural_steel' => '강구조 (Structural Steel)',
        'rigging' => '리깅 (Rigging)',
        'commissioning_support' => '시운전 지원 (Commissioning Support)',
    ];

    public const STAGE_OPTIONS = [
        'estimate' => '견적 (Estimate)',
        'awarded' => '수주 (Awarded)',
        'ntp' => '착공/NTP',
        'in_progress' => '진행 (In Progress)',
        'completed' => '준공 (Completed)',
        'warranty' => '하자보수 (Warranty)',
        'closed' => '종료 (Closed)',
    ];

    public const VENDOR_TIER_OPTIONS = [
        'tier_1' => '1차 벤더 (Tier 1)',
        'tier_2' => '2차 벤더 (Tier 2)',
        'tier_3' => '3차 벤더 (Tier 3)',
    ];

    public const CONTRACT_TYPE_OPTIONS = [
        'lump_sum' => 'Lump Sum (정액)',
        'unit_price' => 'Unit Price (단가)',
        'time_and_material' => 'T&M (실비정산)',
    ];

    public const UNION_STATUS_OPTIONS = [
        'union' => 'Union',
        'non_union_open_shop' => 'Non-Union / Open Shop',
        'mixed' => 'Mixed',
        'not_applicable' => 'N/A',
    ];

    public const OCIP_CCIP_OPTIONS = [
        'not_applicable' => 'N/A',
        'ocip' => 'OCIP',
        'ccip' => 'CCIP',
        'pending' => 'Pending confirmation',
    ];

    public const OSHA_PLAN_OPTIONS = [
        'not_started' => 'Not started',
        'drafting' => 'Drafting',
        'submitted' => 'Submitted',
        'approved' => 'Approved',
        'not_required' => 'Not required',
    ];

    protected static function booted(): void
    {
        static::creating(function (Project $project): void {
            if (filled($project->project_code)) {
                $project->project_code = strtoupper(trim($project->project_code));

                return;
            }

            $project->project_code = self::nextProjectCode($project);
        });
    }

    protected function casts(): array
    {
        return [
            'ntp_date' => 'date',
            'mobilization_date' => 'date',
            'planned_completion_date' => 'date',
            'actual_completion_date' => 'date',
            'preliminary_notice_due_on' => 'date',
            'milestone_plan' => 'array',
            'insurance_requirements' => 'array',
            'workforce_plan' => 'array',
            'korean_dispatch_plan' => 'array',
            'equipment_plan' => 'array',
            'subcontractor_plan' => 'array',
            'master_data_links' => 'array',
            'payload' => 'array',
            'contract_amount' => 'decimal:2',
            'budget_labor_amount' => 'decimal:2',
            'budget_material_amount' => 'decimal:2',
            'budget_equipment_amount' => 'decimal:2',
            'budget_expense_amount' => 'decimal:2',
            'retainage_percent' => 'decimal:2',
            'sales_use_tax_rate' => 'decimal:4',
            'prevailing_wage_required' => 'boolean',
            'davis_bacon_required' => 'boolean',
            'certified_payroll_required' => 'boolean',
            'bonding_required' => 'boolean',
            'lien_notice_required' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function endClient(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'end_client_company_id');
    }

    public function upperContractor(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'upper_contractor_company_id');
    }

    public function epcCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'epc_company_id');
    }

    private static function nextProjectCode(Project $project): string
    {
        $client = $project->end_client_company_id
            ? Company::query()->find($project->end_client_company_id)
            : null;
        $company = (! $client && $project->company_id)
            ? Company::query()->find($project->company_id)
            : null;

        $clientToken = self::codePart($client?->code ?: $client?->name ?: $company?->code ?: 'PROJECT', 8);
        $stateToken = self::codePart($project->state ?: 'US', 4);
        $year = now()->year;
        $prefix = "{$clientToken}-{$stateToken}-{$year}";

        $sequence = static::query()
            ->where('project_code', 'like', "{$prefix}-%")
            ->count() + 1;

        do {
            $code = sprintf('%s-%03d', $prefix, $sequence);
            $sequence++;
        } while (static::query()->where('project_code', $code)->exists());

        return $code;
    }

    private static function codePart(string $value, int $limit): string
    {
        $value = strtoupper((string) Str::of($value)->ascii());
        $value = preg_replace('/[^A-Z0-9]+/', '', $value) ?: 'PROJECT';

        return substr($value, 0, $limit);
    }
}
