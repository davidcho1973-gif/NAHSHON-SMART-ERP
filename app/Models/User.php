<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
class User extends Authenticatable
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
        'client' => 'Client / GC (원청)',
        'viewer' => 'Viewer',
    ];

    /**
     * 화면에 보여줄 한국어 이름.
     *
     * ROLE_OPTIONS 는 영어라 예전 관리자 패널 시절부터 화면에 영어가 섞여 나왔다. SPA 는 한국어가
     * 원문이고 smart-language.js 가 영어·스페인어로 옮기므로, 여기서 한국어를 정본으로 둔다.
     * ROLE_OPTIONS 는 저장값 그대로라 건드리지 않는다.
     */
    public const ROLE_LABELS_KO = [
        'super_admin' => '슈퍼관리자',
        'admin' => '관리자',
        'hr_manager' => '인사담당',
        'site_manager' => '현장소장',
        'safety_manager' => '안전관리자',
        'payroll' => '급여·회계',
        'foreman' => '작업반장',
        'vendor_admin' => '협력사 관리자',
        'worker' => '작업자',
        'client' => '원청 · 발주처',
        'viewer' => '열람 전용',
    ];

    public const SCOPE_LABELS_KO = [
        'self' => '본인만',
        'team' => '소속 팀',
        'site' => '지정 현장',
        'company' => '소속 회사',
        'all_sites' => '전체 현장',
    ];

    public const STATUS_LABELS_KO = [
        'active' => '활성',
        'pending' => '대기',
        'suspended' => '정지',
        'disabled' => '해지',
    ];

    /**
     * 권한 세기 — 목록에서 강한 권한이 눈에 띄게 하려고 등급을 나눈다.
     * 계정이 수십 개가 되면 "누가 관리자인지" 를 한눈에 못 찾는 게 문제가 된다.
     */
    public const ROLE_TIERS = [
        'super_admin' => 'high',
        'admin' => 'high',
        'hr_manager' => 'mid',
        'payroll' => 'mid',
        'site_manager' => 'mid',
        'safety_manager' => 'mid',
        'vendor_admin' => 'mid',
        'foreman' => 'low',
        'worker' => 'low',
        'client' => 'external',
        'viewer' => 'external',
    ];

    /**
     * 열람 전용 역할 — 원청(발주처)과 뷰어. 현황은 보되 어떤 데이터도 바꿀 수 없다.
     */
    public const READ_ONLY_ROLES = [
        'client',
        'viewer',
    ];

    /** 이 계정이 데이터를 바꿀 수 있는가. */
    public function isReadOnly(): bool
    {
        return in_array($this->access_role, self::READ_ONLY_ROLES, true);
    }

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

    /**
     * 관리 화면(현장·직원·계정·임금 등)을 볼 수 있는 역할.
     *
     * 예전에는 별도 관리자 패널(/admin)에 들어갈 수 있는지를 뜻했다. 지금은 그 패널이
     * 없고 관리 화면이 ERP 안에 있으므로, 실제 접근 판정은 각 서비스의 VIEW_ROLES 가
     * 한다. 이 목록은 "관리 메뉴를 보여 줄 사람" 을 고르는 데 쓴다.
     */
    public const ADMIN_PANEL_ROLES = [
        'super_admin',
        'admin',
        'hr_manager',
        'site_manager',
        'safety_manager',
        'payroll',
    ];

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

    /**
     * 로그인 직후 어디로 보낼 것인가.
     *
     * 작업자는 ERP 로 보내면 안 된다. 자기 근무시간을 보러 앱을 열었다가 로그인 뒤에
     * 회사 전체 화면이 뜨면, 자기가 뭘 잘못 눌렀다고 생각하고 앱을 지운다. 설치를
     * 부탁하는 첫날에 이걸 겪으면 두 번째 기회는 없다.
     *
     * 예전에는 /admin 을 가리켰는데 그 화면은 없어졌다(전부 ERP 안으로 들어왔다).
     */
    public function landingPath(): string
    {
        return match ($this->access_role) {
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
            'pin_set_at' => 'datetime',
            'pin_locked_until' => 'datetime',
        ];
    }

    /** 이 사람이 PIN 을 정해 두었는가 — 관리자 화면은 이 사실만 보고 값은 못 본다. */
    public function hasPin(): bool
    {
        return filled($this->pin_hash);
    }

    /** 로그인 가능한 기기(폰). PIN 은 기기 없이는 열쇠가 되지 않는다. */
    public function loginDevices(): HasMany
    {
        return $this->hasMany(LoginDevice::class);
    }
}
