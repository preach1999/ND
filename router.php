<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . '/public_html' . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

if (str_starts_with($path, '/api/')) {
    return false;
}

require __DIR__ . '/public_html/index.html';
