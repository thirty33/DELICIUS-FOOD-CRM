<?php

namespace App\Models;

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
        'production' => 'boolean',
        'active' => 'boolean',
        'token_expiration_time' => 'datetime',
    ];

    // Integration names
    const NAME_DEFONTANA = 'defontana';
    const NAME_FACTURACION_CL = 'facturacion_cl';

    // Integration types
    const TYPE_BILLING = 'billing';
    const TYPE_PAYMENT_GATEWAY = 'payment_gateway';

    public static function getNames(): array
    {
        return [
            self::NAME_DEFONTANA => 'Defontana',
            self::NAME_FACTURACION_CL => 'Facturación.cl',
        ];
    }

    public static function getTypes(): array
    {
        return [
            self::TYPE_BILLING => 'Facturación',
            self::TYPE_PAYMENT_GATEWAY => 'Pasarela de Pago',
        ];
    }
}
