<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 하이브리드 출퇴근 원시 이벤트(진입/이탈/핑) 로그.
 */
class AttendanceGeoEvent extends Model
{
    protected $fillable = [
        'employee_id', 'site_id', 'kind', 'source', 'on_site',
        'lat', 'lng', 'accuracy', 'bssid', 'occurred_at', 'payload',
    ];

    protected function casts(): array
    {
        return ['on_site' => 'boolean', 'occurred_at' => 'datetime', 'payload' => 'array'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
