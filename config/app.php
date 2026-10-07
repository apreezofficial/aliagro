<?php

return [
    'name'         => env('APP_NAME', 'AliAgro'),
    'env'          => env('APP_ENV', 'production'),
    'debug'        => env('APP_DEBUG', false),
    'url'          => rtrim((string) env('APP_URL', 'http://localhost'), '/'),
    'frontend_url' => rtrim((string) env('FRONTEND_URL', env('APP_URL', 'http://localhost')), '/'),
];
