<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ReportGrouper extends Model
{
    protected $fillable = [
        'report_configuration_id',
        'name',
        'code',
        'display_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'display_order' => 'integer',
    ];

    /**
     * Configuration this grouper belongs to
     */
    public function reportConfiguration(): BelongsTo
    {
        return $this->belongsTo(ReportConfiguration::class);
    }

    /**
     * Companies that belong to this grouper
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_report_grouper')
            ->withPivot('use_all_branches')
            ->withTimestamps();
    }

    /**
     * Branches that belong to this grouper
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_report_grouper')
            ->withTimestamps();
    }

    /**
     * Get active groupers ordered by display_order
     */
    public static function getActive()
    {
        return self::where('is_active', true)
            ->orderBy('display_order')
            ->get();
    }
}
