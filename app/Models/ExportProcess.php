<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExportProcess extends Model
{
    protected $fillable = [
        'type',
        'description',
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
    const TYPE_PRICE_LISTS = 'lista de precios';
    const TYPE_PRICE_LIST_LINES = 'líneas de lista de precio';
    const TYPE_MENUS = 'menús';
    const TYPE_MENU_CATEGORIES = 'categorías de menus';
    const TYPE_USERS = 'usuarios';
    const TYPE_ORDER_LINES = 'líneas de pedidos';
    const ORDER_CONSOLIDATED = 'consolidado de pedidos';
    const TYPE_VOUCHERS = 'vouchers';

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
            self::TYPE_ORDER_LINES,
            self::ORDER_CONSOLIDATED,
            self::TYPE_VOUCHERS,
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
