<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteContractor extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'company_id',
        'company_name',
        'contract_role',
        'contract_number',
        'scope_of_work',
        'primary_contact_name',
        'primary_contact_phone',
        'primary_contact_email',
        'starts_on',
        'ends_on',
        'status',
        'notes',
        'payload',
    ];

    public const ROLE_OPTIONS = [
        'direct_client' => '직접 계약사',
        'upper_contractor' => '상위 원청사',
        'subcontractor' => '하청사',
        'vendor' => '벤더',
        'partner' => '협력사',
    ];

    public const STATUS_OPTIONS = [
        'active' => 'Active',
        'pending' => 'Pending',
        'on_hold' => 'On Hold',
        'completed' => 'Completed',
        'inactive' => 'Inactive',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'payload' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
