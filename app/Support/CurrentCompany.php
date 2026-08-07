<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;

/**
 * The company a user is currently looking at.
 *
 * Membership (which companies a user may open) lives in the company_user pivot.
 * The active choice lives in the session, so switching never writes to the user
 * row and a user can be signed in to different companies in different browsers.
 *
 * Resolution order: session choice -> the user's default membership -> their
 * legacy allowed_company_id -> their first membership. Null means "no company
 * resolved" (e.g. an empty database), which callers treat as "do not filter".
 */
class CurrentCompany
{
    public const SESSION_KEY = 'current_company_id';

    public static function id(): ?int
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        $sessionId = session(self::SESSION_KEY);

        if ($sessionId !== null && $user->canAccessCompany((int) $sessionId)) {
            return (int) $sessionId;
        }

        $resolved = self::resolveDefaultId($user);

        if ($resolved !== null) {
            session([self::SESSION_KEY => $resolved]);
        }

        return $resolved;
    }

    public static function get(): ?Company
    {
        $id = self::id();

        return $id === null ? null : Company::find($id);
    }

    /**
     * Switch the active company. Returns false when the user is not a member,
     * leaving the current selection untouched.
     */
    public static function set(int $companyId): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->canAccessCompany($companyId)) {
            return false;
        }

        session([self::SESSION_KEY => $companyId]);

        return true;
    }

    public static function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /** @return array<int, string> company id => label, for the switcher */
    public static function options(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        return $user->accessibleCompanies()
            ->mapWithKeys(fn (Company $company): array => [$company->id => $company->name])
            ->all();
    }

    private static function resolveDefaultId(User $user): ?int
    {
        $companies = $user->accessibleCompanies();

        if ($companies->isEmpty()) {
            return null;
        }

        $default = $companies->firstWhere(fn (Company $company): bool => (bool) ($company->pivot->is_default ?? false));

        if ($default !== null) {
            return (int) $default->id;
        }

        if ($user->allowed_company_id && $companies->contains('id', $user->allowed_company_id)) {
            return (int) $user->allowed_company_id;
        }

        return (int) $companies->first()->id;
    }
}
