<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class OrderLine extends Model
{
    protected $fillable = [
        'quantity',
        'unit_price',
        'order_id',
        'product_id',
        'partially_scheduled'
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
}
