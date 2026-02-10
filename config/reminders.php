<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Reminders Test Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, reminder strategies will ignore the configured hours_before
    | and hours_after values on triggers. Instead, entities will be eligible
    | after just 1 minute. This allows testing the full reminder flow without
    | waiting hours for triggers to fire.
    |
    | The actual trigger configuration is NOT modified — only the runtime
    | behavior changes while test_mode is true.
    |
    */
    'test_mode' => env('REMINDERS_TEST_MODE', false),

    /*
    |--------------------------------------------------------------------------
    | Test Mode Lookback Minutes
    |--------------------------------------------------------------------------
    |
    | When test_mode is enabled, this value determines how many minutes back
    | to look for eligible entities. Must be greater than the scheduler
    | interval (every 5 minutes) to avoid missing entities between runs.
    |
    */
    'test_mode_lookback_minutes' => env('REMINDERS_TEST_LOOKBACK_MINUTES', 10),

    /*
    |--------------------------------------------------------------------------
    | Pending Notification Expiration
    |--------------------------------------------------------------------------
    |
    | Hours to wait for a user to respond to the WhatsApp template before
    | marking the pending notification as expired.
    |
    */
    'pending_expiration_hours' => env('REMINDERS_PENDING_EXPIRATION_HOURS', 48),

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Templates per Event Type
    |--------------------------------------------------------------------------
    |
    | Each reminder event type must use a pre-approved WhatsApp template.
    | Templates are configured per event type with name and language.
    |
    */
    'templates' => [
        'menu_created' => [
            'name' => env('REMINDERS_TEMPLATE_MENU_CREATED', 'hello_world'),
            'language' => env('REMINDERS_TEMPLATE_MENU_CREATED_LANG', 'es'),
        ],
    ],

];