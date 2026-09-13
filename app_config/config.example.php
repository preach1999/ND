<?php
declare(strict_types=1);

return [
    'database' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'REPLACE_DATABASE_NAME',
        'user' => 'REPLACE_DATABASE_USER',
        'password' => 'REPLACE_DATABASE_PASSWORD',
    ],
    'security' => [
        'username' => 'ndadmin',
        // SHA-256 of a long, unique administrator password.
        'password_sha256' => 'REPLACE_PASSWORD_SHA256',
    ],
    'company' => [
        'name' => 'N & D Commercial Development Corp.',
        'location' => 'Butuay, Jimenez, Misamis Occidental',
    ],
];
