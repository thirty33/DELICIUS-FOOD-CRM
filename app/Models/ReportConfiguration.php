<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportConfiguration extends Model
{
    protected $fillable = [
        'name',
        'description',
        'use_groupers',
        'exclude_cafeterias',
        'exclude_agreements',
        'is_active',
    ];

    protected $casts = [
        'use_groupers' => 'boolean',
        'exclude_cafeterias' => 'boolean',
        'exclude_agreements' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Groupers that belong to this configuration
     */
    public function groupers(): HasMany
    {
        return $this->hasMany(ReportGrouper::class);
    }

    /**
     * Get the active configuration
     */
    public static function getActive(): ?self
    {
        return self::where('is_active', true)->first();
    }

    /**
     * Check if groupers should be used
     */
    public static function shouldUseGroupers(): bool
    {
        $config = self::getActive();
        return $config ? $config->use_groupers : false;
    }
}
