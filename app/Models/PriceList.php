<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceList extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'min_price_order'
    ];

    protected $casts = [
        'min_price_order' => 'decimal:2',
    ];

    public function priceListLines(): HasMany
    {
        return $this->hasMany(PriceListLine::class);
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class, 'price_list_id');
    }
}
