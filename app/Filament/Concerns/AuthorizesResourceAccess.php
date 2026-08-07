<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Reusable Filament authorization for SMART COMPANY resources.
 *
 * Gives a resource two layers of protection that previously only ProjectResource had:
 *   1. Role gates  — canViewAny / canCreate / canEdit / canDelete by access_role.
 *   2. Row scoping — getEloquentQuery() limits rows to the user's access_scope
 *      (company / site / team / self), using the user's allowed_* ids.
 *
 * A resource configures it by overriding the protected static methods below. Super
 * admins, admins, and access_scope = all_sites always see everything.
 */
trait AuthorizesResourceAccess
{
    /** @return array<int, string> roles that may see the resource list */
    protected static function accessViewRoles(): array
    {
        return ['super_admin', 'admin'];
    }

    /** @return array<int, string> roles that may create / edit records */
    protected static function accessManageRoles(): array
    {
        return ['super_admin', 'admin'];
    }

    /** @return array<int, string> roles that may delete records */
    protected static function accessDeleteRoles(): array
    {
        return ['super_admin', 'admin'];
    }

    /**
     * Map each access_scope level to the column on THIS model used to filter.
     * null denies that scope level. The 'self' column is compared against employee_id.
     *
     * @return array<string, string|null>
     */
    protected static function accessScopeColumns(): array
    {
        return ['company' => 'company_id', 'site' => 'site_id', 'team' => 'team_id', 'self' => null];
    }

    /** When false, only role gates apply (no per-row scoping) — for reference data. */
    protected static function accessRowScoped(): bool
    {
        return true;
    }

    public static function currentUserHasRole(array $roles): bool
    {
        $user = auth()->user();

        return $user !== null && in_array($user->access_role, $roles, true);
    }

    public static function canViewAny(): bool
    {
        return static::currentUserHasRole(static::accessViewRoles());
    }

    public static function canView($record): bool
    {
        return static::currentUserHasRole(static::accessViewRoles());
    }

    public static function canCreate(): bool
    {
        return static::currentUserHasRole(static::accessManageRoles());
    }

    public static function canEdit($record): bool
    {
        return static::currentUserHasRole(static::accessManageRoles());
    }

    public static function canDelete($record): bool
    {
        return static::currentUserHasRole(static::accessDeleteRoles());
    }

    public static function canDeleteAny(): bool
    {
        return static::currentUserHasRole(static::accessDeleteRoles());
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if (! static::accessRowScoped()) {
            return $query;
        }

        if (in_array($user->access_role, ['super_admin', 'admin'], true) || $user->access_scope === 'all_sites') {
            return $query;
        }

        $columns = static::accessScopeColumns();

        return match ($user->access_scope) {
            'company' => static::scopeByColumn($query, $columns['company'] ?? null, $user->allowed_company_id),
            'site' => static::scopeByColumn($query, $columns['site'] ?? null, $user->allowed_site_id),
            'team' => static::scopeByColumn($query, $columns['team'] ?? null, $user->allowed_team_id),
            'self' => static::scopeByColumn($query, $columns['self'] ?? null, $user->employee_id),
            default => $query->whereRaw('1 = 0'),
        };
    }

    protected static function scopeByColumn(Builder $query, ?string $column, mixed $value): Builder
    {
        if ($column === null || blank($value)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($column, $value);
    }
}
