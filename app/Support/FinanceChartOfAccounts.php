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
            // 1. Fixed Assets (Capital Expenditures)
            '1501 Capitalized Vehicles',
            '1502 Machinery & Heavy Equipment',
            '1503 Computer Hardware ($2500+)',
            '1504 Office Furniture',

            // 2. Job Costs / Cost of Goods Sold (5xxx)
            '5101 Gross Wages - Field',
            '5102 Payroll Taxes - Field',
            '5103 Workers Comp Insurance - Field',
            '5201 Job Materials',
            '5202 Freight & Delivery',
            '5301 1099 Subcontractors',
            '5401 Equipment Rental',
            '5402 Fuel - Equipment',
            '5403 Small Tools & Consumables',
            '5501 Permits & Fees',
            '5502 Safety Equipment & Supplies',
            '5503 Crew Lodging & Housing',
            '5504 Jobsite Meals',

            // 3. Operating Expenses (OPEX / SG&A) (6xxx - 7xxx)
            '6101 Office Salaries',
            '6102 Employer Payroll Taxes - Office',
            '6103 Health Insurance',
            '6104 401k Matching',
            '6201 Fuel - Office Vehicles',
            '6202 Repairs & Maintenance - Auto',
            '6203 Auto Insurance',
            '6204 Vehicle Registration & Taxes (MVD)',
            '6301 Rent or Lease - Office',
            '6302 Utilities (SRP/APS/Water)',
            '6303 Telephone & Internet',
            '6304 Office Cleaning & Maintenance',
            '6401 Business Travel (Flights/Lodging)',
            '6402 Business Meals (50% deductible)',
            '6403 Office Meals (100% deductible)',
            '6501 Legal & Professional Fees',
            '6502 Merchant Fees & Bank Charges',
            '6503 Software & SaaS Subscriptions',
            '6601 Office Supplies',
            '6602 Postage & Delivery (FedEx/UPS)',
            '6603 Printing & Reproduction',
            '6701 Advertising',
            '6702 Promotional Items',
            '6801 General Liability Insurance',
            '6802 Professional Liability (E&O)',
            '6803 Commercial Property Insurance',
            '6901 Business Licenses & Permits (TPT)',
            '6902 Property Taxes',
            '7001 Training & Seminars',
            '7002 Dues & Subscriptions',

            // 4. Other Expenses (Non-Operating) (8xxx)
            '8101 Interest Expense',
            '8102 Penalties & Fines',
            '8103 Charitable Contributions',
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

        $legacyMap = [
            '7308 Business Meal' => '6402 Business Meals (50% deductible)',
            '7206 Office Supplies' => '6601 Office Supplies',
            '6403 Site Vehicle Fuel' => '6201 Fuel - Office Vehicles',
            '5215 Consumables' => '5403 Small Tools & Consumables',
            'Travel & Lodging' => '6401 Business Travel (Flights/Lodging)',
            'Office Supplies' => '6601 Office Supplies',
            'Tools' => '5403 Small Tools & Consumables',
            '5101 Permanent Staff Wages' => '5101 Gross Wages - Field',
            '5102 Temporary / Daily Labor' => '5101 Gross Wages - Field',
            '5216 Fuel & Lubricants' => '5402 Fuel - Equipment',
            '5407 Equipment Rental - Others' => '5401 Equipment Rental',
            '7103 Admin Staff Salary' => '6101 Office Salaries',
            '7201 Office Rent' => '6301 Rent or Lease - Office',
            '7205 Office Utilities - Internet' => '6303 Telephone & Internet',
            '7301 Domestic Travel - Airfare' => '6401 Business Travel (Flights/Lodging)',
            '7303 Accommodation - Business Travel' => '6401 Business Travel (Flights/Lodging)',
            '7401 Company Vehicle Fuel' => '6201 Fuel - Office Vehicles',
            '7601 Software Subscription' => '6503 Software & SaaS Subscriptions',
            '8105 Bank Service Charges' => '6502 Merchant Fees & Bank Charges',
        ];

        if (isset($legacyMap[$account])) {
            $account = $legacyMap[$account];
        }

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
            '6201 Fuel - Office Vehicles' => ['marathon', 'shell', 'chevron', 'exxon', 'circle k', 'fuel', 'gasoline', 'regular gas', 'diesel', 'gas receipt', 'love\'s', 'pilot', 'quiktrip', 'qt'],
            '6202 Repairs & Maintenance - Auto' => ['auto repair', 'vehicle maintenance', 'oil change', 'tire', 'autozone', 'pep boys', 'discount tire'],
            '6401 Business Travel (Flights/Lodging)' => ['hotel', 'motel', 'lodging', 'accommodation', 'marriott', 'hilton', 'holiday inn', 'airbnb', 'flight', 'airline', 'delta', 'american airlines', 'southwest', 'uber', 'lyft', 'taxi', 'shuttle'],
            '6402 Business Meals (50% deductible)' => ['restaurant', 'mcdonald', 'burger', 'meal', 'lunch', 'dinner', 'breakfast', 'catering', 'starbucks', 'dutch bros', 'subway', 'chipotle'],
            '6601 Office Supplies' => ['office supplies', 'staples', 'officemax', 'paper', 'pen', 'printer', 'walmart', 'target', 'amazon'],
            '6503 Software & SaaS Subscriptions' => ['software', 'subscription', 'license', 'saas', 'aws', 'google cloud', 'github', 'adobe', 'microsoft', 'slack', 'zoom'],
            '6303 Telephone & Internet' => ['phone bill', 'mobile bill', 'communication', 'verizon', 'att', 't-mobile', 'cox', 'centurylink'],
            '6302 Utilities (SRP/APS/Water)' => ['electricity', 'electric bill', 'srp', 'aps', 'water bill', 'gas bill', 'southwest gas'],
            '5502 Safety Equipment & Supplies' => ['safety material', 'gloves', 'goggles', 'hard hat', 'safety vest', 'safety boots', 'earplugs', 'grainger', 'home depot', 'lowes'],
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
