<?php

namespace App\Models;

use App\Enums\RoleName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Filament\Panel;
use Filament\Models\Contracts\FilamentUser;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable, HasApiTokens;

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
        'validate_min_price',
        'validate_subcategory_rules',
        'nickname',
        'plain_password'
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
}
