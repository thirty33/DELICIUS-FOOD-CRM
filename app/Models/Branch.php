<?php

namespace App\Models;

use App\Casts\E164PhoneNumber;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Notifications\Notifiable;

class Branch extends Model
{
    use HasFactory, Notifiable;

    protected $casts = [
        'contact_phone_number' => E164PhoneNumber::class,
    ];

    protected $fillable = [
        'company_id',
        'address',
        'shipping_address',
        'contact_name',
        'contact_last_name',
        'contact_phone_number',
        'branch_code',
        'fantasy_name',
        'min_price_order'
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function dispatchRules(): BelongsToMany
    {
        return $this->belongsToMany(DispatchRule::class, 'dispatch_rule_branches')
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

        return $this->contact_phone_number;
    }
}
