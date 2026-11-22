<?php
// Aynur Tümen web sitesi — giriş noktası.
// Uygulama dosyaları "app" klasöründedir: tercihen bu klasörün bir üstünde (public_html'in dışında),
// değilse public_html/app içinde (o durumda app/.htaccess dışarıdan erişimi kapatır).
define('PUBLIC_DIR', __DIR__);
$app = is_dir(__DIR__ . '/../app') ? __DIR__ . '/../app' : __DIR__ . '/app';
require $app . '/router.php';
