<?php

return [
    'google_client_id' => env('GOOGLE_CLIENT_ID'),
    'token_days' => (int) env('SESSION_TOKEN_DAYS', 30),
    'pey_enabled' => (bool) env('PEY_ENABLED', false),
    'pey_key' => env('PEY_API_KEY'),
    'pey_key_id' => env('PEY_API_KEY_ID'),
    'webhook_secret' => env('PEY_WEBHOOK_SECRET'),
    'signing_key_path' => env('EVENT_SIGNING_KEY_PATH'),
    'signing_kid' => env('EVENT_SIGNING_KID'),
    'fcm_credentials_path' => env('GOOGLE_APPLICATION_CREDENTIALS'),
    'fcm_project_id' => env('FCM_PROJECT_ID'),
];
