<?php

$scheme = env('MAIL_SCHEME');

return [
    'mailer'     => env('MAIL_MAILER', 'log'),          // log | smtp | mail
    'host'       => env('MAIL_HOST', '127.0.0.1'),
    'port'       => env('MAIL_PORT', 2525),
    // ssl (implicit TLS, port 465) | tls (STARTTLS, port 587) | empty = auto
    'encryption' => env('MAIL_ENCRYPTION', $scheme === 'smtps' ? 'ssl' : null),
    'username'   => env('MAIL_USERNAME'),
    'password'   => env('MAIL_PASSWORD'),
    'from'       => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name'    => env('MAIL_FROM_NAME', env('APP_NAME', 'AliAgro')),
    ],
];
