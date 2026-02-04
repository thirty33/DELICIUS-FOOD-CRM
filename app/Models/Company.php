<?php

namespace App\Models;

use App\Casts\E164PhoneNumber;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;

class Company extends Model
{
    use HasFactory, Notifiable;

    protected $casts = [
        'phone_number' => E164PhoneNumber::class,
    ];

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

    public function menus(): BelongsToMany
    {
        return $this->belongsToMany(Menu::class, 'company_menu');
    }

    public function orderRules(): BelongsToMany
    {
        return $this->belongsToMany(OrderRule::class, 'order_rule_companies');
    }

    public function reportGroupers(): BelongsToMany
    {
        return $this->belongsToMany(ReportGrouper::class, 'company_report_grouper')
            ->withTimestamps();
    }

    public function routeNotificationForWhatsApp(): ?string
    {
        $integration = Integration::where('name', \App\Enums\IntegrationName::WHATSAPP)
            ->where('active', true)
            ->first();

        if ($integration && !$integration->production) {
            return config('whatsapp.test_phone_number');
        }

        return $this->phone_number;
    }
}
