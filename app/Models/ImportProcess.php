<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImportProcess extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'status',
        'error_log',
        'file_url',
        'file_error_url'
    ];

    protected $casts = [
        'error_log' => 'array'
    ];

    // Constantes para los tipos de importación
    const TYPE_COMPANIES = 'empresas';
    const TYPE_BRANCHES = 'sucursales';
    const TYPE_CATEGORIES = 'categorias';
    const TYPE_DISPATCH_LINES = 'lineas de despacho';
    const TYPE_PRODUCTS = 'productos';
    const TYPE_PRODUCTS_IMAGES = 'imágenes de productos';
    const TYPE_PRICE_LISTS = 'lista de precios';
    const TYPE_PRICE_LIST_LINES = 'líneas de lista de precio';
    const TYPE_MENUS = 'menús';
    const TYPE_MENU_CATEGORIES = 'categorías de menus';
    const TYPE_USERS = 'usuarios';
    const TYPE_ORDERS = 'órdenes';
    const TYPE_NUTRITIONAL_INFORMATION = 'informacion nutricional';
    const TYPE_PLATED_DISH_INGREDIENTS = 'emplatados';

    // Constantes para los estados
    const STATUS_QUEUED = 'en cola';
    const STATUS_PROCESSING = 'procesando';
    const STATUS_PROCESSED = 'procesado';
    const STATUS_PROCESSED_WITH_ERRORS = 'procesado con errores';

    /**
     * Get valid import types
     */
    public static function getValidTypes(): array
    {
        return [
            self::TYPE_COMPANIES,
            self::TYPE_BRANCHES,
            self::TYPE_CATEGORIES,
            self::TYPE_DISPATCH_LINES,
            self::TYPE_PRODUCTS,
            self::TYPE_PRICE_LISTS,
            self::TYPE_PRICE_LIST_LINES,
            self::TYPE_MENUS,
            self::TYPE_MENU_CATEGORIES,
            self::TYPE_USERS,
            self::TYPE_PRODUCTS_IMAGES,
            self::TYPE_ORDERS,
            self::TYPE_NUTRITIONAL_INFORMATION,
            self::TYPE_PLATED_DISH_INGREDIENTS,
        ];
    }

    /**
     * Get valid statuses
     */
    public static function getValidStatuses(): array
    {
        return [
            self::STATUS_QUEUED,
            self::STATUS_PROCESSING,
            self::STATUS_PROCESSED,
            self::STATUS_PROCESSED_WITH_ERRORS
        ];
    }
}