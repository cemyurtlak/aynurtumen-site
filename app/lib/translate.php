<?php
// Otomatik çeviri (DeepL). Değişen içerik (Google Takvim etkinlikleri, panelden girilen metinler, galeri klasör adları)
// DeepL'e gönderilir; sonuç locales/otomatik/<dil>.json dosyasına yazılır. Elle yazılmış çeviri her zaman önceliklidir.
// Kişisel bilgiler (danışan adı, telefon) hiçbir zaman gönderilmez. DEEPL_API_KEY yoksa hiçbir şey yapmaz.
final class Translate {
  const TARGET = ['en' => 'EN-GB', 'ru' => 'RU', 'de' => 'DE'];
  const FORMAL = ['ru' => 'prefer_more', 'de' => 'prefer_more'];

  static function key(): string { return Config::get('DEEPL_API_KEY'); }
  static function enabled(): bool { return self::key() !== ''; }
  static function endpoint(): string {
    return env('DEEPL_API_URL') ?: (str_ends_with(self::key(), ':fx') ? 'https://api-free.deepl.com/v2/translate' : 'https://api.deepl.com/v2/translate');
  }
  private static function worth($s): bool {
    return is_string($s) && mb_strlen($s) >= 2 && mb_strlen($s) <= 5000 && preg_match('/\p{L}{2}/u', $s) && !preg_match('/^[\w.-]+@[\w.-]+$|^https?:/', $s);
  }
  private static function state(): array { return Store::read('ceviri-durum', ['queue' => [], 'lastError' => null, 'translated' => 0, 'syncAt' => 0]); }

  static function request($texts): void {
    if (!self::enabled()) return;
    $add = [];
    foreach ((array)$texts as $raw) {
      $s = I18n::norm($raw ?? '');
      if (!self::worth($s)) continue;
      $all = true; foreach (['en', 'ru', 'de'] as $l) if (!I18n::has($s, $l)) { $all = false; break; }
      if (!$all) $add[] = $s;
    }
    if (!$add) return;
    Store::update('ceviri-durum', self::state(), function ($st) use ($add) { $st['queue'] = array_values(array_unique(array_merge($st['queue'] ?? [], $add))); return $st; });
    later([self::class, 'flush']);
  }

  private static function deepl(array $texts, string $l): array {
    $body = ['text' => $texts, 'source_lang' => 'TR', 'target_lang' => self::TARGET[$l], 'preserve_formatting' => true];
    if (isset(self::FORMAL[$l])) $body['formality'] = self::FORMAL[$l];
    [$code, $res] = http_post_json(self::endpoint(), $body, ['Authorization: DeepL-Auth-Key ' . self::key()]);
    if ($code === 456) throw new RuntimeException('DeepL aylık ücretsiz kotası doldu');
    if ($code === 403) throw new RuntimeException('DeepL anahtarı geçersiz');
    if ($code < 200 || $code >= 300) throw new RuntimeException("DeepL HTTP $code");
    return array_map(fn($x) => $x['text'], json_decode($res, true)['translations'] ?? []);
  }

  static function flush(): void {
    $lock = fopen(DATA_DIR . '/.ceviri.lock', 'c');
    if (!flock($lock, LOCK_EX | LOCK_NB)) return; // başka bir istek zaten çeviriyor
    try {
      $st = self::state();
      $items = $st['queue'] ?? [];
      if (!$items) return;
      $err = null; $done = 0;
      try {
        @mkdir(LOCALE_DIR . '/otomatik', 0775, true);
        foreach (['en', 'ru', 'de'] as $l) {
          $todo = array_values(array_filter($items, fn($s) => !I18n::has($s, $l)));
          if (!$todo) continue;
          $file = LOCALE_DIR . "/otomatik/$l.json";
          $auto = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
          foreach (array_chunk($todo, 40) as $batch) {
            $out = self::deepl($batch, $l);
            foreach ($batch as $k => $s) if (!empty($out[$k])) $auto[$s] = $out[$k];
            $done += count($batch);
          }
          file_put_contents($file, json_encode($auto, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $keepQueue = [];
      } catch (Throwable $e) {
        $err = $e->getMessage();
        $keepQueue = preg_match('/kota|geçersiz/u', $err) ? [] : $items; // geçici hata: sonra tekrar dene
      }
      Store::update('ceviri-durum', self::state(), function ($s) use ($items, $keepQueue, $err, $done) {
        $s['queue'] = array_values(array_unique(array_merge(array_values(array_diff($s['queue'] ?? [], $items)), $keepQueue)));
        $s['lastError'] = $err; $s['translated'] = ($s['translated'] ?? 0) + $done;
        return $s;
      });
    } finally { flock($lock, LOCK_UN); fclose($lock); }
  }

  // Panelden ya da dosyadan değişebilen içerik
  static function contentStrings(): array {
    $out = [];
    foreach (Events::all() as $e) array_push($out, $e['title'] ?? '', $e['description'] ?? '', $e['location'] ?? '');
    foreach (Store::read('services', []) as $x) array_push($out, $x['name'] ?? '', $x['short'] ?? '', $x['priceLabel'] ?? '');
    foreach (array_unique(array_column(galleryTree('galeri'), 'cat')) as $g) $out[] = $g;
    $s = Store::read('settings', []);
    array_push($out, $s['roles'] ?? '', $s['city'] ?? '');
    return array_values(array_filter($out));
  }
  static function syncNow(): void { self::request(self::contentStrings()); }
  // Her 15 dakikada bir içeriği kontrol eder (istekler sırasında, yanıttan sonra)
  static function tick(): void {
    if (!self::enabled()) return;
    $st = self::state();
    if (time() - (int)($st['syncAt'] ?? 0) < 900 && empty($st['queue'])) return;
    Store::update('ceviri-durum', $st, function ($s) { $s['syncAt'] = time(); return $s; });
    later([self::class, 'syncNow']);
    later([self::class, 'flush']);
  }
  static function status(): array {
    $st = self::state();
    return ['enabled' => self::enabled(), 'pending' => count($st['queue'] ?? []), 'lastError' => $st['lastError'] ?? null, 'translatedCount' => $st['translated'] ?? 0];
  }
}
