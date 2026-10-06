<?php

namespace App\Mcp\Read;

use App\Models;

/**
 * The MCP read boundary is explicit: model names, columns and relationships are
 * application constants, never client-supplied selectors or runtime fillable lists.
 *
 * Do not dispatch through legacy getters: several create alerts, rooms, templates
 * or claim links while reading. Query only stored rows through the read service.
 * Parent restrictions are mandatory, including when a child has scope=global.
 * Attachment paths are internal descriptors and must never join the projection.
 */
final class ErpDatasetCatalog
{
    private const SYSTEM = ['super_admin', 'admin'];

    private const BUYER = ['super_admin'];

    private const ERP = ['super_admin', 'admin', 'hr_manager', 'site_manager', 'safety_manager', 'payroll', 'vendor_admin', 'client', 'viewer'];

    private const INTERNAL = ['super_admin', 'admin', 'hr_manager', 'site_manager', 'safety_manager', 'payroll'];

    private const HR = ['super_admin', 'admin', 'hr_manager', 'site_manager', 'payroll', 'safety_manager'];

    private const PEOPLE = ['super_admin', 'admin', 'hr_manager'];

    private const MONEY = ['super_admin', 'admin', 'hr_manager', 'payroll'];

    private const DOCUMENTS = ['super_admin', 'admin', 'hr_manager', 'payroll'];

    private const CONTRACT = ['super_admin', 'admin', 'site_manager', 'payroll'];

    private const REGISTER = ['super_admin', 'admin', 'payroll', 'site_manager', 'safety_manager'];

    private const SITE = ['super_admin', 'admin', 'hr_manager', 'site_manager'];

    private const MEETING = ['super_admin', 'admin', 'site_manager'];

    private const CHECKS = ['super_admin', 'admin', 'site_manager', 'safety_manager', 'hr_manager'];

    private const RECEIVING = ['super_admin', 'admin', 'hr_manager', 'site_manager', 'safety_manager'];

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            'companies' => [
                'model' => Models\Company::class,
                'roles' => self::ERP,
                'scope' => 'company',
                'fields' => [
                    'id', 'code', 'name', 'legal_name', 'status', 'company_type',
                ],
                'search' => ['code', 'name', 'legal_name'],
            ],
            'sites' => [
                'model' => Models\Site::class,
                'roles' => self::ERP,
                'scope' => 'site',
                'fields' => [
                    'id', 'company_id', 'client_company_id', 'code', 'name', 'country',
                    'address', 'timezone', 'status', 'manager_employee_id', 'latitude', 'longitude',
                    'radius_meters', 'work_start', 'work_end', 'regular_minutes', 'break_minutes', 'break_after_minutes',
                    'workweek_days',
                ],
                'search' => ['code', 'name', 'address'],
            ],
            'teams' => [
                'model' => Models\Team::class,
                'roles' => self::HR,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'site_id', 'company_id', 'code', 'name', 'contract_company_name',
                    'trade_type', 'foreman_name', 'foreman_employee_id', 'responsible_manager_name', 'supervisor_name', 'supervisor_phone',
                    'planned_headcount', 'status', 'notes',
                ],
                'search' => ['code', 'name', 'trade_type'],
            ],
            'site_contractors' => [
                'model' => Models\SiteContractor::class,
                'roles' => self::INTERNAL,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'site_id', 'company_id', 'company_name', 'contract_role', 'contract_number',
                    'scope_of_work', 'primary_contact_name', 'primary_contact_phone', 'primary_contact_email', 'starts_on', 'ends_on',
                    'status', 'notes',
                ],
                'search' => ['company_name', 'contract_number'],
            ],
            'projects' => [
                'model' => Models\Project::class,
                'roles' => self::INTERNAL,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'company_id', 'site_id', 'team_id', 'employee_id', 'project_code',
                    'name', 'construction_type', 'end_client_company_id', 'project_stage', 'vendor_tier', 'upper_contractor_company_id',
                    'epc_company_id', 'po_number', 'contract_type', 'scope_of_work', 'site_address', 'state',
                    'jurisdiction', 'ntp_date', 'mobilization_date', 'planned_completion_date', 'actual_completion_date', 'wbs_code',
                    'prevailing_wage_required', 'davis_bacon_required', 'union_status', 'certified_payroll_required', 'ocip_ccip_status', 'bonding_required',
                    'osha_plan_status', 'lien_notice_required', 'preliminary_notice_due_on',
                ],
                'search' => ['project_code', 'name', 'scope_of_work'],
            ],
            'project_financials' => [
                'model' => Models\Project::class,
                'roles' => self::MONEY,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'company_id', 'site_id', 'project_code', 'name', 'contract_amount',
                    'currency', 'budget_labor_amount', 'budget_material_amount', 'budget_equipment_amount', 'budget_expense_amount', 'retainage_percent',
                    'payment_terms', 'sales_use_tax_code', 'sales_use_tax_rate',
                ],
                'search' => ['project_code', 'name'],
            ],
            'vendors' => [
                'model' => Models\Vendor::class,
                'roles' => self::INTERNAL,
                'scope' => 'company',
                'fields' => [
                    'id', 'company_id', 'name', 'contact_name', 'phone', 'email',
                    'address', 'trade', 'status', 'notes',
                ],
                'search' => ['name', 'contact_name', 'trade'],
            ],
            'employees' => [
                'model' => Models\Employee::class,
                'roles' => self::HR,
                'scope' => 'employee',
                'fields' => [
                    'id', 'company_id', 'site_id', 'team_id', 'employee_number', 'badge_number',
                    'badge_printed_number', 'first_name', 'last_name', 'name', 'email', 'phone',
                    'badge_company_name', 'badge_issued_on', 'nationality', 'preferred_language', 'role', 'position',
                    'start_date', 'employment_status', 'employment_type', 'visa_expires_on', 'safety_training_expires_on',
                ],
                'search' => ['employee_number', 'name', 'email'],
            ],
            'applicants' => [
                'model' => Models\MemberRegistration::class,
                'roles' => self::PEOPLE,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'employee_id', 'company_id', 'site_id', 'team_id', 'registration_number',
                    'applicant_code', 'employee_number', 'badge_number', 'member_type', 'full_name', 'first_name',
                    'last_name', 'preferred_name', 'email', 'phone', 'nationality', 'preferred_language',
                    'date_of_birth', 'address', 'emergency_contact_name', 'emergency_contact_phone', 'role', 'position',
                    'trade', 'start_date', 'end_date', 'visa_type', 'visa_expires_on', 'safety_training_expires_on',
                    'identity_status', 'document_status', 'onboarding_status', 'risk_level', 'interview_status', 'interviewed_at',
                    'interview_notes', 'safety_training_status', 'safety_training_completed_on', 'badge_registration_status', 'badge_printed_number', 'badge_company_name',
                    'invited_at', 'submitted_at', 'approved_at', 'notes',
                ],
                'search' => ['full_name', 'applicant_code', 'email'],
            ],
            'member_documents' => [
                'model' => Models\MemberDocument::class,
                'roles' => self::PEOPLE,
                'scope' => 'global',
                'fields' => [
                    'id', 'member_registration_id', 'document_type', 'title', 'status', 'issued_on',
                    'expires_on', 'review_notes', 'verified_at', 'verified_by_id',
                ],
                'parent' => ['member_registration_id', 'applicants'],
                'search' => ['title', 'document_type'],
            ],
            'w9_status' => [
                'model' => Models\W9Form::class,
                'roles' => self::MONEY,
                'scope' => 'global',
                'fields' => [
                    'id', 'employee_id', 'legal_name', 'business_name', 'tax_classification', 'llc_tax_class',
                    'address', 'city_state_zip', 'certified_at', 'status',
                ],
                'parent' => ['employee_id', 'employees'],
                'search' => ['legal_name', 'business_name'],
            ],
            'attendance_logs' => [
                'model' => Models\AttendanceLog::class,
                'roles' => self::HR,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'employee_id', 'company_id', 'site_id', 'team_id', 'photo_upload_id',
                    'daily_work_assignment_id', 'site_contractor_id', 'employer_company_id', 'recorded_by_id', 'attendance_date', 'event_type',
                    'event_at', 'source', 'status', 'approved_by_id', 'approved_at', 'notes',
                ],
                'parent' => ['employee_id', 'employees'],
            ],
            'attendance_sessions' => [
                'model' => Models\AttendanceSession::class,
                'roles' => self::HR,
                'scope' => 'site',
                'fields' => [
                    'id', 'employee_id', 'site_id', 'work_date', 'status', 'first_enter_at',
                    'last_enter_at', 'pending_exit_at', 'last_exit_at', 'last_onsite_at', 'on_site_seconds', 'needs_review',
                    'finalized_at',
                ],
                'parent' => ['employee_id', 'employees'],
            ],
            'attendance_geo_events' => [
                'model' => Models\AttendanceGeoEvent::class,
                'roles' => self::HR,
                'scope' => 'site',
                'fields' => [
                    'id', 'employee_id', 'site_id', 'kind', 'source', 'on_site',
                    'lat', 'lng', 'accuracy', 'occurred_at',
                ],
                'parent' => ['employee_id', 'employees'],
            ],
            'attendance_reminders' => [
                'model' => Models\AttendanceReminder::class,
                'roles' => self::HR,
                'scope' => 'global',
                'fields' => [
                    'id', 'employee_id', 'work_date', 'kind', 'sent_count', 'last_sent_at',
                ],
                'parent' => ['employee_id', 'employees'],
            ],
            'daily_work_assignments' => [
                'model' => Models\DailyWorkAssignment::class,
                'roles' => self::HR,
                'scope' => 'site',
                'fields' => [
                    'id', 'employee_id', 'work_date', 'employer_company_id', 'site_id', 'site_contractor_id',
                    'team_id', 'status', 'source', 'assigned_by_id', 'approved_by_id', 'approved_at',
                ],
                'parent' => ['employee_id', 'employees'],
            ],
            'daily_crew_reports' => [
                'model' => Models\DailyCrewReport::class,
                'roles' => self::HR,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'company_id', 'site_id', 'site_contractor_id', 'team_id', 'work_date',
                    'scanned_headcount', 'external_headcount', 'manual_adjustment', 'final_headcount', 'status', 'work_description',
                    'adjustment_reason', 'notes', 'reported_by_id', 'reported_at', 'closed_by_id', 'closed_at',
                ],
                'search' => ['work_description', 'notes'],
            ],
            'attendance_photos' => [
                'model' => Models\PhotoUpload::class,
                'roles' => self::HR,
                'scope' => 'site',
                'fields' => [
                    'id', 'site_id', 'team_id', 'uploaded_by_id', 'capture_type', 'captured_at',
                    'uploaded_at', 'status',
                ],
                'attachments' => ['original' => ['path' => 'storage_path', 'disk' => 'storage_disk', 'default_disk' => 'public']],
            ],
            'attendance_ocr' => [
                'model' => Models\OcrResult::class,
                'roles' => self::HR,
                'scope' => 'global',
                'fields' => [
                    'id', 'photo_upload_id', 'engine', 'status', 'confidence', 'raw_text',
                    'processed_at',
                ],
                'parent' => ['photo_upload_id', 'attendance_photos'],
            ],
            'payroll_profiles' => [
                'model' => Models\EmployeePayrollProfile::class,
                'roles' => self::MONEY,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'employee_id', 'company_id', 'site_id', 'pay_type', 'base_rate',
                    'overtime_multiplier', 'trade', 'worker_division', 'is_exempt', 'is_dispatched', 'visa_type',
                    'pay_currency', 'per_diem_rate', 'fringe_rate', 'fed_filing_status', 'withholding_state', 'retirement_pct',
                    'effective_from',
                ],
                'parent' => ['employee_id', 'employees'],
            ],
            'payroll_timesheets' => [
                'model' => Models\PayrollTimesheet::class,
                'roles' => self::MONEY,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'employee_id', 'company_id', 'site_id', 'team_id', 'employer_company_id',
                    'site_contractor_id', 'work_date', 'check_in_at', 'check_out_at', 'regular_minutes', 'overtime_minutes',
                    'payable_minutes', 'status', 'source', 'approved_by_id', 'approved_at',
                ],
                'parent' => ['employee_id', 'employees'],
            ],
            'payroll_runs' => [
                'model' => Models\PayrollRun::class,
                'roles' => self::MONEY,
                'scope' => 'payroll_run',
                'fields' => [
                    'id', 'code', 'period_start', 'period_end', 'pay_date', 'site_scope',
                    'status', 'fx_rate_krw', 'total_gross', 'total_net', 'headcount', 'created_by_id',
                    'calculated_at', 'approved_at',
                ],
                'search' => ['code'],
            ],
            'payslips' => [
                'model' => Models\Payslip::class,
                'roles' => self::MONEY,
                'scope' => 'company',
                'fields' => [
                    'id', 'payroll_run_id', 'employee_id', 'company_id', 'snap_pay_type', 'snap_base_rate',
                    'snap_trade', 'snap_division', 'regular_hours', 'overtime_hours', 'doubletime_hours', 'applied_rate',
                    'gross_pay', 'fringe_pay', 'per_diem', 'reimbursement', 'fed_tax', 'state_tax',
                    'fica', 'medicare', 'retirement_401k', 'other_deduction', 'net_pay', 'currency',
                    'open_days', 'status',
                ],
                'parent' => ['employee_id', 'employees'],
            ],
            'payslip_lines' => [
                'model' => Models\PayslipLine::class,
                'roles' => self::MONEY,
                'scope' => 'site',
                'fields' => [
                    'id', 'payslip_id', 'project_id', 'site_id', 'cost_code', 'hour_type',
                    'hours', 'rate_applied', 'amount',
                ],
                'parent' => ['payslip_id', 'payslips'],
            ],
            'expenses' => [
                'model' => Models\MobileExpense::class,
                'roles' => self::MONEY,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'company_id', 'vendor_id', 'site_id', 'project_id', 'wbs_code',
                    'employee_id', 'expense_pre_approval_id', 'payment_type', 'category', 'accounting_account', 'class',
                    'description', 'amount', 'expense_date', 'receipt_mime_type', 'receipt_original_name', 'status',
                    'reviewed_at', 'reviewed_by_user_id', 'rejection_reason', 'paid_at', 'paid_by_user_id', 'payment_reference',
                    'payroll_run_id',
                ],
                'search' => ['description', 'category', 'accounting_account'],
                'attachments' => ['receipt' => ['path' => 'receipt_path', 'default_disk' => 'public', 'name' => 'receipt_original_name', 'mime' => 'receipt_mime_type']],
            ],
            'expense_preapprovals' => [
                'model' => Models\ExpensePreApproval::class,
                'roles' => self::MONEY,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'company_id', 'site_id', 'employee_id', 'title', 'description',
                    'justification', 'estimated_amount', 'planned_date', 'payment_method', 'status',
                ],
                'search' => ['title', 'description'],
            ],
            'contracts' => [
                'model' => Models\ProjectContract::class,
                'roles' => self::CONTRACT,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'company_id', 'counterparty_company_id', 'counterparty_vendor_id', 'site_id', 'project_id',
                    'manager_employee_id', 'internal_reference', 'contract_number', 'title', 'direction', 'counterparty_role',
                    'contract_type', 'status', 'risk_level', 'executed_on', 'effective_on', 'starts_on',
                    'ends_on', 'notice_to_proceed_on', 'renewal_notice_days', 'next_action_on', 'next_action_notes', 'original_amount',
                    'approved_change_amount', 'current_amount', 'currency', 'retainage_percent', 'payment_terms', 'insurance_required',
                    'bond_required', 'prevailing_wage_required', 'certified_payroll_required', 'lien_notice_required', 'counterparty_contact_name', 'counterparty_contact_email',
                    'counterparty_contact_phone', 'scope_of_work', 'notes',
                ],
                'search' => ['contract_number', 'title', 'internal_reference'],
            ],
            'contract_documents' => [
                'model' => Models\ProjectContractDocument::class,
                'roles' => self::CONTRACT,
                'scope' => 'global',
                'fields' => [
                    'id', 'project_contract_id', 'replaces_document_id', 'uploaded_by', 'reviewed_by', 'document_type',
                    'title', 'document_number', 'version', 'status', 'is_required', 'is_current',
                    'is_confidential', 'issued_on', 'effective_on', 'expires_on', 'reviewed_at', 'original_file_name',
                    'mime_type', 'file_size', 'notes',
                ],
                'parent' => ['project_contract_id', 'contracts'],
                'search' => ['title', 'document_number'],
                'attachments' => ['original' => ['path' => 'file_path', 'disk' => 'disk', 'default_disk' => 'local', 'name' => 'original_file_name', 'mime' => 'mime_type', 'size' => 'file_size', 'disk_config' => 'document-intelligence.disk']],
            ],
            'contract_boq_lines' => [
                'model' => Models\ContractBoqLine::class,
                'roles' => self::CONTRACT,
                'scope' => 'global',
                'fields' => [
                    'id', 'project_contract_id', 'work_section_id', 'line_no', 'description', 'unit',
                    'contract_qty', 'unit_price', 'material_price', 'labor_price', 'expense_price', 'recognition_basis',
                    'stage_weights', 'status', 'acceptance_note', 'accepted_by', 'accepted_at', 'source_document_id',
                    'source_locator',
                ],
                'parent' => ['project_contract_id', 'contracts'],
                'search' => ['line_no', 'description'],
            ],
            'claim_work_records' => [
                'model' => Models\ClaimWorkRecord::class,
                'roles' => self::CONTRACT,
                'scope' => 'global',
                'fields' => [
                    'id', 'contract_boq_line_id', 'record_kind', 'work_date', 'location', 'stage',
                    'reported_qty', 'verified_qty', 'status', 'notes', 'reported_by', 'reviewed_by',
                    'reviewed_at', 'review_note',
                ],
                'parent' => ['contract_boq_line_id', 'contract_boq_lines'],
                'search' => ['location', 'notes'],
            ],
            'pay_applications' => [
                'model' => Models\PayApplication::class,
                'roles' => self::CONTRACT,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'project_contract_id', 'company_id', 'site_id', 'project_id', 'internal_reference',
                    'application_no', 'type', 'status', 'period_start', 'period_end', 'submitted_on',
                    'approved_on', 'due_on', 'this_period_amount', 'stored_materials_amount', 'previous_billed_amount', 'cumulative_amount',
                    'retainage_percent', 'retainage_released', 'retainage_held', 'earned_less_retainage', 'previous_certificates', 'amount_due',
                    'approved_amount', 'conditional_waiver_on', 'unconditional_waiver_on', 'paid_at', 'paid_by_user_id', 'intelligent_document_id',
                    'notes',
                ],
                'parent' => ['project_contract_id', 'contracts'],
                'search' => ['internal_reference', 'notes'],
            ],
            'pay_application_allocations' => [
                'model' => Models\PayApplicationAllocation::class,
                'roles' => self::CONTRACT,
                'scope' => 'global',
                'fields' => [
                    'id', 'pay_application_id', 'claim_work_record_id', 'quantity', 'amount',
                ],
                'parent' => ['pay_application_id', 'pay_applications'],
            ],
            'billing_receipts' => [
                'model' => Models\BillingReceipt::class,
                'roles' => self::CONTRACT,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'project_contract_id', 'pay_application_id', 'company_id', 'site_id', 'received_on',
                    'amount', 'method', 'reference', 'deduction_amount', 'deduction_reason', 'deduction_accepted',
                    'recorded_by_user_id', 'intelligent_document_id', 'memo',
                ],
                'parent' => ['project_contract_id', 'contracts'],
                'search' => ['reference', 'memo'],
            ],
            'contract_changes' => [
                'model' => Models\ContractChange::class,
                'roles' => self::CONTRACT,
                'scope' => 'global',
                'fields' => [
                    'id', 'project_contract_id', 'work_section_id', 'rfi_no', 'kind', 'title',
                    'status', 'submitted_on', 'decided_on', 'amount', 'request_document_id', 'approval_document_id',
                    'note', 'decision_note', 'created_by', 'decided_by',
                ],
                'parent' => ['project_contract_id', 'contracts'],
                'search' => ['rfi_no', 'title'],
            ],
            'contract_change_lines' => [
                'model' => Models\ContractChangeLine::class,
                'roles' => self::CONTRACT,
                'scope' => 'global',
                'fields' => [
                    'id', 'contract_change_id', 'contract_boq_line_id', 'qty_delta', 'qty_before', 'amount',
                ],
                'parent' => ['contract_change_id', 'contract_changes'],
            ],
            'work_sections' => [
                'model' => Models\WorkSection::class,
                'roles' => self::CONTRACT,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'site_id', 'company_id', 'project_contract_id', 'division', 'code',
                    'name', 'contract_amount', 'sort_order', 'source',
                ],
                'parent' => ['project_contract_id', 'contracts'],
                'search' => ['code', 'name'],
            ],
            'work_section_sheets' => [
                'model' => Models\WorkSectionSheet::class,
                'roles' => self::CONTRACT,
                'scope' => 'global',
                'fields' => [
                    'id', 'work_section_id', 'sheet_no', 'sort_order', 'created_by_id',
                ],
                'parent' => ['work_section_id', 'work_sections'],
                'search' => ['sheet_no'],
            ],
            'drawing_marks' => [
                'model' => Models\DrawingMark::class,
                'roles' => self::CONTRACT,
                'scope' => 'site',
                'fields' => [
                    'id', 'site_id', 'sheet_no', 'work_section_id', 'contract_boq_line_id', 'claim_work_record_id',
                    'shape', 'points', 'label', 'created_by',
                ],
                'search' => ['sheet_no', 'label'],
            ],
            'material_claim_links' => [
                'model' => Models\MaterialClaimLink::class,
                'roles' => self::CONTRACT,
                'scope' => 'site',
                'fields' => [
                    'id', 'site_id', 'name_key', 'contract_boq_line_id', 'factor', 'receipt_unit',
                    'created_by', 'times_used',
                ],
                'parent' => ['contract_boq_line_id', 'contract_boq_lines'],
            ],
            'wbs_items' => [
                'model' => Models\WbsItem::class,
                'roles' => self::INTERNAL,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'project_id', 'project_code', 'parent_id', 'level', 'wbs_code',
                    'node_no', 'activity_id', 'name', 'company', 'trade', 'crew_text',
                    'crew_size', 'crew_roles', 'equipment', 'status', 'ehs', 'manhours',
                    'days', 'planned_start', 'planned_end', 'actual_start', 'actual_end', 'preds',
                    'float_days', 'is_critical', 'late_start', 'late_end', 'progress', 'hold_point',
                    'hold_released', 'hold_note', 'submittal_seqs', 'committed_week', 'incomplete_reason', 'sort_order',
                    'company_id', 'site_id', 'source',
                ],
                'search' => ['wbs_code', 'activity_id', 'name', 'project_code'],
            ],
            'wbs_costs' => [
                'model' => Models\WbsItem::class,
                'roles' => self::MONEY,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'project_id', 'project_code', 'wbs_code', 'name', 'company_id',
                    'site_id', 'planned_cost',
                ],
                'search' => ['wbs_code', 'project_code'],
            ],
            'wbs_manuals' => [
                'model' => Models\WbsManual::class,
                'roles' => self::SITE,
                'scope' => 'site',
                'fields' => [
                    'id', 'project_code', 'site_id', 'original_name', 'mime_type', 'size',
                    'engine', 'status', 'stages', 'tasks', 'subtasks', 'analyzed_by_id',
                    'analyzed_at',
                ],
                'search' => ['project_code', 'original_name'],
                'attachments' => ['original' => ['path' => 'path', 'disk' => 'disk', 'default_disk' => 'public', 'name' => 'original_name', 'mime' => 'mime_type', 'size' => 'size']],
            ],
            'wbs_photos' => [
                'model' => Models\WbsPhoto::class,
                'roles' => self::INTERNAL,
                'scope' => 'site',
                'fields' => [
                    'id', 'wbs_code', 'project_code', 'site_id', 'photo_date', 'caption',
                    'mime', 'width', 'height', 'bytes', 'original_bytes', 'original_name',
                    'uploaded_by_id',
                ],
                'search' => ['wbs_code', 'caption'],
                'attachments' => ['photo' => ['path' => 'path', 'disk' => 'disk', 'default_disk' => 'local', 'name' => 'original_name', 'mime' => 'mime', 'size' => 'bytes', 'disk_config' => 'filesystems.wbs_photos_disk'], 'original' => ['path' => 'original_path', 'disk' => 'disk', 'default_disk' => 'local', 'name' => 'original_name', 'mime' => 'mime', 'size' => 'original_bytes', 'disk_config' => 'filesystems.wbs_photos_disk'], 'thumbnail' => ['path' => 'thumb_path', 'disk' => 'disk', 'default_disk' => 'local', 'mime' => 'mime', 'disk_config' => 'filesystems.wbs_photos_disk']],
            ],
            'week_board_lines' => [
                'model' => Models\WeekBoardLine::class,
                'roles' => self::INTERNAL,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'company_id', 'site_id', 'week_start', 'trade', 'task',
                    'headcount', 'status', 'reason', 'note', 'wbs_codes', 'sort_order',
                    'carried_from_id', 'created_by_id', 'updated_by_id', 'done_at', 'auto_source', 'auto_quote',
                    'auto_batch_id', 'auto_at',
                ],
                'search' => ['trade', 'task', 'note'],
            ],
            'submittals' => [
                'model' => Models\Submittal::class,
                'roles' => self::REGISTER,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'company_id', 'site_id', 'project_id', 'seq', 'csi',
                    'section', 'category', 'title', 'gate', 'status', 'assignee',
                    'planned_on', 'submitted_on', 'approved_on', 'notes', 'vendor_name', 'vendor_email',
                    'vendor_phone', 'recipient_name', 'recipient_email', 'confidence', 'needs_review', 'review_reason',
                    'source_document_id', 'extracted_by', 'source_excerpt',
                ],
                'search' => ['title', 'csi', 'section'],
            ],
            'submittal_events' => [
                'model' => Models\SubmittalEvent::class,
                'roles' => self::REGISTER,
                'scope' => 'global',
                'fields' => [
                    'id', 'submittal_id', 'kind', 'channel', 'to_name', 'to_email',
                    'subject', 'intelligent_document_id', 'created_by', 'mail_message_id',
                ],
                'parent' => ['submittal_id', 'submittals'],
                'search' => ['subject'],
            ],
            'boq_items' => [
                'model' => Models\BoqItem::class,
                'roles' => self::REGISTER,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'company_id', 'site_id', 'project_id', 'seq', 'discipline_code',
                    'discipline', 'name_kr', 'name_en', 'spec', 'unit', 'qty',
                    'qty_basis', 'source', 'note', 'flagged', 'wbs_activity_id', 'confidence',
                    'needs_review', 'review_reason', 'source_document_id', 'extracted_by',
                ],
                'search' => ['name_kr', 'name_en', 'spec'],
            ],
            'boq_costs' => [
                'model' => Models\BoqItem::class,
                'roles' => self::CONTRACT,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'company_id', 'site_id', 'project_id', 'seq', 'name_kr',
                    'name_en', 'unit', 'qty', 'unit_price', 'amount',
                ],
                'search' => ['name_kr', 'name_en'],
            ],
            'daily_closings' => [
                'model' => Models\DailyClosingReport::class,
                'roles' => self::SITE,
                'scope' => 'site',
                'fields' => [
                    'id', 'site_id', 'report_date', 'status', 'narrative', 'closed_by_id',
                    'closed_at', 'weather', 'temperature', 'trades', 'work_title', 'work_today',
                    'work_tomorrow', 'progress_rate', 'tbm_completed', 'safety_checks', 'safety_notes', 'field_status',
                    'field_submitted_at', 'plan_status', 'plan_submitted_at', 'plan_by_id',
                ],
                'search' => ['work_title', 'work_today', 'narrative'],
            ],
            'daily_trade_reports' => [
                'model' => Models\DailyTradeReport::class,
                'roles' => self::SITE,
                'scope' => 'site',
                'fields' => [
                    'id', 'site_id', 'work_date', 'trade', 'kind', 'status',
                    'submitted_by_id', 'submitted_at', 'reopen_reason', 'applied_count', 'held_count', 'reflected_at',
                    'reflection_note',
                ],
                'search' => ['trade', 'reflection_note'],
            ],
            'ops_batches' => [
                'model' => Models\OpsIntakeBatch::class,
                'roles' => self::SYSTEM,
                'scope' => 'site',
                'fields' => [
                    'id', 'site_id', 'created_by_id', 'source', 'communication_message_id', 'raw_text',
                    'original_text', 'edited_by_id', 'edited_at', 'image_count', 'parsed_count', 'actionable_count',
                    'noise_count', 'status', 'analyzed_at', 'auto_applied', 'evidence_filed', 'week_board_updated',
                    'daily_trade_report_id',
                ],
                'search' => ['raw_text'],
            ],
            'ops_items' => [
                'model' => Models\OpsIntakeItem::class,
                'roles' => self::SYSTEM,
                'scope' => 'site',
                'fields' => [
                    'id', 'site_id', 'ops_intake_batch_id', 'project_code', 'source', 'communication_message_id',
                    'created_by_id', 'raw_text', 'speaker', 'occurred_on', 'category', 'confidence',
                    'summary', 'target_type', 'target_code', 'target_name', 'question', 'conflict',
                    'status', 'applied_at', 'applied_by_id', 'result_note', 'applied_via',
                ],
                'parent' => ['ops_intake_batch_id', 'ops_batches'],
                'search' => ['summary', 'raw_text', 'target_name'],
            ],
            'ops_actions' => [
                'model' => Models\OpsActionItem::class,
                'roles' => self::SITE,
                'scope' => 'site',
                'fields' => [
                    'id', 'site_id', 'ops_intake_batch_id', 'ops_intake_item_id', 'kind', 'title',
                    'detail', 'requester', 'assignee', 'due_on', 'occurred_on', 'status',
                    'is_blocker', 'done_at', 'done_by_id',
                ],
                'search' => ['title', 'detail', 'assignee'],
            ],
            'ops_labor' => [
                'model' => Models\OpsLaborReport::class,
                'roles' => self::SITE,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'site_id', 'ops_intake_batch_id', 'ops_intake_item_id', 'company_id', 'work_date',
                    'company_label', 'trade', 'headcount', 'note', 'reported_by_id',
                ],
                'search' => ['company_label', 'trade', 'note'],
            ],
            'meetings' => [
                'model' => Models\OpsMeeting::class,
                'roles' => self::MEETING,
                'scope' => 'site',
                'fields' => [
                    'id', 'site_id', 'created_by_id', 'title', 'meeting_on', 'participants',
                    'status', 'audio_mime', 'audio_bytes', 'transcripts', 'ops_intake_batch_id', 'queued_at',
                    'started_at', 'finished_at',
                ],
                'search' => ['title', 'participants'],
                'attachments' => ['audio' => ['path' => 'audio_path', 'disk' => 'disk', 'default_disk' => 'local', 'mime' => 'audio_mime', 'size' => 'audio_bytes', 'disk_config' => 'meetings.disk']],
            ],
            'report_dispatches' => [
                'model' => Models\ReportDispatch::class,
                'roles' => self::SITE,
                'scope' => 'global',
                'fields' => [
                    'id', 'daily_closing_report_id', 'kind', 'channel', 'to_email', 'to_name',
                    'subject', 'status', 'intelligent_document_id', 'created_by_id', 'sent_at', 'mail_message_id',
                ],
                'parent' => ['daily_closing_report_id', 'daily_closings'],
                'search' => ['subject', 'to_email'],
            ],
            'report_recipients' => [
                'model' => Models\ReportRecipient::class,
                'roles' => self::SYSTEM,
                'scope' => 'site',
                'fields' => [
                    'id', 'site_id', 'name', 'email', 'org', 'role',
                    'receives', 'is_cc', 'active', 'created_by_id',
                ],
                'search' => ['name', 'email'],
            ],
            'equipment' => [
                'model' => Models\Equipment::class,
                'roles' => self::INTERNAL,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'equipment_code', 'company_id', 'site_id', 'project_id', 'purchased_for_site_id',
                    'team_id', 'employee_id', 'equipment_type', 'category_group', 'trade', 'model',
                    'vendor', 'acquisition_type', 'inspection_due_on', 'rent_start', 'rent_end', 'status',
                    'registration_method', 'quantity', 'is_bulk', 'last_checked_at',
                ],
                'search' => ['equipment_code', 'equipment_type', 'model', 'vendor'],
            ],
            'equipment_costs' => [
                'model' => Models\Equipment::class,
                'roles' => self::MONEY,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'equipment_code', 'company_id', 'site_id', 'asset_value', 'daily_rate',
                    'delivery_fee',
                ],
                'search' => ['equipment_code'],
            ],
            'equipment_rentals' => [
                'model' => Models\EquipmentRental::class,
                'roles' => self::INTERNAL,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'equipment_id', 'company_id', 'team_id', 'employee_id', 'site_id',
                    'rented_at', 'returned_at', 'status', 'notes',
                ],
                'parent' => ['equipment_id', 'equipment'],
            ],
            'equipment_check_templates' => [
                'model' => Models\EquipmentChecklistTemplate::class,
                'roles' => self::SYSTEM,
                'scope' => 'template',
                'fields' => [
                    'id', 'company_id', 'site_id', 'scope_type', 'scope_value', 'name',
                    'stage', 'status', 'is_default', 'sort_order',
                ],
                'search' => ['name'],
            ],
            'equipment_check_items' => [
                'model' => Models\EquipmentChecklistItem::class,
                'roles' => self::SYSTEM,
                'scope' => 'global',
                'fields' => [
                    'id', 'equipment_checklist_template_id', 'sort_order', 'label_ko', 'label_en', 'label_es',
                    'help_ko', 'help_en', 'help_es', 'severity', 'requires_photo_on_fail', 'stage',
                    'status',
                ],
                'parent' => ['equipment_checklist_template_id', 'equipment_check_templates'],
                'search' => ['label_ko', 'label_en'],
            ],
            'equipment_check_logs' => [
                'model' => Models\EquipmentChecklistLog::class,
                'roles' => self::CHECKS,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'equipment_id', 'equipment_checklist_template_id', 'equipment_rental_id', 'company_id', 'site_id',
                    'team_id', 'employee_id', 'user_id', 'stage', 'result', 'failed_count',
                    'critical_failed_count', 'notes', 'latitude', 'longitude', 'accuracy_m', 'submitted_at',
                ],
                'parent' => ['equipment_id', 'equipment'],
            ],
            'item_categories' => [
                'model' => Models\ItemCategory::class,
                'roles' => self::CONTRACT,
                'scope' => 'company',
                'fields' => [
                    'id', 'company_id', 'parent_id', 'name', 'code', 'sort',
                    'status',
                ],
                'search' => ['code', 'name'],
            ],
            'items' => [
                'model' => Models\Item::class,
                'roles' => self::CONTRACT,
                'scope' => 'company',
                'fields' => [
                    'id', 'company_id', 'item_category_id', 'code', 'name', 'unit',
                    'standard_cost', 'description', 'status',
                ],
                'search' => ['code', 'name', 'description'],
            ],
            'vehicles' => [
                'model' => Models\Vehicle::class,
                'roles' => self::INTERNAL,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'vehicle_code', 'company_id', 'site_id', 'team_id', 'plate_number',
                    'vehicle_type', 'model', 'vendor', 'rent_start', 'rent_end', 'insurance_expiry',
                    'current_mileage', 'next_oil_change_mileage', 'status', 'registration_method',
                ],
                'search' => ['vehicle_code', 'plate_number', 'model'],
            ],
            'vehicle_costs' => [
                'model' => Models\Vehicle::class,
                'roles' => self::MONEY,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'vehicle_code', 'company_id', 'site_id', 'monthly_rate',
                ],
                'search' => ['vehicle_code'],
            ],
            'vehicle_rentals' => [
                'model' => Models\VehicleRental::class,
                'roles' => self::INTERNAL,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'vehicle_id', 'employee_id', 'company_id', 'site_id', 'rented_at',
                    'returned_at', 'start_mileage', 'end_mileage', 'status', 'notes',
                ],
                'parent' => ['vehicle_id', 'vehicles'],
            ],
            'housing' => [
                'model' => Models\Housing::class,
                'roles' => self::INTERNAL,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'company_id', 'site_id', 'code', 'name', 'address',
                    'beds', 'occupied', 'status',
                ],
                'search' => ['code', 'name', 'address'],
            ],
            'housing_costs' => [
                'model' => Models\Housing::class,
                'roles' => self::MONEY,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'company_id', 'site_id', 'code', 'name', 'monthly_rent',
                ],
                'search' => ['code', 'name'],
            ],
            'safety_work' => [
                'model' => Models\SafetyWorkItem::class,
                'roles' => self::INTERNAL,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'work_code', 'company_id', 'site_id', 'team_id', 'project',
                    'location', 'title', 'work_date', 'wbs_code', 'crew', 'unit',
                    'planned_qty', 'done_qty', 'total_qty', 'progress', 'due_label', 'plan_status',
                    'tbm_status', 'close_status', 'progress_status', 'work_text', 'close_text', 'created_by_id',
                ],
                'search' => ['work_code', 'title', 'location'],
            ],
            'safety_issues' => [
                'model' => Models\SafetyWorkIssue::class,
                'roles' => self::INTERNAL,
                'scope' => 'global',
                'fields' => [
                    'id', 'safety_work_item_id', 'type', 'body', 'owner', 'status',
                    'sort_order',
                ],
                'parent' => ['safety_work_item_id', 'safety_work'],
                'search' => ['body', 'owner'],
            ],
            'safety_signatures' => [
                'model' => Models\SafetyWorkSignature::class,
                'roles' => self::INTERNAL,
                'scope' => 'global',
                'fields' => [
                    'id', 'safety_work_item_id', 'employee_id', 'name', 'role', 'signed',
                    'signed_at', 'sort_order',
                ],
                'parent' => ['safety_work_item_id', 'safety_work'],
                'search' => ['name'],
            ],
            'safety_permits' => [
                'model' => Models\SafetyPermit::class,
                'roles' => self::INTERNAL,
                'scope' => 'site',
                'fields' => [
                    'id', 'safety_work_item_id', 'wbs_code', 'site_id', 'permit_no', 'type',
                    'title', 'precautions', 'valid_from', 'valid_to', 'status', 'issued_by_id',
                    'issued_at', 'approved_by_id', 'approved_at', 'signed_by', 'signed_at',
                ],
                'parent' => ['safety_work_item_id', 'safety_work'],
                'search' => ['permit_no', 'title'],
            ],
            'material_receipts' => [
                'model' => Models\MaterialReceipt::class,
                'roles' => self::RECEIVING,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'company_id', 'site_id', 'received_on', 'vendor', 'vendor_id',
                    'po_no', 'delivery_no', 'note', 'status', 'photo_name', 'created_by_id',
                    'confirmed_by_id', 'confirmed_at',
                ],
                'search' => ['vendor', 'po_no', 'delivery_no'],
                'attachments' => ['delivery' => ['path' => 'photo_path', 'disk' => 'photo_disk', 'default_disk' => 'public', 'name' => 'photo_name']],
            ],
            'material_receipt_lines' => [
                'model' => Models\MaterialReceiptLine::class,
                'roles' => self::RECEIVING,
                'scope' => 'global',
                'fields' => [
                    'id', 'material_receipt_id', 'item_id', 'name', 'quantity', 'unit',
                    'unit_price', 'note', 'seq',
                ],
                'parent' => ['material_receipt_id', 'material_receipts'],
                'search' => ['name', 'note'],
            ],
            'procurement' => [
                'model' => Models\ProcurementItem::class,
                'roles' => self::BUYER,
                'scope' => 'site',
                'fields' => [
                    'id', 'project_code', 'site_id', 'wbs_code', 'wbs_item_id', 'item_id',
                    'status', 'vendor', 'vendor_id', 'contract_id', 'po_no', 'amount',
                    'currency', 'ordered_on', 'eta', 'note', 'created_by_id', 'document_name',
                ],
                'search' => ['project_code', 'wbs_code', 'po_no', 'vendor'],
                'attachments' => ['document' => ['path' => 'document_path', 'disk' => 'document_disk', 'default_disk' => 'public', 'name' => 'document_name']],
            ],
            'purchase_requests' => [
                'model' => Models\PurchaseRequest::class,
                'roles' => self::BUYER,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'company_id', 'site_id', 'requested_by_id', 'status', 'version',
                    'need_by', 'note', 'reason', 'eta',
                ],
                'search' => ['note'],
            ],
            'purchase_request_lines' => [
                'model' => Models\PurchaseRequestLine::class,
                'roles' => self::BUYER,
                'scope' => 'global',
                'fields' => [
                    'id', 'purchase_request_id', 'name', 'specification', 'quantity', 'unit',
                    'seq',
                ],
                'parent' => ['purchase_request_id', 'purchase_requests'],
                'search' => ['name', 'specification'],
            ],
            'purchase_events' => [
                'model' => Models\PurchaseRequestEvent::class,
                'roles' => self::BUYER,
                'scope' => 'global',
                'fields' => [
                    'id', 'purchase_request_id', 'actor_id', 'action', 'status', 'message',
                ],
                'parent' => ['purchase_request_id', 'purchase_requests'],
                'search' => ['message'],
            ],
            'purchase_attachments' => [
                'model' => Models\PurchaseRequestAttachment::class,
                'roles' => self::BUYER,
                'scope' => 'global',
                'fields' => [
                    'id', 'purchase_request_id', 'uploaded_by_id', 'purpose', 'name', 'mime',
                    'size',
                ],
                'parent' => ['purchase_request_id', 'purchase_requests'],
                'search' => ['name'],
                'attachments' => ['original' => ['path' => 'path', 'disk' => 'disk', 'default_disk' => 'local', 'name' => 'name', 'mime' => 'mime', 'size' => 'size']],
            ],
            'purchase_orders' => [
                'model' => Models\PurchaseRequestOrder::class,
                'roles' => self::BUYER,
                'scope' => 'global',
                'fields' => [
                    'id', 'purchase_request_id', 'ordered_by_id', 'evidence_id', 'order_number', 'vendor_id',
                    'vendor', 'amount', 'currency', 'ordered_at',
                ],
                'parent' => ['purchase_request_id', 'purchase_requests'],
                'search' => ['order_number', 'vendor'],
            ],
            'purchase_order_lines' => [
                'model' => Models\PurchaseRequestOrderLine::class,
                'roles' => self::BUYER,
                'scope' => 'global',
                'fields' => [
                    'id', 'purchase_request_order_id', 'purchase_request_line_id', 'quantity',
                ],
                'parent' => ['purchase_request_order_id', 'purchase_orders'],
            ],
            'purchase_receipt_allocations' => [
                'model' => Models\PurchaseReceiptAllocation::class,
                'roles' => self::BUYER,
                'scope' => 'global',
                'fields' => [
                    'id', 'purchase_request_line_id', 'material_receipt_line_id', 'quantity', 'allocated_by_id',
                ],
                'parent' => ['purchase_request_line_id', 'purchase_request_lines'],
            ],
            'documents' => [
                'model' => Models\IntelligentDocument::class,
                'roles' => self::DOCUMENTS,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'uuid', 'company_id', 'site_id', 'project_id', 'project_contract_id',
                    'supersedes_document_id', 'uploaded_by', 'owner_user_id', 'reviewed_by', 'source', 'email_thread_id',
                    'original_file_name', 'mime_type', 'extension', 'file_size', 'title', 'category',
                    'document_type', 'discipline', 'direction', 'document_number', 'revision', 'sender',
                    'recipients', 'status', 'confidentiality', 'access_level', 'tags', 'keywords',
                    'summary', 'key_facts', 'extracted_text', 'document_date', 'effective_on', 'expires_on',
                    'response_due_on', 'received_at', 'ai_status', 'ai_engine', 'ai_model', 'ai_confidence',
                    'analyzed_at', 'reviewed_at',
                ],
                'privacy' => 'document',
                'search' => ['title', 'document_number', 'original_file_name', 'summary'],
                'attachments' => ['original' => ['path' => 'file_path', 'disk' => 'disk', 'default_disk' => 'local', 'name' => 'original_file_name', 'mime' => 'mime_type', 'size' => 'file_size', 'disk_config' => 'document-intelligence.disk']],
            ],
            'integrated_documents' => [
                'model' => Models\IntegratedDocument::class,
                'roles' => self::DOCUMENTS,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'site_id', 'project_code', 'procurement_item_id', 'employee_id', 'company_id',
                    'wbs_code', 'folder_code', 'document_type', 'type_confidence', 'folder_confidence', 'title',
                    'document_number', 'issuer', 'counterparty', 'issued_on', 'effective_on', 'expires_on',
                    'amount', 'currency', 'summary', 'body_text', 'fields', 'tags',
                    'duplicate_note', 'duplicate_of_id', 'source_document_id', 'original_name', 'mime_type', 'size',
                    'status', 'uploaded_by_id', 'uploaded_by_label', 'engine', 'model', 'analyzed_at',
                ],
                'privacy' => 'integrated_document',
                'search' => ['title', 'document_number', 'issuer', 'body_text'],
                'attachments' => ['original' => ['path' => 'path', 'disk' => 'disk', 'default_disk' => 'public', 'name' => 'original_name', 'mime' => 'mime_type', 'size' => 'size', 'disk_config' => 'filesystems.documents_disk']],
            ],
            'document_actions' => [
                'model' => Models\DocumentActionItem::class,
                'roles' => self::DOCUMENTS,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'intelligent_document_id', 'company_id', 'site_id', 'project_id', 'assigned_user_id',
                    'action_type', 'related_module', 'severity', 'status', 'title', 'details',
                    'recommended_action', 'source_excerpt', 'due_at', 'remind_at', 'completed_at', 'confidence',
                ],
                'parent' => ['intelligent_document_id', 'documents'],
                'search' => ['title', 'details'],
            ],
            'document_folders' => [
                'model' => Models\DocumentFolder::class,
                'roles' => self::SYSTEM,
                'scope' => 'global',
                'fields' => [
                    'id', 'code', 'name', 'color', 'sort_order', 'created_by_id',
                ],
                'search' => ['code', 'name'],
            ],
            'knowledge_facts' => [
                'model' => Models\KnowledgeFact::class,
                'roles' => self::DOCUMENTS,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'company_id', 'site_id', 'project_id', 'intelligent_document_id', 'doc_title',
                    'document_type', 'document_number', 'revision', 'document_date', 'fact', 'retired_at',
                    'retired_by_document_id',
                ],
                'parent' => ['intelligent_document_id', 'documents'],
                'search' => ['doc_title', 'fact'],
            ],
            'drawing_sheets' => [
                'model' => Models\DrawingSheet::class,
                'roles' => self::DOCUMENTS,
                'scope' => 'site',
                'fields' => [
                    'id', 'intelligent_document_id', 'site_id', 'page_no', 'sheet_no', 'title',
                    'discipline', 'manual', 'text_source', 'text', 'status', 'ai_model',
                    'width_pt', 'height_pt', 'feet_per_point', 'scale_label', 'read_at',
                ],
                'parent' => ['intelligent_document_id', 'documents'],
                'search' => ['sheet_no', 'title', 'text'],
                'attachments' => ['thumbnail' => ['path' => 'thumb_path', 'disk' => 'thumb_disk', 'default_disk' => 'public', 'disk_config' => 'filesystems.documents_disk']],
            ],
            'field_drawings' => [
                'model' => Models\FieldDrawing::class,
                'roles' => self::SYSTEM,
                'scope' => 'site',
                'fields' => [
                    'id', 'site_id', 'drawing_no', 'title', 'category', 'version',
                    'file_mime', 'summary', 'specs', 'safety_notes', 'status', 'ai_model',
                    'analyzed_at',
                ],
                'search' => ['drawing_no', 'title'],
            ],
            'field_drawing_messages' => [
                'model' => Models\FieldDrawingMessage::class,
                'roles' => self::SYSTEM,
                'scope' => 'global',
                'fields' => [
                    'id', 'field_drawing_id', 'role', 'content',
                ],
                'parent' => ['field_drawing_id', 'field_drawings'],
                'search' => ['content'],
            ],
            'correspondence_threads' => [
                'model' => Models\MailThread::class,
                'roles' => self::SITE,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'uuid', 'company_id', 'site_id', 'project_id', 'ref_code',
                    'related_type', 'related_id', 'subject', 'counterparty_name', 'counterparty_email', 'counterparty_org',
                    'status', 'confidentiality', 'response_due_on', 'first_sent_at', 'last_message_at', 'message_count',
                    'closed_at', 'created_by_id',
                ],
                'search' => ['ref_code', 'subject', 'counterparty_name'],
            ],
            'correspondence_messages' => [
                'model' => Models\MailMessage::class,
                'roles' => self::SITE,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'mail_thread_id', 'company_id', 'site_id', 'direction', 'channel',
                    'status', 'from_address', 'from_name', 'to_addresses', 'cc_addresses', 'subject',
                    'body_text', 'snippet', 'attachment_count', 'delivered_at', 'bounced_at', 'bounce_reason',
                    'occurred_at', 'created_by_id',
                ],
                'parent' => ['mail_thread_id', 'correspondence_threads'],
                'search' => ['subject', 'body_text'],
            ],
            'email_threads' => [
                'model' => Models\EmailThread::class,
                'roles' => self::DOCUMENTS,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'owner_user_id', 'company_id', 'site_id', 'project_id', 'subject',
                    'participants', 'first_message_at', 'last_message_at', 'summary_ko', 'classification', 'needs_response',
                    'response_due_on', 'ai_confidence', 'visibility', 'shared_by', 'shared_at', 'status',
                ],
                'privacy' => 'email_thread',
                'search' => ['subject', 'summary_ko'],
            ],
            'email_messages' => [
                'model' => Models\EmailMessage::class,
                'roles' => self::DOCUMENTS,
                'scope' => 'global',
                'fields' => [
                    'id', 'email_thread_id', 'intelligent_document_id', 'direction', 'sender', 'recipients',
                    'sent_at', 'received_at', 'body_preview', 'has_attachments',
                ],
                'parent' => ['email_thread_id', 'email_threads'],
                'search' => ['body_preview'],
            ],
            'communication_rooms' => [
                'model' => Models\CommunicationRoom::class,
                'roles' => self::ERP,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'company_id', 'site_id', 'team_id', 'type', 'scope',
                    'name', 'description', 'status', 'is_read_only', 'created_by_id', 'last_message_at',
                ],
                'privacy' => 'communication_room',
                'search' => ['name', 'description'],
            ],
            'communication_messages' => [
                'model' => Models\CommunicationMessage::class,
                'roles' => self::ERP,
                'scope' => 'company_site',
                'fields' => [
                    'id', 'communication_room_id', 'company_id', 'site_id', 'team_id', 'parent_id',
                    'sender_user_id', 'sender_employee_id', 'kind', 'title', 'body', 'related_type',
                    'related_id', 'is_pinned', 'priority', 'status', 'sent_at', 'edited_at',
                ],
                'parent' => ['communication_room_id', 'communication_rooms'],
                'privacy' => 'communication_message',
                'search' => ['title', 'body'],
            ],
            'communication_files' => [
                'model' => Models\CommunicationMessageFile::class,
                'roles' => self::ERP,
                'scope' => 'global',
                'fields' => [
                    'id', 'communication_message_id', 'intelligent_document_id', 'original_name', 'mime_type', 'extension',
                    'file_size', 'kind',
                ],
                'parent' => ['communication_message_id', 'communication_messages'],
                'search' => ['original_name'],
                'attachments' => ['original' => ['path' => 'path', 'disk' => 'disk', 'default_disk' => 'local', 'name' => 'original_name', 'mime' => 'mime_type', 'size' => 'file_size', 'disk_config' => 'document-intelligence.disk']],
            ],
            'communication_members' => [
                'model' => Models\CommunicationRoomMember::class,
                'roles' => self::ERP,
                'scope' => 'global',
                'fields' => [
                    'id', 'communication_room_id', 'user_id', 'employee_id', 'role', 'status',
                    'joined_at',
                ],
                'parent' => ['communication_room_id', 'communication_rooms'],
            ],
            'communication_reactions' => [
                'model' => Models\CommunicationMessageReaction::class,
                'roles' => self::ERP,
                'scope' => 'global',
                'fields' => [
                    'id', 'communication_message_id', 'communication_room_id', 'user_id', 'employee_id', 'emoji',
                ],
                'parent' => ['communication_message_id', 'communication_messages'],
            ],
            'personal_notifications' => [
                'model' => Models\CommunicationNotification::class,
                'roles' => self::ERP,
                'scope' => 'own_user',
                'fields' => [
                    'id', 'user_id', 'employee_id', 'communication_room_id', 'communication_message_id', 'type',
                    'title', 'body', 'read_at',
                ],
                'parent' => ['communication_message_id', 'communication_messages'],
                'search' => ['title', 'body'],
            ],
            'personal_alerts' => [
                'model' => Models\UnifiedAlert::class,
                'roles' => self::ERP,
                'scope' => 'personal_alert',
                'fields' => [
                    'id', 'alert_code', 'company_id', 'site_id', 'project_id', 'user_id',
                    'employee_id', 'source_module', 'event_type', 'severity', 'status', 'title',
                    'assignee', 'occurred_at', 'due_at', 'resolved_at', 'last_detected_at',
                ],
                'search' => ['title'],
            ],
            'reminder_recipients' => [
                'model' => Models\KakaoRecipient::class,
                'roles' => self::SYSTEM,
                'scope' => 'site',
                'fields' => [
                    'id', 'employee_id', 'site_id', 'enabled', 'consented_at', 'weekdays',
                    'clock_in', 'clock_out', 'daily_report', 'updated_by',
                ],
                'parent' => ['employee_id', 'employees'],
            ],
            'reminder_deliveries' => [
                'model' => Models\KakaoDelivery::class,
                'roles' => self::SYSTEM,
                'scope' => 'site',
                'fields' => [
                    'id', 'employee_id', 'site_id', 'work_date', 'kind', 'status',
                    'reason', 'provider_code',
                ],
                'parent' => ['employee_id', 'employees'],
            ],
        ];
    }
}
