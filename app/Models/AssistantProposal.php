<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Approval/audit envelope only; operational todos remain in ops_action_items. */
class AssistantProposal extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_STALE = 'stale';

    // PostgreSQL timestamp-with-timezone must receive an offset, even when DB/app zones differ.
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $guarded = ['id'];

    protected $hidden = ['preview_token', 'actor_context', 'record_version'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'before_snapshot' => 'array',
            'after_snapshot' => 'array',
            'audit_events' => 'array',
            'result' => 'array',
            'expires_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
