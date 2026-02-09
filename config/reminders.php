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
    | Pending Notification Expiration
    |--------------------------------------------------------------------------
    |
    | Hours to wait for a user to respond to the WhatsApp template before
    | marking the pending notification as expired.
    |
    */
    'pending_expiration_hours' => env('REMINDERS_PENDING_EXPIRATION_HOURS', 48),

];