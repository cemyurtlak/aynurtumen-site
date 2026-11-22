<?php
// Çok dilli site altyapısı (Node sürümündeki lib/i18n.js ile aynı mantık)
// Kaynak dil Türkçe. Sayfa üretilirken metinler locales/<dil>.json sözlüğünden çevrilir.
final class I18n {
  const LANGS = ['tr', 'en', 'ru', 'de'];
  const NAMES = ['tr' => 'Türkçe', 'en' => 'English', 'ru' => 'Русский', 'de' => 'Deutsch'];
  const LOCALE = ['tr' => 'tr-TR', 'en' => 'en-GB', 'ru' => 'ru-RU', 'de' => 'de-DE'];
  const OG = ['tr' => 'tr_TR', 'en' => 'en_GB', 'ru' => 'ru_RU', 'de' => 'de_DE'];
  // JavaScript'teki \s ile aynı boşluk karakterleri
  const WS = '[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';
  const SLOT = ['n' => '(\d[\d., ]*(?:' . self::WS . '?B)?)', 'a' => '(\d+)', 'b' => '(\d+)', 'p' => '(₺?' . self::WS . '?\d[\d., ]*)'];

  static string $lang = 'tr';
  static string $base = '';
  private static array $dicts = [];
  private static ?array $keep = null;

  static function lang(): string { return self::$lang; }
  static function set(string $l, string $base = ''): void { self::$lang = in_array($l, self::LANGS, true) ? $l : 'tr'; self::$base = $base; }

  static function norm($s): string {
    $s = preg_replace('/' . self::WS . '+/u', ' ', (string)$s);
    return preg_replace('/^' . self::WS . '+|' . self::WS . '+$/u', '', $s);
  }

  private static function readJson(string $file): array {
    if (!is_file($file)) return [0, []];
    $j = json_decode((string)file_get_contents($file), true);
    return [filemtime($file), is_array($j) ? $j : []];
  }

  static function dict(string $l): ?array {
    if ($l === 'tr') return null;
    if (isset(self::$dicts[$l])) return self::$dicts[$l];
    [, $curated] = self::readJson(LOCALE_DIR . "/$l.json");
    [, $auto] = self::readJson(LOCALE_DIR . "/otomatik/$l.json");
    $raw = $auto;
    foreach ($curated as $k => $v) $raw[(string)$k] = $v;
    $exact = []; $patterns = [];
    foreach ($raw as $k => $v) {
      $k = (string)$k;
      if (str_starts_with($k, '//') || !is_string($v) || $v === '') continue;
      $key = self::norm($k);
      if (preg_match('/\{\w+\}/', $key)) {
        $names = []; $re = '';
        foreach (preg_split('/(\{\w+\})/', $key, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
          if (preg_match('/^\{(\w+)\}$/', $part, $m)) { $names[] = $m[1]; $re .= self::SLOT[$m[1]] ?? '(.+?)'; }
          else $re .= preg_quote($part, '/');
        }
        $patterns[] = ['re' => '/^' . $re . '$/u', 'names' => $names, 'value' => $v, 'len' => intdiv(strlen(mb_convert_encoding($key, 'UTF-16LE', 'UTF-8')), 2)];
      } else $exact[$key] = $v;
    }
    usort($patterns, fn($a, $b) => $b['len'] <=> $a['len']);
    return self::$dicts[$l] = ['exact' => $exact, 'patterns' => $patterns, 'raw' => $raw];
  }

  static function keep(): array {
    if (self::$keep !== null) return self::$keep;
    [, $j] = self::readJson(LOCALE_DIR . '/_ayni-kalsin.json');
    self::$keep = [];
    foreach ($j['metinler'] ?? [] as $s) self::$keep[self::norm($s)] = true;
    return self::$keep;
  }

  static function t($str, ?string $l = null) {
    $l ??= self::$lang;
    if ($str === null || $l === 'tr') return $str;
    $d = self::dict($l);
    $key = self::norm($str);
    if ($key === '') return $str;
    if (isset(self::keep()[$key])) return $str;
    if (array_key_exists($key, $d['exact'])) return $d['exact'][$key];
    foreach ($d['patterns'] as $p) {
      if (preg_match($p['re'], $key, $m)) {
        $out = $p['value'];
        foreach ($p['names'] as $i => $n) $out = str_replace('{' . $n . '}', (string)self::t($m[$i + 1], $l), $out);
        return $out;
      }
    }
    return $str;
  }

  static function exact(string $str, ?string $l = null): ?string {
    $l ??= self::$lang;
    if ($l === 'tr') return null;
    return self::dict($l)['exact'][self::norm($str)] ?? null;
  }

  static function has(string $str, string $l): bool {
    $d = self::dict($l); $key = self::norm($str);
    if (array_key_exists($key, $d['exact'])) return true;
    foreach ($d['patterns'] as $p) if (preg_match($p['re'], $key)) return true;
    return false;
  }

  // ---- adresler ----
  const SKIP_PREFIX = '/^\/(css|js|images|fonts|api|admin|favicon|icon-|site\.webmanifest|robots\.txt|sitemap\.xml)(\/|$|\.|\d)/';
  static function prefix(string $url, ?string $l = null): string {
    $l ??= self::$lang;
    if ($l === 'tr' || !str_starts_with($url, '/') || str_starts_with($url, '//') || preg_match(self::SKIP_PREFIX, $url)) return $url;
    if ($url === '/') return "/$l";
    if (preg_match('/^\/[?#]/', $url)) return "/$l" . substr($url, 1);
    return "/$l$url";
  }
  static function splitPath(string $p): array {
    if (preg_match('/^\/(en|ru|de)(\/.*|$)/', $p, $m)) return [$m[1], ($m[2] ?? '') === '' ? '/' : $m[2]];
    return ['tr', $p];
  }

  // ---- HTML çevirisi ----
  static function decode(string $s): string {
    return preg_replace_callback('/&(amp|lt|gt|quot|#39);/', fn($m) => ['amp' => '&', 'lt' => '<', 'gt' => '>', 'quot' => '"', '#39' => "'"][$m[1]], $s);
  }
  static function encode(string $s): string { return strtr($s, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']); }
  static function encodeAttr(string $s): string { return str_replace('"', '&quot;', self::encode($s)); }

  static function translateHtml(string $html, ?string $l = null, ?array &$collect = null): string {
    $l ??= self::$lang;
    $collecting = is_array($collect);
    if ($l === 'tr' && !$collecting) return preg_replace('/(href|action)="@@/', '$1="', $html);
    $conv = function (string $s) use ($l, $collecting, &$collect) {
      $k = self::norm(self::decode($s));
      if ($k === '' || !preg_match('/[A-Za-zÇĞİÖŞÜçğıöşüÂâÎîÛû]/u', $k)) return null;
      if ($collecting) { $collect[$k] = true; return null; }
      $out = self::t($k, $l);
      return $out === $k ? null : $out;
    };
    $parts = preg_split('/(<script\b[\s\S]*?<\/script>|<style\b[\s\S]*?<\/style>|<!--i18n-off-->[\s\S]*?<!--i18n-on-->)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $i => &$part) {
      if ($i % 2 === 1) continue;
      $part = preg_replace_callback('/>([^<>]+)</u', function ($m) use ($conv) {
        $out = $conv($m[1]);
        if ($out === null) return $m[0];
        preg_match('/^' . self::WS . '*/u', $m[1], $lead); preg_match('/' . self::WS . '*$/u', $m[1], $trail);
        return '>' . $lead[0] . self::encode($out) . $trail[0] . '<';
      }, $part);
      $part = preg_replace_callback('/\s(alt|title|aria-label|placeholder)="([^"]*)"/u', function ($m) use ($conv) {
        $out = $conv($m[2]);
        return $out === null ? $m[0] : ' ' . $m[1] . '="' . self::encodeAttr($out) . '"';
      }, $part);
      if (!$collecting && $l !== 'tr') $part = preg_replace_callback('/\s(href|action)="(\/[^"]*)"/', fn($m) => ' ' . $m[1] . '="' . self::encodeAttr(self::prefix(self::decode($m[2]), $l)) . '"', $part);
    }
    unset($part);
    return preg_replace('/(href|action)="@@/', '$1="', implode('', $parts));
  }

  // ---- tarih ve süre ----
  static function dateParts($d, ?string $l = null): array {
    $l ??= self::$lang;
    $loc = self::LOCALE[$l];
    $t = ts($d);
    $get = fn($sk) => intl_fmt($t, $loc, $sk);
    $month = preg_replace('/^' . self::WS . '+|' . self::WS . '+$/u', '', preg_replace('/[\d.' . substr(self::WS, 1, -1) . ']/u', ' ', $get('dMMMM')));
    return [
      'day' => (int)preg_replace('/\D/', '', $get('d')),
      'month' => $month,
      'dow' => upper_first($get('EEEE'), $loc),
      'hm' => $get('HHmm'),
      'year' => (int)$get('y'),
      'long' => upper_first($get('dMMMMEEEE'), $loc),
    ];
  }

  const UNITS = [
    'tr' => ['d' => 'gün', 'h' => 'saat', 'hs' => 'sa', 'm' => 'dk'],
    'en' => ['d' => 'days', 'd1' => 'day', 'h' => 'hours', 'h1' => 'hour', 'hs' => 'h', 'm' => 'min'],
    'ru' => ['d' => 'дн.', 'h' => 'ч', 'hs' => 'ч', 'm' => 'мин'],
    'de' => ['d' => 'Tage', 'd1' => 'Tag', 'h' => 'Std.', 'hs' => 'Std.', 'm' => 'Min.'],
  ];
  static function duration(int $min, ?string $l = null): string {
    $u = self::UNITS[$l ?? self::$lang];
    if ($min >= 1440) { $n = (int)round($min / 1440); return "$n " . ($n === 1 && isset($u['d1']) ? $u['d1'] : $u['d']); }
    if ($min >= 60) {
      $h = intdiv($min, 60); $m = $min % 60;
      return $m ? "$h {$u['hs']} $m {$u['m']}" : "$h " . ($h === 1 && isset($u['h1']) ? $u['h1'] : $u['h']);
    }
    return "$min {$u['m']}";
  }

  static function clientDict(?string $l = null): array {
    $l ??= self::$lang;
    if ($l === 'tr') return [];
    return self::dict($l)['raw'];
  }
}

function t($s, ?string $l = null) { return I18n::t($s, $l); }
