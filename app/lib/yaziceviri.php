<?php
// Yazıların (blog) İngilizce, Rusça ve Almanca çevirisi — Gemini ile, bir kez yapılır ve saklanır.
// Anahtarlar yalnız sunucudaki app/ayarlar.env içinde: GEMINI_KEYS=anahtar1,anahtar2,... (GitHub'a gitmez).
// Çeviriler app/data/yazi-ceviri/<dil>.json dosyasında. Türkçe yazı değişirse (özeti değişir) yeniden çevrilir.
// Her istek yalnız bu sitenin metnini ve talimatını içerir; yapay zekâ başka sitelerle hiçbir bilgi paylaşmaz.
// Çalıştırma: GET /api/yazi-cevir?k=<GÜNCELLEME_ANAHTARI>  (her çağrı ~40 sn çalışır, kaldığı yerden devam eder)
final class YaziCeviri {
  const LANGS = ['en' => 'English (British spelling)', 'ru' => 'Russian (use the polite «вы» form)', 'de' => 'German (use the polite «Sie» form)'];
  const PARCA = 6000; // HTML parça boyu (karakter)

  static function dir(): string { return DATA_DIR . '/yazi-ceviri'; }
  static function file(string $l): string { return self::dir() . "/$l.json"; }
  private static array $c = [];
  static function all(string $l): array {
    if (!isset(self::$c[$l])) { $f = self::file($l); self::$c[$l] = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : []; }
    return self::$c[$l];
  }
  static function src(array $p): string { return substr(sha1(($p['title'] ?? '') . "\0" . ($p['excerpt'] ?? '') . "\0" . ($p['html'] ?? '')), 0, 16); }
  static function parts(string $html): array {
    $blocks = preg_split('/\n(?=<)/u', trim($html)) ?: [$html];
    $out = []; $cur = '';
    foreach ($blocks as $b) {
      if ($cur !== '' && mb_strlen($cur) + mb_strlen($b) > self::PARCA) { $out[] = $cur; $cur = ''; }
      $cur .= ($cur === '' ? '' : "\n") . $b;
    }
    if ($cur !== '') $out[] = $cur;
    return $out;
  }
  // Tamamlanmış çeviri (yoksa null)
  static function get(array $p, string $l): ?array {
    if ($l === 'tr' || !isset(self::LANGS[$l])) return null;
    $t = self::all($l)[$p['slug']] ?? null;
    if (!$t || ($t['src'] ?? '') !== self::src($p) || empty($t['done'])) return null;
    return ['title' => $t['title'], 'excerpt' => $t['excerpt'], 'html' => implode("\n", $t['parts'])];
  }
  static function langsFor(array $p): array {
    $out = ['tr'];
    foreach (array_keys(self::LANGS) as $l) if (self::get($p, $l)) $out[] = $l;
    return $out;
  }

  static function keys(): array {
    return array_values(array_filter(preg_split('/[\s,;]+/', env('GEMINI_KEYS')) ?: [], fn($k) => strlen($k) >= 30));
  }
  static function models(): array {
    return array_values(array_filter(array_map('trim', explode(',', env('GEMINI_MODELS') ?: 'gemini-3.6-flash, gemini-3.5-flash-lite, gemini-3.8-flash'))));
  }

  private static function gemini(string $prompt, string $key, string $model, int $timeout): array {
    $body = ['contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
      'generationConfig' => ['temperature' => 0.2, 'responseMimeType' => 'application/json', 'maxOutputTokens' => 16384]];
    $ch = curl_init((env('GEMINI_BASE') ?: 'https://generativelanguage.googleapis.com') . '/v1beta/models/' . rawurlencode($model) . ':generateContent');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key], CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE)]);
    $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch); curl_close($ch);
    if ($res === false) throw new RuntimeException('bağlantı: ' . $err);
    $j = json_decode((string)$res, true);
    if ($code !== 200) throw new RuntimeException("HTTP $code: " . mb_substr((string)($j['error']['message'] ?? ''), 0, 120));
    $t = ''; foreach ($j['candidates'][0]['content']['parts'] ?? [] as $p) if (empty($p['thought'])) $t .= (string)($p['text'] ?? '');
    $o = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', $t)), true);
    if (!is_array($o)) throw new RuntimeException('JSON çözülemedi');
    return $o;
  }

  // Sırayla model × anahtar dener; kota dolan (429) anahtar+model bir süre atlanır
  private static function ask(string $prompt, int $budget): array {
    $waitF = DATA_DIR . '/yazi-ceviri/.bekle.json';
    $wait = is_file($waitF) ? array_filter(json_decode((string)file_get_contents($waitF), true) ?: [], fn($t) => $t > time()) : [];
    $errs = []; $end = time() + $budget;
    foreach (self::models() as $m) foreach (self::keys() as $i => $k) {
      $id = "k$i:$m";
      if (isset($wait[$id]) || isset($wait["k$i"])) continue;
      $left = $end - time(); if ($left < 8) break 2;
      try { return self::gemini($prompt, $k, $m, min(45, $left)); }
      catch (Throwable $e) {
        $msg = $e->getMessage(); $errs[] = "anahtar " . ($i + 1) . " $m: " . mb_substr($msg, 0, 80);
        if (str_contains($msg, 'HTTP 429')) $wait[$id] = time() + (preg_match('/per ?day|daily/i', $msg) ? 6 * 3600 : 15 * 60);
        elseif (preg_match('/HTTP 40[013]/', $msg)) $wait["k$i"] = time() + 3600;
        @file_put_contents($waitF, json_encode($wait), LOCK_EX);
      }
    }
    throw new RuntimeException($errs ? implode(' | ', array_slice($errs, -4)) : 'uygun anahtar/model kalmadı (kota beklemede)');
  }

  private static function prompt(string $l, string $task, string $json): string {
    return "You translate articles for the website of Aynur Tümen, a Turkish family constellation (\"aile dizimi\") facilitator and author.\n"
      . "Translate from Turkish into " . self::LANGS[$l] . ".\n"
      . "Rules: translate faithfully, do not add, remove, summarise or explain anything; keep a warm, clear, professional tone.\n"
      . "Terminology: \"aile dizimi\" / \"konstelasyon\" = family constellation(s) / семейные расстановки / Familienaufstellung. Keep personal names unchanged.\n"
      . $task . "\nReturn ONLY a JSON object, no markdown.\n\n" . $json;
  }

  // Bir sonraki eksik parçayı çevirir. Dönüş: ['done'=>bool,'did'=>açıklama] ya da istisna
  static function step(int $budget): array {
    $posts = posts();
    foreach (array_keys(self::LANGS) as $l) {
      $all = self::all($l);
      foreach ($posts as $p) {
        $src = self::src($p); $t = $all[$p['slug']] ?? null;
        if ($t && ($t['src'] ?? '') === $src && !empty($t['done'])) continue;
        $parts = self::parts((string)$p['html']);
        if (!$t || ($t['src'] ?? '') !== $src) $t = ['src' => $src, 'title' => null, 'excerpt' => null, 'parts' => [], 'n' => count($parts), 'done' => false];
        if ($t['title'] === null) {
          $o = self::ask(self::prompt($l, 'Translate the article title and its short summary. Output keys: "title", "excerpt".',
            json_encode(['title' => $p['title'], 'excerpt' => $p['excerpt'] ?? ''], JSON_UNESCAPED_UNICODE)), $budget);
          if (empty($o['title'])) throw new RuntimeException('başlık boş geldi');
          $t['title'] = (string)$o['title']; $t['excerpt'] = (string)($o['excerpt'] ?? ''); $did = "başlık";
        } else {
          $i = count($t['parts']);
          $o = self::ask(self::prompt($l, 'Translate this HTML fragment of the article body. Keep every HTML tag, attribute and link exactly as it is; translate only the visible text. Output key: "html".',
            json_encode(['html' => $parts[$i]], JSON_UNESCAPED_UNICODE)), $budget);
          $h = (string)($o['html'] ?? '');
          if ($h === '' || substr_count($h, '<') < substr_count($parts[$i], '<') * 0.8) throw new RuntimeException('HTML parçası eksik geldi');
          $t['parts'][] = $h; $did = 'parça ' . ($i + 1) . '/' . count($parts);
        }
        $t['done'] = $t['title'] !== null && count($t['parts']) >= count($parts);
        $t['at'] = gmdate('c');
        self::save($l, $p['slug'], $t);
        return ['done' => false, 'did' => "$l · {$p['slug']} · $did"];
      }
    }
    return ['done' => true, 'did' => 'hepsi çevrildi'];
  }

  private static function save(string $l, string $slug, array $t): void {
    @mkdir(self::dir(), 0775, true);
    $f = self::file($l); $fh = fopen("$f.lock", 'c'); flock($fh, LOCK_EX);
    $all = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $all[$slug] = $t;
    file_put_contents("$f.tmp", json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); rename("$f.tmp", $f);
    self::$c[$l] = $all;
    flock($fh, LOCK_UN); fclose($fh);
  }

  static function progress(): array {
    $posts = posts(); $out = [];
    foreach (array_keys(self::LANGS) as $l) { $n = 0; foreach ($posts as $p) if (self::get($p, $l)) $n++; $out[$l] = "$n/" . count($posts); }
    return $out;
  }

  // Ziyaretçi geldikçe arka planda (yanıt gönderildikten sonra) en çok 2 dakikada bir ~35 sn ilerler; hepsi bitince 6 saatte bir yalnız kontrol eder
  static function tick(): void {
    if (!self::keys()) return;
    $f = DATA_DIR . '/.yazi-ceviri-tick'; $last = is_file($f) ? (int)filemtime($f) : 0;
    $doneF = DATA_DIR . '/.yazi-ceviri-bitti';
    $gap = is_file($doneF) && (int)filemtime($doneF) >= (int)@filemtime(DATA_DIR . '/posts.json') ? 6 * 3600 : 120;
    if (time() - $last < $gap) return;
    @touch($f);
    later(function () use ($doneF) { $r = self::run(35); if (!empty($r['done'])) @touch($doneF); else @unlink($doneF); });
  }

  // Bir çağrıda ~$budget saniye çalışır
  static function run(int $budget = 40): array {
    if (!self::keys()) return ['ok' => false, 'error' => 'GEMINI_KEYS ayarlı değil', 'progress' => self::progress()];
    $lock = fopen(DATA_DIR . '/.yazi-ceviri.lock', 'c');
    if (!flock($lock, LOCK_EX | LOCK_NB)) return ['ok' => false, 'error' => 'başka bir çeviri çalışıyor', 'progress' => self::progress()];
    $end = time() + $budget; $log = []; $done = false; $err = null;
    try {
      while (time() < $end - 10) {
        $r = self::step($end - time()); $log[] = $r['did'];
        if ($r['done']) { $done = true; break; }
      }
    } catch (Throwable $e) { $err = $e->getMessage(); }
    finally { flock($lock, LOCK_UN); fclose($lock); }
    return ['ok' => $err === null, 'done' => $done, 'error' => $err, 'did' => $log, 'progress' => self::progress()];
  }
}
