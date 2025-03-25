<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'address',
        'email',
        'phone_number',
        'website',
        'registration_number',
        'description',
        'logo',
        'active',
        'tax_id',              // RUT
        'business_activity',   // Giro
        'acronym',             // Sigla
        'shipping_address',    // Dirección de Despacho
        'district',            // Distrito/Comuna
        'state_region',        // Estado/Región
        'postal_box',          // Casilla Postal
        'city',                // Ciudad
        'country',             // País
        'zip_code',            // Código ZIP
        'fax',                 // Fax
        'company_name',        // Razón social
        'contact_name',
        'contact_last_name',
        'contact_phone_number',
        'fantasy_name',
        'price_list_id',
        'company_code',
        'payment_condition',
        'exclude_from_consolidated_report'
    ];

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'price_list_id', 'id');
    }

    public function priceLists(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'price_list_id', 'id');
    }
    
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

}
