<?php
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'http://localhost:4200',
        'http://localhost:4201',
    ],
    'allowed_origins_patterns' => [
        '#^https://generator-front(-[a-z0-9-]+)?\.vercel\.app$#',
        '#^https://generated-app(-[a-z0-9-]+)?\.vercel\.app$#',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
