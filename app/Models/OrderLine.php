<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class OrderLine extends Model
{
    /**
     * Flag to disable OrderLineProductionStatusObserver during bulk imports.
     * When true, the observer will skip execution to avoid saturating the queue
     * with thousands of jobs during import operations.
     *
     * @var bool
     */
    public static bool $importMode = false;

    protected $fillable = [
        'quantity',
        'unit_price',
        'order_id',
        'product_id',
        'partially_scheduled',
        'unit_price_with_tax',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getTotalPriceAttribute()
    {
        return $this->quantity * $this->unit_price;
    }

    public function getTotalPriceWithTaxAttribute()
    {
        return $this->quantity * $this->unit_price_with_tax;
    }

    /**
     * Calcula el precio unitario del producto según la lista de precios
     * de la empresa asociada al usuario que realizó el pedido.
     *
     * @param int $productId ID del producto
     * @param int $orderId ID del pedido
     * @return float|null Precio unitario o null si no se encuentra
     */
    public static function calculateUnitPrice($productId, $orderId)
    {
        try {
            // Obtener el pedido
            $order = Order::find($orderId);
            if (!$order) {
                Log::warning("No se encontró el pedido con ID {$orderId}");
                return null;
            }

            // Obtener el usuario
            $user = User::find($order->user_id);
            if (!$user) {
                Log::warning("No se encontró el usuario para el pedido {$orderId}");
                return null;
            }

            // Obtener la empresa
            $company = $user->company;
            if (!$company) {
                Log::warning("El usuario {$user->id} no tiene empresa asociada");
                return null;
            }

            // Obtener ID de la lista de precios
            $priceListId = $company->price_list_id;
            if (!$priceListId) {
                Log::warning("La empresa {$company->id} no tiene lista de precios asociada");
                return null;
            }

            // Buscar el precio en la lista de precios
            $priceListLine = PriceListLine::where('price_list_id', $priceListId)
                ->where('product_id', $productId)
                ->first();

            if ($priceListLine) {
                return $priceListLine->unit_price;
            }

            Log::warning("No se encontró precio para el producto {$productId} en la lista {$priceListId}");
            return null;
        } catch (\Exception $e) {
            Log::error('Error al calcular precio unitario:', [
                'product_id' => $productId,
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Calcula el precio unitario con impuesto incluido
     * 
     * @param float $unitPrice Precio unitario sin impuesto
     * @return float|null Precio unitario con impuesto o null si no se puede calcular
     */
    public static function calculateUnitPriceWithTax($unitPrice)
    {
        try {
            if ($unitPrice === null) {
                return null;
            }

            // Obtener el valor del impuesto desde los parámetros
            $taxValue = Parameter::getValue(Parameter::TAX_VALUE);

            if ($taxValue === null) {
                Log::warning("No se encontró el parámetro 'tax value'");
                return $unitPrice; // Retornar el precio sin impuesto si no hay valor de impuesto
            }

            // Calcular el precio con impuesto
            return $unitPrice * (1 + $taxValue);
        } catch (\Exception $e) {
            Log::error('Error al calcular precio con impuesto:', [
                'unit_price' => $unitPrice,
                'error' => $e->getMessage()
            ]);

            return $unitPrice; // Retornar el precio sin impuesto en caso de error
        }
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted()
    {
        static::creating(function (OrderLine $orderLine) {
            // Si no tiene precio unitario y tiene producto y orden, calcularlo
            if ($orderLine->unit_price === null && $orderLine->product_id && $orderLine->order_id) {
                $orderLine->unit_price = self::calculateUnitPrice($orderLine->product_id, $orderLine->order_id);

                // If calculateUnitPrice returns NULL (product not in price list), set to 0
                // to prevent database constraint violation: "Column 'unit_price' cannot be null"
                if ($orderLine->unit_price === null) {
                    $orderLine->unit_price = 0;
                }
            }

            // Calcular el precio con impuesto
            if ($orderLine->unit_price !== null) {
                $orderLine->unit_price_with_tax = self::calculateUnitPriceWithTax($orderLine->unit_price);
            } else {
                // If unit_price is still NULL, set unit_price_with_tax to 0
                // to prevent database constraint violation
                $orderLine->unit_price_with_tax = 0;
            }
        });

        static::updating(function (OrderLine $orderLine) {
            // Si se actualizó el precio unitario, actualizar también el precio con impuesto
            if ($orderLine->isDirty('unit_price')) {
                $orderLine->unit_price_with_tax = self::calculateUnitPriceWithTax($orderLine->unit_price);
            }
        });

        static::created(function (OrderLine $orderLine) {
            if ($orderLine->order) {
                $orderLine->order->updateDispatchCost();
            }
        });

        static::updated(function (OrderLine $orderLine) {
            if ($orderLine->order) {
                $orderLine->order->updateDispatchCost();
            }
        });

        static::deleted(function (OrderLine $orderLine) {
            if ($orderLine->order) {
                $orderLine->order->updateDispatchCost();
            }
        });
    }
}
