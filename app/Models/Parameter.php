<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Parameter extends Model
{
    use HasFactory;
    
    public const TAX_VALUE = 'Valor de Impuesto';
    
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'description',
        'value_type',
        'value',
        'active'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'active' => 'boolean',
    ];

    /**
     * Get the parameter value cast to its appropriate type.
     *
     * @return mixed
     */
    public function getTypedValueAttribute()
    {
        return match($this->value_type) {
            'numeric' => (float) $this->value,
            'integer' => (int) $this->value,
            'boolean' => (bool) $this->value,
            'json' => json_decode($this->value, true),
            default => $this->value,
        };
    }

    /**
     * Scope a query to only include active parameters.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Get a parameter by its name.
     *
     * @param string $name
     * @return mixed
     */
    public static function getByName($name)
    {
        return static::where('name', $name)->first();
    }

    /**
     * Get a parameter value by its name.
     *
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public static function getValue($name, $default = null)
    {
        $parameter = static::where('name', $name)->where('active', true)->first();
        
        if (!$parameter) {
            return $default;
        }
        
        return $parameter->getTypedValueAttribute();
    }
}