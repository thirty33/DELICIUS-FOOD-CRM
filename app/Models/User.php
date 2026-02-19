<?php

namespace App\Models;

use App\Enums\RoleName;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasFactory, Notifiable;

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasRole(RoleName::ADMIN->value);
    }

    protected $with = [
        'roles',
        'permissions',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'company_id',
        'branch_id',
        'allow_late_orders',
        'allow_weekend_orders',
        'validate_min_price',
        'validate_subcategory_rules',
        'nickname',
        'plain_password',
        'billing_code',
        'master_user',
        'super_master_user',
        'is_seller',
        'seller_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'master_user' => 'boolean',
            'super_master_user' => 'boolean',
            'is_seller' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id', 'id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    /**
     * Determine if the user has the given role.
     */
    public function hasRole(string $role): bool
    {
        return $this->roles->contains('name', $role);
    }

    /**
     * Determine if the user has the given permission.
     */
    public function hasPermission(string $permission): bool
    {
        return $this->permissions->contains('name', $permission);
    }

    /**
     * Determine if the user has any of the given roles.
     */
    public function hasAnyRole(array $roles): bool
    {
        return $this->roles->whereIn('name', $roles)->isNotEmpty();
    }

    public function hasBranch(): bool
    {
        return $this->branch->isNotEmpty();
    }

    /**
     * Determine if the user has any roles.
     */
    public function hasRoles(): bool
    {
        return $this->roles->isNotEmpty();
    }

    public function scopeCustomers(Builder $builder): Builder
    {
        return $builder->where('is_customer', true);
    }

    public function scopeActive(Builder $builder): Builder
    {
        return $builder->where('active', true);
    }

    public function categoryUserLines(): HasMany
    {
        return $this->hasMany(CategoryUserLine::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function clients(): HasMany
    {
        return $this->hasMany(User::class, 'seller_id');
    }

    public function sellerPortfolios(): HasMany
    {
        return $this->hasMany(SellerPortfolio::class, 'seller_id');
    }

    public function activePortfolio(): HasOne
    {
        return $this->hasOne(UserPortfolio::class)->where('is_active', true);
    }

    public function portfolioHistory(): HasMany
    {
        return $this->hasMany(UserPortfolio::class)->orderByDesc('assigned_at');
    }
}
