<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Collection;

/** The same receiving permission applies to the worker app, ERP and evidence files. */
final class MaterialReceiptAccess
{
    public static function canManage(?User $user): bool
    {
        if (! $user || $user->account_status !== 'active' || $user->isReadOnly()
            || ! array_key_exists($user->access_role, User::ROLE_OPTIONS)) {
            return false;
        }

        if (in_array($user->access_role, [...AccessPolicy::SITE_ROLES, 'safety_manager', 'foreman'], true)) {
            return true;
        }

        $employee = $user->employee;

        return $employee?->employment_status === 'active'
            && (in_array($employee->attendance_app_role, ['foreman', 'safety_manager', 'attendance_admin'], true)
                || in_array($employee->position, Employee::SUPERVISORY_POSITIONS, true));
    }

    /** @return Collection<int, Site> */
    public static function sites(?User $user, bool $activeOnly = false): Collection
    {
        if (! self::canManage($user)) {
            return collect();
        }

        return Site::query()->when($activeOnly, fn ($q) => $q->where('status', 'active'))
            ->orderBy('name')->get()
            ->filter(fn (Site $site): bool => AiInformationAccess::canUseSite($user, $site))->values();
    }

    /** @return array<int, array{value: int, label: string}> */
    public static function siteOptions(?User $user): array
    {
        return self::sites($user, true)->map(fn (Site $site): array => [
            'value' => $site->id,
            'label' => trim($site->code.' — '.$site->name, ' —'),
        ])->all();
    }

    public static function site(?User $user, mixed $wanted, bool $activeOnly = true): ?Site
    {
        if (! is_scalar($wanted) || trim((string) $wanted) === '') {
            return null;
        }

        $wanted = trim((string) $wanted);

        return self::sites($user, $activeOnly)->first(fn (Site $site): bool => is_numeric($wanted)
            ? $site->id === (int) $wanted
            : strcasecmp((string) $site->code, $wanted) === 0);
    }
}
