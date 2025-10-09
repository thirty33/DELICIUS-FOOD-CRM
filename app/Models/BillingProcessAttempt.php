<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BillingProcessAttempt extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'billing_process_id',
        'request_body',
        'response_body',
        'response_status',
    ];

    protected $casts = [
        'response_status' => 'integer',
    ];

    // Relationships
    public function billingProcess(): BelongsTo
    {
        return $this->belongsTo(BillingProcess::class);
    }
}
