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
        return self::query()
            ->with(['employee.company', 'employee.site', 'employee.team'])
            ->where('token_hash', self::hashToken($token))
            ->where('status', 'active')
            ->first();
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
