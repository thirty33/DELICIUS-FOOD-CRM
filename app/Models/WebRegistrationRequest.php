<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WebRegistrationRequest extends Model
{
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'razon_social',
        'rut',
        'nombre_fantasia',
        'tipo_cliente',
        'giro',
        'direccion',
        'telefono',
        'email',
        'mensaje',
        'status',
        'admin_notes',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONTACTED = 'contacted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /**
     * Get available statuses
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_CONTACTED,
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
        ];
    }

    /**
     * Scope for pending requests
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Check if request is pending
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Validation rules for creating a request
     */
    public static function validationRules(): array
    {
        return [
            'razon_social' => ['required', 'string', 'max:255'],
            'rut' => ['required', 'string', 'max:12', 'cl_rut'],
            'nombre_fantasia' => ['nullable', 'string', 'max:255'],
            'tipo_cliente' => ['required', 'string', 'max:50'],
            'giro' => ['nullable', 'string', 'max:255'],
            'direccion' => ['required', 'string', 'max:500'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'email' => ['required', 'email', 'max:255'],
            'mensaje' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
