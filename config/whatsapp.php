<?php

return [
    'default_country_code' => env('WHATSAPP_DEFAULT_COUNTRY_CODE', '56'),
    'api_token' => env('WHATSAPP_API_ACCESS_TOKEN'),
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'test_phone_number' => env('WHATSAPP_TEST_PHONE_NUMBER'),
    'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
    'initial_template_name' => env('WHATSAPP_INITIAL_TEMPLATE_NAME', 'hello_world'),
];