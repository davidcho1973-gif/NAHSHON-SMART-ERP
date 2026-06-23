<?php

namespace App\Support;

class FinanceChartOfAccounts
{
    public const FALLBACK_ACCOUNT = '9999 Needs Review';

    /**
     * @return list<string>
     */
    public static function accounts(): array
    {
        return [
            '5101 Permanent Staff Wages',
            '5102 Temporary / Daily Labor',
            '5103 Overtime Pay',
            '5104 Night Shift Allowance',
            '5105 Site Allowance',
            '5106 Hazard Pay',
            '5107 Holiday Pay',
            '5108 Meal Allowance - Labor',
            '5109 Transportation Allowance - Labor',
            '5201 Concrete & Cement',
            '5202 Reinforcement Bar / Rebar',
            '5203 Structural Steel',
            '5204 Lumber & Timber',
            '5205 Mechanical Piping',
            '5206 Electrical Cable & Conduit',
            '5207 Plumbing Fixtures',
            '5208 HVAC Equipment & Duct',
            '5209 Insulation Materials',
            '5210 Waterproofing Materials',
            '5211 Finishing Materials',
            '5212 Paint & Coatings',
            '5213 Fasteners & Hardware',
            '5214 Safety Materials',
            '5215 Consumables',
            '5216 Fuel & Lubricants',
            '5301 Civil Works Subcontract',
            '5302 Structural Works Subcontract',
            '5303 Mechanical Works Subcontract',
            '5304 Electrical Works Subcontract',
            '5305 Plumbing Works Subcontract',
            '5306 HVAC Works Subcontract',
            '5307 Finishing Works Subcontract',
            '5308 Demolition Subcontract',
            '5309 Excavation Subcontract',
            '5310 Scaffolding Subcontract',
            '5311 Painting Subcontract',
            '5312 Landscaping Subcontract',
            '5313 Specialty Subcontract',
            '5401 Equipment Rental - Crane',
            '5402 Equipment Rental - Excavator',
            '5403 Equipment Rental - Forklift',
            '5404 Equipment Rental - Concrete Pump',
            '5405 Equipment Rental - Scaffolding',
            '5406 Equipment Rental - Generator',
            '5407 Equipment Rental - Others',
            '5408 Equipment Operation Cost',
            '5409 Equipment Maintenance & Repair',
            '5410 Equipment Transportation',
            '5411 Equipment Fuel',
            '6101 Site Manager Salary',
            '6102 Site Engineer Salary',
            '6103 Site Supervisor Salary',
            '6104 Site Admin Salary',
            '6105 Site Staff Bonus',
            '6106 Site Staff Overtime',
            '6201 Temporary Office Setup',
            '6202 Temporary Office Rent',
            '6203 Temporary Accommodation',
            '6204 Temporary Fencing & Hoarding',
            '6205 Temporary Road',
            '6206 Temporary Power & Wiring',
            '6207 Temporary Water Supply',
            '6208 Temporary Sanitation',
            '6209 Temporary Signage',
            '6210 Temporary Storage',
            '6301 Electricity - Site',
            '6302 Water - Site',
            '6303 Gas - Site',
            '6304 Waste Disposal',
            '6305 Sewage Disposal',
            '6401 Material Transportation',
            '6402 Worker Shuttle Bus',
            '6403 Site Vehicle Fuel',
            '6404 Site Vehicle Maintenance',
            '6405 Parking',
            '6501 Site Office Supplies',
            '6502 Site Communication - Phone',
            '6503 Site Internet',
            '6504 Printing & Copying',
            '6505 Postage & Courier',
            '6506 Site Meals & Catering',
            '6507 Site Cleaning',
            '6508 Site Security',
            '6601 PPE - Hard Hat',
            '6602 PPE - Safety Vest',
            '6603 PPE - Safety Harness',
            '6604 PPE - Safety Boots',
            '6605 PPE - Gloves & Goggles',
            '6606 PPE - Respiratory Protection',
            '6607 Safety Signage & Barriers',
            '6608 First Aid Supplies',
            '6609 Fire Extinguisher',
            '6610 Safety Training Cost',
            '6611 Safety Officer Cost',
            '6612 Safety Inspection Fee',
            '6613 Drug & Alcohol Testing',
            '6701 Material Testing',
            '6702 Concrete Testing',
            '6703 Soil Testing',
            '6704 NDT Inspection',
            '6705 Third-party Inspection',
            '6706 Quality Audit',
            '6707 As-Built Survey',
            '7101 Executive Salary',
            '7102 Management Salary',
            '7103 Admin Staff Salary',
            '7104 Annual Bonus',
            '7105 Retirement Benefit',
            '7106 Employee Health Insurance',
            '7107 Employee Pension',
            '7108 Employment Insurance',
            '7109 Workers Compensation',
            '7110 Staff Training',
            '7111 Recruitment Cost',
            '7112 Welfare & Benefits',
            '7113 Staff Uniforms',
            '7201 Office Rent',
            '7202 Office Maintenance',
            '7203 Office Utilities - Electric',
            '7204 Office Utilities - Water',
            '7205 Office Utilities - Internet',
            '7206 Office Supplies',
            '7207 Printing & Stationery',
            '7208 Postage & Courier',
            '7209 Office Cleaning',
            '7210 Office Security',
            '7301 Domestic Travel - Airfare',
            '7302 International Travel - Airfare',
            '7303 Accommodation - Business Travel',
            '7304 Ground Transportation',
            '7305 Meal - Business Travel',
            '7306 Daily Allowance / Per Diem',
            '7307 Client Entertainment',
            '7308 Business Meal',
            '7309 Conference & Event',
            '7310 Gift & Hospitality',
            '7401 Company Vehicle Fuel',
            '7402 Company Vehicle Maintenance',
            '7403 Company Vehicle Insurance',
            '7404 Vehicle Lease / Rental',
            '7405 Toll & Parking',
            '7501 Legal Fees',
            '7502 Accounting & Audit Fees',
            '7503 Tax Advisory Fees',
            '7504 Engineering Consulting',
            '7505 Design & Architecture Fees',
            '7506 Surveying Fees',
            '7507 Environmental Consulting',
            '7508 IT Consulting',
            '7509 HR Consulting',
            '7601 Software Subscription',
            '7602 ERP System Cost',
            '7603 IT Maintenance',
            '7604 Cybersecurity',
            '7605 Cloud Services',
            '7606 Mobile Devices',
            '7607 IT Hardware',
            '7701 Advertising',
            '7702 Website Maintenance',
            '7703 Branding & Design',
            '7704 Tender & Bid Cost',
            '7705 Proposal Preparation',
            '7706 Market Research',
            '7707 Trade Show & Exhibition',
            '7708 Sponsorship',
            '7801 Construction All Risk Insurance',
            '7802 Public Liability Insurance',
            '7803 Workers Compensation Insurance',
            '7804 Professional Indemnity Insurance',
            '7805 Vehicle Insurance',
            '7806 Property Insurance',
            '7807 Performance Bond Premium',
            '7808 Advance Payment Bond Premium',
            '7809 Defect Liability Bond Premium',
            '7810 Bid Bond Premium',
            '7901 Construction Permit',
            '7902 Business License',
            '7903 Environmental Permit',
            '7904 Safety Certification',
            '7905 Professional License Renewal',
            '7906 Regulatory Filing Fee',
            '8101 Interest on Bank Loan',
            '8102 Interest on Overdraft',
            '8103 Interest on Bonds',
            '8104 Loan Arrangement Fee',
            '8105 Bank Service Charges',
            '8106 Letter of Credit Fee',
            '8107 Bank Guarantee Fee',
            '8201 Foreign Exchange Loss',
            '8202 Currency Conversion Fee',
            '8301 Depreciation - Buildings',
            '8302 Depreciation - Equipment',
            '8303 Depreciation - Vehicles',
            '8304 Depreciation - Office Equipment',
            '8305 Amortization - Software',
            '8306 Amortization - License',
            '9101 Corporate Income Tax',
            '9102 Local Income Tax',
            '9103 Property Tax',
            '9104 Acquisition Tax',
            '9105 VAT - Non-recoverable',
            '9106 Withholding Tax - Paid',
            '9107 Stamp Duty',
            '9108 Import Duties',
            self::FALLBACK_ACCOUNT,
        ];
    }

    public static function promptList(): string
    {
        return implode("\n", array_map(fn (string $account): string => '- ' . $account, self::accounts()));
    }

    public static function normalize(mixed $account, string $context = ''): string
    {
        $account = trim((string) $account);
        $accounts = self::accounts();

        foreach ($accounts as $candidate) {
            if (strcasecmp($account, $candidate) === 0) {
                return $candidate;
            }
        }

        if (preg_match('/^(\d{4})\b/', $account, $matches) === 1) {
            foreach ($accounts as $candidate) {
                if (str_starts_with($candidate, $matches[1] . ' ')) {
                    return $candidate;
                }
            }
        }

        $inferred = self::infer($account . ' ' . $context);
        if ($inferred !== null) {
            return $inferred;
        }

        return self::FALLBACK_ACCOUNT;
    }

    private static function infer(string $text): ?string
    {
        $text = strtolower($text);

        $rules = [
            '7404 Vehicle Lease / Rental' => ['hertz', 'enterprise', 'avis', 'budget', 'rental car', 'car rental', 'vehicle rental'],
            '6403 Site Vehicle Fuel' => ['marathon', 'shell', 'chevron', 'exxon', 'circle k', 'fuel', 'gasoline', 'regular gas', 'diesel', 'gas receipt'],
            '7405 Toll & Parking' => ['toll', 'parking'],
            '6404 Site Vehicle Maintenance' => ['auto repair', 'vehicle maintenance', 'oil change', 'tire'],
            '7303 Accommodation - Business Travel' => ['hotel', 'motel', 'lodging', 'accommodation'],
            '7301 Domestic Travel - Airfare' => ['airfare', 'airline', 'flight'],
            '7304 Ground Transportation' => ['uber', 'lyft', 'taxi', 'shuttle'],
            '7308 Business Meal' => ['restaurant', 'mcdonald', 'burger', 'meal', 'lunch', 'dinner', 'breakfast', 'catering'],
            '7206 Office Supplies' => ['office supplies', 'staples', 'officemax', 'paper', 'pen', 'printer'],
            '6501 Site Office Supplies' => ['site office supplies'],
            '7601 Software Subscription' => ['software', 'subscription', 'license', 'saas'],
            '7606 Mobile Devices' => ['mobile device', 'phone device', 'tablet'],
            '7205 Office Utilities - Internet' => ['internet'],
            '6502 Site Communication - Phone' => ['phone bill', 'mobile bill', 'communication'],
            '6301 Electricity - Site' => ['electricity', 'electric bill'],
            '6302 Water - Site' => ['water bill'],
            '6303 Gas - Site' => ['utility gas'],
            '6304 Waste Disposal' => ['waste', 'dumpster', 'trash'],
            '5216 Fuel & Lubricants' => ['lubricant', 'lubricants'],
            '5215 Consumables' => ['consumable', 'consumables'],
            '5214 Safety Materials' => ['safety material'],
            '6605 PPE - Gloves & Goggles' => ['gloves', 'goggles'],
            '6601 PPE - Hard Hat' => ['hard hat'],
            '6602 PPE - Safety Vest' => ['safety vest'],
            '6604 PPE - Safety Boots' => ['safety boots'],
        ];

        foreach ($rules as $account => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    return $account;
                }
            }
        }

        return null;
    }
}
