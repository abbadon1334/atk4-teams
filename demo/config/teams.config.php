<?php

declare(strict_types=1);

return [
    'teams' => [
        'app_id' => '123456789',
        'app_secret' => '123456789',
        'app_redirect_uri' => 'https://www.yourdomain.com/',
        'app_scopes' => [
            'openid',
            'profile',
            'email',
            'offline_access',
        ],
        'app_redirect_uri_on_success' => 'https://www.yourdomain.com/',
        'app_redirect_uri_on_logout' => 'https://www.yourdomain.com/',
        'session_cookie_params' => [
            'lifetime' => 0,
            'path' => '/',
            'domain' => '.yourdomain.com',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'None',
        ],
    ],
];
