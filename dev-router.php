<?php
// Bilgisayarda deneme: php -S 127.0.0.1:4100 -t . dev-router.php
$f = __DIR__ . '' . rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (is_file($f) && !str_ends_with($f, '.php')) return false;
define('PUBLIC_DIR', __DIR__ . '');
require __DIR__ . '/app/router.php';
