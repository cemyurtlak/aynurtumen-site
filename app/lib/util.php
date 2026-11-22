<?php
// JavaScript davranışını birebir taklit eden küçük yardımcılar

// encodeURIComponent: A-Z a-z 0-9 - _ . ! ~ * ' ( ) kodlanmaz
function js_uri(string $s): string {
  return strtr(rawurlencode($s), ['%21' => '!', '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')']);
}

// JSON.stringify karşılığı
function js_json($v): string {
  return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_PRESERVE_ZERO_FRACTION);
}

// Türkçe büyük/küçük harf (i/İ, ı/I)
function tr_lower(string $s): string { return mb_strtolower(strtr($s, ['I' => 'ı', 'İ' => 'i']), 'UTF-8'); }
function tr_upper(string $s): string { return mb_strtoupper(strtr($s, ['i' => 'İ', 'ı' => 'I']), 'UTF-8'); }
function upper_first(string $s, string $loc = 'tr-TR'): string {
  if ($s === '') return $s;
  $c = mb_substr($s, 0, 1);
  return (str_starts_with($loc, 'tr') ? tr_upper($c) : mb_strtoupper($c)) . mb_substr($s, 1);
}

// ISO tarih metnini zaman damgasına (ms değil, saniye) çevirir
function ts($iso): int {
  if (is_int($iso)) return $iso;
  $t = strtotime((string)$iso);
  return $t === false ? 0 : $t;
}
// Date.toISOString()
function iso_utc(int $t): string { return gmdate('Y-m-d\TH:i:s', $t) . '.000Z'; }

function rand_hex(int $bytes): string { return bin2hex(random_bytes($bytes)); }

// Number(x).toLocaleString(loc)
function num_loc($n, string $loc): string {
  static $f = [];
  $f[$loc] ??= new NumberFormatter($loc, NumberFormatter::DECIMAL);
  return $f[$loc]->format($n);
}

// Intl.DateTimeFormat(loc, seçenekler).format() — iskeletten desen üretir (JS ile aynı ICU mantığı)
function intl_fmt(int $t, string $loc, string $skeleton, string $tz = 'Europe/Istanbul'): string {
  static $cache = [];
  $key = "$loc|$skeleton|$tz";
  if (!isset($cache[$key])) {
    $gen = new IntlDatePatternGenerator($loc);
    $pattern = $gen->getBestPattern($skeleton);
    $cache[$key] = new IntlDateFormatter($loc, IntlDateFormatter::NONE, IntlDateFormatter::NONE, $tz, IntlDateFormatter::GREGORIAN, $pattern);
  }
  return $cache[$key]->format($t);
}

function http_get(string $url, int $timeout = 10, array $headers = []): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min(5, $timeout), CURLOPT_HTTPHEADER => $headers, CURLOPT_USERAGENT => 'Mozilla/5.0 aynurtumen-site']);
  $body = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  return [$code, $body === false ? '' : $body, $err];
}

function http_post_json(string $url, array $data, array $headers = [], int $timeout = 20): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => js_json($data), CURLOPT_TIMEOUT => $timeout, CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers)]);
  $body = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  return [$code, $body === false ? '' : $body];
}
