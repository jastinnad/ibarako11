<?php
// config.php - Professional Configuration
return [
    'db' => [
        'host' => '127.0.0.1',
        'dbname' => 'ibarako',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4'
    ],
    'app' => [
        'name' => 'iBarako Loan System',
        'version' => '2.0.0',
        'base_url' => '/ibarako',
        'timezone' => 'Asia/Manila'
    ],
    'security' => [
        'min_password_length' => 8,
        'max_login_attempts' => 5,
        'lockout_time' => 900, // 15 minutes
        'session_timeout' => 3600 // 1 hour
    ],
    'loan' => [
        'min_amount' => 1000,
        'max_amount' => 50000,
        'default_interest_rate' => 2.0,
        'terms' => [3, 6, 9, 12]
    ]
];