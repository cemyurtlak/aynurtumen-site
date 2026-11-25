<?php
// Aynur Tümen web sitesi — PHP sürümü
// Her istek public_html/index.php üzerinden buraya gelir. Ayarlar app/ayarlar.env dosyasındadır.
declare(strict_types=1);

const APP_DIR = __DIR__;
const DATA_DIR = __DIR__ . '/data';
const LOCALE_DIR = __DIR__ . '/locales';
if (!defined('PUBLIC_DIR')) define('PUBLIC_DIR', is_file(dirname(__DIR__) . '/index.php') ? dirname(__DIR__) : dirname(__DIR__) . '/public_html');

date_default_timezone_set('Europe/Istanbul');
mb_internal_encoding('UTF-8');

// .env dosyasını oku (KEY=değer satırları; # ile başlayanlar yorum)
(function () {
  $f = is_file(APP_DIR . '/ayarlar.env') ? APP_DIR . '/ayarlar.env' : APP_DIR . '/.env';
  if (!is_file($f)) return;
  foreach (file($f, FILE_IGNORE_NEW_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = array_map('trim', explode('=', $line, 2));
    if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) $v = substr($v, 1, -1);
    if (getenv($k) === false) putenv("$k=$v");
    $_ENV[$k] = $v;
  }
})();

function env(string $k, string $default = ''): string {
  $v = getenv($k);
  if ($v === false || $v === '') $v = $_ENV[$k] ?? '';
  return $v === '' ? $default : (string)$v;
}

// Yanıttan sonra arka planda çalışacak işler (takvim yenileme, çeviri)
$GLOBALS['__later'] = [];
function later(callable $fn): void { $GLOBALS['__later'][] = $fn; }
register_shutdown_function(function () {
  if (!$GLOBALS['__later']) return;
  if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
  elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
  ignore_user_abort(true);
  @set_time_limit(60);
  foreach ($GLOBALS['__later'] as $fn) { try { $fn(); } catch (Throwable $e) { error_log('[arka plan] ' . $e->getMessage()); } }
});

foreach (['util', 'store', 'i18n', 'config', 'translate', 'ical', 'calendar', 'events', 'booking', 'youtube', 'instagram', 'mailer', 'yaziceviri', 'whatsapp', 'asistan', 'etkinlikbot'] as $m) require APP_DIR . "/lib/$m.php";
foreach (['helpers', 'layout', 'pages', 'admin'] as $m) require APP_DIR . "/views/$m.php";
