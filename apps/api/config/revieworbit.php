<?php

return [
    'seed' => [
        'admin_email' => env('SEED_SUPER_ADMIN_EMAIL', 'admin@revieworbit.test'),
        'owner_email' => env('SEED_BUSINESS_OWNER_EMAIL', 'owner@albarbershop.test'),
        'password' => env('SEED_DEFAULT_PASSWORD'),
    ],
];
