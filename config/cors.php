<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    // The web app, and the phone apps' WebViews (Capacitor: https://localhost on Android, capacitor://localhost on iOS).
    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:5173'), 'http://127.0.0.1:5173', ...explode(',', env('MOBILE_ORIGINS', 'https://localhost,capacitor://localhost'))],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    // Browsers remember a preflight answer for an hour instead of asking before every call.
    'max_age' => 3600,
    'supports_credentials' => false,
];
