<?php

// Author: ramanpal singh | URL: https://kwebby.com
return [
    'store' => env('CRM_STORE', 'sql'),
    'bootstrap_token' => env('CRM_BOOTSTRAP_TOKEN'),
    'private_path' => env('CRM_PRIVATE_PATH', storage_path('app/private/vault')),
    'firestore' => ['project' => env('FIRESTORE_PROJECT_ID'), 'database' => env('FIRESTORE_DATABASE', '(default)'), 'credentials' => env('GOOGLE_APPLICATION_CREDENTIALS'), 'emulator' => env('FIRESTORE_EMULATOR_HOST')],
    'require_mfa' => env('CRM_REQUIRE_MFA', true),
    'approved_hosts' => array_filter(explode(',', env('CRM_APPROVED_HOSTS', ''))),
    'scanner' => ['url' => env('SCANNER_URL'), 'key' => env('SCANNER_API_KEY')],
    'ai_daily_limit' => (int) env('AI_DAILY_RUN_LIMIT', 50),
    'version' => '0.1.0',
];
