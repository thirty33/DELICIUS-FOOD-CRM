<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Defontana API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Defontana billing integration API
    | Note: API URLs come from the active Integration record in the database
    |
    */

    'auth' => [
        'client' => env('DEFONTANA_CLIENT', ''),
        'company' => env('DEFONTANA_COMPANY', ''),
        'user' => env('DEFONTANA_USER', ''),
        'password' => env('DEFONTANA_PASSWORD', ''),
    ],

    'endpoints' => [
        'auth' => '/api/auth',
        'save_sale' => '/api/sale/SaveSale',
    ],

    'timeout' => env('DEFONTANA_API_TIMEOUT', 30),

    'sale_defaults' => [
        'document_type' => env('DEFONTANA_DOCUMENT_TYPE', '33'),
        'payment_condition' => env('DEFONTANA_PAYMENT_CONDITION', 'CONTADO'),
        'seller_file_id' => env('DEFONTANA_SELLER_FILE_ID', '11111111-1'),
        'billing_coin' => env('DEFONTANA_BILLING_COIN', 'PESO'),
        'billing_rate' => env('DEFONTANA_BILLING_RATE', 1),
        'shop_id' => env('DEFONTANA_SHOP_ID', 1),
        'price_list' => env('DEFONTANA_PRICE_LIST', 'GENERAL'),
        'giro' => env('DEFONTANA_GIRO', 'VENTA DE ALIMENTOS'),
        'storage_code' => env('DEFONTANA_STORAGE_CODE', 'BODEGA01'),
        'storage_motive' => env('DEFONTANA_STORAGE_MOTIVE', 'VENTA'),
        'tax_code' => env('DEFONTANA_TAX_CODE', 'IVA'),
        'tax_value' => env('DEFONTANA_TAX_VALUE', 19),
        'is_transfer_document' => env('DEFONTANA_IS_TRANSFER_DOCUMENT', true),
    ],
];
