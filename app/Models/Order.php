<?php

namespace App\Models;

use App\Classes\Menus\MenuHelper;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class Order extends Model
{
    protected $fillable = [
        'total',
        'status',
        'user_id',
        'price_list_min',
        'branch_id',
        'dispatch_date',
        'alternative_address',
        'order_number',
        'user_comment'
    ];

    protected $casts = [
        'user_comment' => 'string',
    ];

    protected $appends = [
        'price_list_min',
        'total',
        'total_with_tax'
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        // Antes de crear, generar número de orden si no se proporciona
        static::creating(function ($order) {
            if (empty($order->order_number)) {
                $order->order_number = self::generateOrderNumber();
            }

            if (empty($order->branch_id) && !empty($order->user_id)) {
                try {
                    $user = User::find($order->user_id);

                    if ($user) {
                        // Si el usuario tiene una sucursal asignada directamente
                        if (!empty($user->branch_id)) {
                            $order->branch_id = $user->branch_id;
                        }
                        // Si el usuario pertenece a una empresa con una sucursal predeterminada
                        elseif ($user->company && $user->company->branches()->count() > 0) {
                            // Obtener la primera sucursal de la empresa como predeterminada
                            $branch = $user->company->branches()->first();
                            if ($branch) {
                                $order->branch_id = $branch->id;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('Error al asignar sucursal al pedido:', [
                        'user_id' => $order->user_id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        });
    }

    /**
     * Genera un número de orden único
     * 
     * @return string
     */
    public static function generateOrderNumber()
    {
        $date = Carbon::now()->format('Ymd');
        $random = str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);

        return $date . $random;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orderLines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    protected function Total(): Attribute
    {
        return Attribute::make(
            get: fn($value) => $this->orderLines->sum('total_price'),
            set: function ($value) {
                $this->attributes['total'] = $value;
                return $value;
            }
        );
    }

    protected function priceListMin(): Attribute
    {
        $hasBranch = $this->user->branch;
        $branchMinPrice = $this->user->branch->min_price_order;

        return Attribute::make(
            get: fn($value) => $hasBranch && $branchMinPrice ? $branchMinPrice : $this->user->company->priceList->min_price_order,
        );
    }

    protected function TotalWithTax(): Attribute
    {
        return Attribute::make(
            get: function () {
                $totalWithTax = 0;
                foreach ($this->orderLines as $line) {
                    $totalWithTax += $line->quantity * $line->unit_price_with_tax;
                }
                return $totalWithTax;
            }
        );
    }
}
