<?php

namespace App\Models;

use App\Enums\IntegrationName;
use App\Enums\IntegrationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Integration extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'url',
        'url_test',
        'type',
        'production',
        'active',
        'temporary_token',
        'token_expiration_time',
    ];

    protected $casts = [
        'name' => IntegrationName::class,
        'type' => IntegrationType::class,
        'production' => 'boolean',
        'active' => 'boolean',
        'token_expiration_time' => 'datetime',
    ];
}