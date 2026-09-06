<?php

namespace App\Support;

use App\Models\IntelligentDocument;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** One boundary for AI facts, document excerpts, citations and saved answers. */
final class AiInformationAccess
{
    public const DENIED = '회계·급여·단가·견적·계약금액은 재무 열람 권한이 있어야 답변할 수 있습니다. 공정·시공·수량 질문은 금액 부분을 제외하고 물어봐 주세요.';

    // Unknown/general documents are not assumed safe merely because AI misclassified them.
    private const TECHNICAL_TYPES = ['drawing', 'specification', 'submittal', 'rfi', 'daily_report', 'inspection', 'ncr', 'safety_plan', 'incident_report', 'schedule', 'meeting_minutes', 'delivery_ticket', 'certificate', 'warranty', 'closeout_package'];

    // PostgreSQL and PCRE compatible. Do not match technical "얼마나/얼마", dimensions or quantities.
    private const MONEY_PATTERN = '급여|임금|시급|월급|연봉|인건비|노무비|재료비|단가|금액|총액|견적|회계|경비|지출|예산|정산|대금|원가|매출|매입|수익|이익|청구|세금|송금|계좌|돈|비용|가격|임대료|\\b(payroll|salary|salaries|wages?|accounting|financial|price|pricing|costs?|expenses?|budget|invoice|payment|profit|revenue|quotation|tax|unit[ _-]?rate|contract[ _-]?(amount|value|sum)|estimate|cotizaci[oó]n|precio|costo|salario|n[oó]mina|presupuesto|factura|pago)\\b|[$€£₩]|\\b(USD|KRW|EUR)\\b|[0-9][0-9,.]*[ ]*(달러|원)([^가-힣]|$)';

    public static function financial(string $text): bool
    {
        return preg_match('~'.self::MONEY_PATTERN.'~iu', $text) === 1;
    }

    public static function siteId(User $user): ?int
    {
        return $user->allowed_site_id ?: $user->employee?->site_id;
    }

    public static function canUseSite(User $user, Site $site): bool
    {
        if ($user->account_status !== 'active' || ! array_key_exists($user->access_role, User::ROLE_OPTIONS)) {
            return false;
        }
        if (AccessPolicy::canManageSystem($user)) {
            return true;
        }
        if (! AccessPolicy::canSeeCompany($user, $site->company_id)) {
            return false;
        }
        if (in_array($user->access_role, ['worker', 'foreman'], true) || in_array($user->access_scope, ['site', 'self', 'team'], true)) {
            $company = $user->allowed_company_id ?: $user->employee?->company_id;

            return self::siteId($user) === $site->id && (! $company || (int) $company === (int) $site->company_id);
        }
        if ($user->access_scope === 'company') {
            return (int) ($user->allowed_company_id ?: $user->employee?->company_id) === (int) $site->company_id;
        }

        return $user->access_scope === 'all_sites';
    }

    public static function documents(User $user, ?Site $site): Builder
    {
        $query = IntelligentDocument::query()->where('ai_status', 'ready');
        if ($user->account_status !== 'active' || ($site && ! self::canUseSite($user, $site)) || (! $site && ! AccessPolicy::canManageSystem($user))) {
            return $query->whereRaw('1 = 0');
        }
        if ($site) {
            $query->where('site_id', $site->id)->where('company_id', $site->company_id);
        }
        AccessPolicy::applyCompanyLock($query, $user);
        if (! AccessPolicy::canManageSystem($user)) {
            $query->whereIn('confidentiality', ['public', 'internal']);
        }
        if (! AccessPolicy::canManageMoney($user)) {
            $query->whereIn('document_type', self::TECHNICAL_TYPES);
            // Check the complete source, not just a clipped excerpt: mixed cost/technical documents stay private.
            foreach (['title', 'original_file_name', 'summary', 'key_facts', 'search_text', 'extracted_text'] as $column) {
                self::withoutFinancialText($query, $column);
            }
        }

        return $query;
    }

    public static function withoutFinancialText(Builder $query, string $column): void
    {
        // PostgreSQL uses \y for a word boundary; column names are application constants only.
        // json preserves escaped Korean (\uXXXX); jsonb normalizes it before the text check.
        $expression = $column === 'key_facts' ? 'CAST(key_facts AS JSONB)' : $column;
        $query->whereRaw("COALESCE(CAST({$expression} AS TEXT), '') !~* ?", [str_replace('\\b', '\\y', self::MONEY_PATTERN)]);
    }

    /** Free-text operational fields can also contain a pasted price. Remove the whole affected field. */
    public static function technicalFacts(array $facts): array
    {
        $safe = [];
        foreach ($facts as $key => $value) {
            if (is_string($key) && self::financial($key)) {
                continue;
            }
            if (is_array($value)) {
                $value = self::technicalFacts($value);
            } elseif (is_string($value) && self::financial($value)) {
                continue;
            }
            $safe[$key] = $value;
        }

        return array_is_list($facts) ? array_values($safe) : $safe;
    }

    public static function context(User $user): string
    {
        return hash('sha256', json_encode(['worker-ask-v1', $user->access_role, $user->account_status, $user->access_scope,
            $user->allowed_company_id, $user->allowed_site_id, $user->allowed_team_id, $user->employee_id,
            $user->employee?->company_id, $user->employee?->site_id]));
    }
}
