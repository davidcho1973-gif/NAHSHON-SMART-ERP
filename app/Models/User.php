<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

#[Fillable([
    'employee_id',
    'name',
    'email',
    'email_verified_at',
    'google_id',
    'password',
    'access_role',
    'access_scope',
    'account_status',
    'allowed_company_id',
    'allowed_site_id',
    'allowed_team_id',
    'last_login_at',
    'access_notes',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Memoised per request. Named apart from accessibleCompanies() so Eloquent
     * never mistakes it for a relationship.
     *
     * @var Collection<int, Company>|null
     */
    private ?Collection $accessibleCompaniesCache = null;

    public const ROLE_OPTIONS = [
        'super_admin' => 'Super Admin',
        'admin' => 'Admin',
        'hr_manager' => 'HR Manager',
        'site_manager' => 'Site Manager',
        'safety_manager' => 'Safety Manager',
        'payroll' => 'Payroll / Accounting',
        'foreman' => 'Foreman / Supervisor',
        'vendor_admin' => 'Vendor Admin',
        'worker' => 'Worker / Member',
        'viewer' => 'Viewer',
    ];

    public const SCOPE_OPTIONS = [
        'self' => 'Self only',
        'team' => 'Team only',
        'site' => 'Site only',
        'company' => 'Company only',
        'all_sites' => 'All sites',
    ];

    public const STATUS_OPTIONS = [
        'active' => 'Active',
        'pending' => 'Pending',
        'suspended' => 'Suspended',
        'disabled' => 'Disabled',
    ];

    public const ADMIN_PANEL_ROLES = [
        'super_admin',
        'admin',
        'hr_manager',
        'site_manager',
        'safety_manager',
        'payroll',
    ];

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->account_status === 'active'
            && in_array($this->access_role, self::ADMIN_PANEL_ROLES, true);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function communicationRoomMemberships(): HasMany
    {
        return $this->hasMany(CommunicationRoomMember::class);
    }

    public function communicationMessages(): HasMany
    {
        return $this->hasMany(CommunicationMessage::class, 'sender_user_id');
    }

    public function allowedCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'allowed_company_id');
    }

    /** Companies this user may switch between. */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_user')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    /**
     * Companies the user can actually open. Admins reach every active company so
     * a newly created one is never orphaned; everyone else is limited to their
     * memberships.
     *
     * @return Collection<int, Company>
     */
    public function accessibleCompanies(): Collection
    {
        return $this->accessibleCompaniesCache ??= in_array($this->access_role, ['super_admin', 'admin'], true)
            ? Company::query()->where('status', 'active')->orderBy('name')->get()
            : $this->companies()->where('status', 'active')->orderBy('name')->get();
    }

    public function canAccessCompany(int $companyId): bool
    {
        return $this->accessibleCompanies()->contains('id', $companyId);
    }

    public function allowedSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'allowed_site_id');
    }

    public function allowedTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'allowed_team_id');
    }

    public function landingPath(): string
    {
        return match ($this->access_role) {
            'super_admin', 'admin' => '/admin',
            'hr_manager' => '/admin/member-registrations',
            'site_manager' => '/admin',
            'safety_manager' => '/admin/member-documents',
            'payroll' => '/admin',
            'foreman', 'worker' => '/attendance-app',
            default => '/',
        };
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }
}
