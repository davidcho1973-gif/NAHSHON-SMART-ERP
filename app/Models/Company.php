<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'legal_name',
        'status',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'company_site');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function siteContractors(): HasMany
    {
        return $this->hasMany(SiteContractor::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function endClientProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'end_client_company_id');
    }

    public function upperContractorProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'upper_contractor_company_id');
    }

    public function epcProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'epc_company_id');
    }
}
