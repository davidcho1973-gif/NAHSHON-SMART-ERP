<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EmployeeBadgeQrToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'token',
        'token_hash',
        'status',
        'issued_at',
        'revoked_at',
        'created_by_id',
        'payload',
    ];

    protected $hidden = [
        'token',
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'issued_at' => 'datetime',
            'revoked_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public static function makeToken(): string
    {
        return 'emp_' . Str::random(48);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function activeForToken(string $token): ?self
    {
        $row = self::query()
            ->with(['employee.company', 'employee.site', 'employee.team'])
            ->where('token_hash', self::hashToken($token))
            ->where('status', 'active')
            ->first();

        // 재직 검사 — 배지 토큰이 살아 있어도 사람이 퇴사했으면 무효다. 이 검사가 없으면
        // 퇴사자 배지로 팀 출퇴근이 찍히고, 배지 화면이 인증 없이 퇴사자 신원을 노출한다.
        // (퇴사 캐스케이드가 배지를 폐기하지만, 그 이전에 발급된 기록·복제본까지 막으려면
        //  읽는 쪽에서도 재직을 봐야 한다 — 문과 열쇠 둘 다 잠근다.)
        if ($row !== null && $row->employee?->employment_status !== 'active') {
            return null;
        }

        return $row;
    }

    public static function activeForEmployee(Employee $employee, ?int $createdById = null): self
    {
        $existing = self::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        $token = self::makeToken();

        return self::query()->create([
            'employee_id' => $employee->id,
            'token' => $token,
            'token_hash' => self::hashToken($token),
            'status' => 'active',
            'issued_at' => now(),
            'created_by_id' => $createdById,
        ]);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
