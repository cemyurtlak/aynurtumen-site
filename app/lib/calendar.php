<?php
// Google Takvim bağlantısı
//
// 1) Etkinlik takvimi (GOOGLE_CALENDAR_ICS_URL): "Site Etkinlikleri" adlı ayrı bir Google takvimi.
//    Bu takvime eklenen her etkinlik sitede yayınlanır. Açıklamaya şu satırlar yazılabilir:
//      ücret: 750        (yazılmazsa ücretsiz)
//      kontenjan: 30     (yazılmazsa ayarlardaki varsayılan)
//      tür: kamp         (online / yüz yüze / kamp; yazılmazsa yerden ve süreden anlaşılır)
//      kayıt: yok        (sitede kayıt düğmesi çıkmaz, yalnızca duyurulur)
//      gizli             (sitede hiç gösterilmez)
//    Kalan satırlar etkinlik açıklaması olur.
// 2) Meşguliyet takvimi (GOOGLE_BUSY_ICS_URL): Aynur Hanım'ın kendi takvimi. Dolu saatler randevuda kapanır.
//
// Takvim her CALENDAR_REFRESH_MINUTES dakikada bir (varsayılan 15) okunur: ziyaretçi geldiğinde, sayfa gönderildikten
// sonra arka planda; ayrıca istenirse Hostinger'da cron ile (app/cron.php).
final class Calendar {
  const CACHE = 'takvim-onbellek';
  const AHEAD_DAYS = 400;

  static function eventsUrl(): string { return Config::get('GOOGLE_CALENDAR_ICS_URL'); }
  static function busyUrl(): string { return Config::get('GOOGLE_BUSY_ICS_URL'); }
  static function configured(): bool { return self::eventsUrl() !== ''; }
  private static function state(): array { return Store::read(self::CACHE, ['events' => [], 'busy' => [], 'at' => null, 'lastError' => null]); }

  private static function getText(string $url): string {
    if (!preg_match('/^(https?|webcal):\/\//', $url)) return (string)file_get_contents($url); // deneme için yerel dosya
    [$code, $body, $err] = http_get(preg_replace('/^webcal:/', 'https:', $url), 15, ['User-Agent: aynurtumen-site']);
    if ($code < 200 || $code >= 300) throw new RuntimeException('Takvim okunamadı (' . ($code ? "HTTP $code" : $err) . ')');
    return $body;
  }

  private static function clean(string $s): string {
    $s = preg_replace('/<br\s*\/?>/i', "\n", $s);
    $s = preg_replace('/<\/(p|div|li)>/i', "\n", $s);
    $s = preg_replace('/<[^>]+>/', '', $s);
    return strtr($s, ['&nbsp;' => ' ', '&amp;' => '&', '&lt;' => '<', '&gt;' => '>', '&quot;' => '"', '&#39;' => "'"]);
  }

  static function toSiteEvent(array $x): ?array {
    $settings = Store::read('settings', []);
    $meta = []; $text = [];
    foreach (explode("\n", self::clean($x['description'])) as $line) {
      $line = trim($line); $l = tr_lower($line);
      if (preg_match('/^gizli\b|^site\s*[:=]\s*(hayır|hayir|yok)/u', $l)) $meta['hidden'] = true;
      elseif (preg_match('/^(ücret|ucret|fiyat|bedel)\s*[:=]\s*(.+)$/u', $l, $m)) $meta['price'] = preg_match('/ücretsiz|ucretsiz|bedava/u', $m[2]) ? 0 : (int)preg_replace('/[^\d]/', '', preg_replace('/[,.](\d{1,2})\s*(tl|₺)?\s*$/iu', '', $m[2]));
      elseif (preg_match('/^(kontenjan|kişi|kisi|kapasite)\s*[:=]\s*(\d+)/u', $l, $m)) $meta['capacity'] = (int)$m[2];
      elseif (preg_match('/^(tür|tur|tip)\s*[:=]\s*(.+)$/u', $l, $m)) $meta['type'] = preg_match('/kamp/u', $m[2]) ? 'kamp' : (preg_match('/online|çevrim/u', $m[2]) ? 'online' : 'yuzyuze');
      elseif (preg_match('/^kayıt\s*[:=]\s*(.+)$|^kayit\s*[:=]\s*(.+)$/u', $l, $m)) $meta['noRegistration'] = (bool)preg_match('/yok|hayır|hayir|kapalı|kapali/u', ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? ''));
      elseif (preg_match('/^ücretsiz\b|^ucretsiz\b/u', $l)) $meta['price'] = 0;
      else $text[] = $line;
    }
    if (!empty($meta['hidden']) || $x['summary'] === '') return null;
    $start = $x['start'];
    $duration = max(15, (int)round(($x['end'] - $x['start']) / 60));
    if ($x['allDay']) { // tüm gün etkinlikleri: sabah 10:00'da başlar, gün sayısı kadar sürer
      $date = gmdate('Y-m-d', $x['start'] + 12 * 3600 + 3 * 3600);
      $start = strtotime($date . 'T10:00:00+03:00');
      $duration = max(1, (int)round(($x['end'] - $x['start']) / 86400)) * 1440;
    }
    $loc = $x['location'];
    $type = $meta['type'] ?? (($duration >= 20 * 60 || preg_match('/kamp|retreat/iu', $x['summary'])) ? 'kamp' : (($loc === '' || preg_match('/online|zoom|meet|canlı|canli|instagram|youtube/iu', $loc)) ? 'online' : 'yuzyuze'));
    return [
      'id' => 'tk-' . substr(sha1($x['uid'] . '|' . iso_utc($x['start'])), 0, 10),
      'source' => 'google',
      'title' => $x['summary'],
      'type' => $type,
      'start' => gmdate('Y-m-d\TH:i:s', $start + 3 * 3600) . '+03:00',
      'duration' => $duration,
      'location' => $loc !== '' ? $loc : ($type === 'online' ? 'Online, canlı' : ''),
      'capacity' => !empty($meta['capacity']) ? $meta['capacity'] : (int)($settings['eventDefaultCapacity'] ?? 30),
      'price' => $meta['price'] ?? 0,
      'registration' => empty($meta['noRegistration']),
      'description' => trim(preg_replace('/\s+/u', ' ', implode(' ', $text))),
    ];
  }

  static function refresh(): array {
    $state = self::state();
    if (!self::configured() && self::busyUrl() === '') {
      if ($state['events'] || $state['busy']) Store::write(self::CACHE, ['events' => [], 'busy' => [], 'at' => null, 'lastError' => null]);
      return self::state();
    }
    $from = time() - 2 * 86400; $to = time() + self::AHEAD_DAYS * 86400;
    try {
      $next = ['events' => $state['events'], 'busy' => [], 'at' => gmdate('Y-m-d\TH:i:s.v\Z'), 'lastError' => null];
      if (self::configured()) {
        $list = array_values(array_filter(array_map([self::class, 'toSiteEvent'], ICal::instances(self::getText(self::eventsUrl()), $from, $to))));
        usort($list, fn($a, $b) => strtotime($a['start']) <=> strtotime($b['start']));
        $next['events'] = $list;
        foreach ($list as $e) { $s = strtotime($e['start']) * 1000; $next['busy'][] = [$s, $s + $e['duration'] * 60000]; } // etkinlik saatlerinde bireysel randevu alınmasın
        $texts = []; foreach ($list as $e) array_push($texts, $e['title'], $e['description'], $e['location']);
        Translate::request($texts);
      }
      if (self::busyUrl() !== '') {
        foreach (ICal::instances(self::getText(self::busyUrl()), $from, $to) as $x) if (!$x['transparent']) $next['busy'][] = [$x['start'] * 1000, $x['end'] * 1000];
      }
      Store::write(self::CACHE, $next);
    } catch (Throwable $e) {
      error_log('[takvim] ' . $e->getMessage()); // önbellekteki son liste kullanılmaya devam eder
      $state['lastError'] = $e->getMessage(); $state['at'] = $state['at'] ?? null;
      $state['triedAt'] = time();
      Store::write(self::CACHE, $state);
    }
    return self::state();
  }

  // Süresi dolduysa yanıttan sonra takvimi yeniden okur
  static function tick(): void {
    if (!self::configured() && self::busyUrl() === '') return;
    $st = self::state();
    $every = 60 * (int)env('CALENDAR_REFRESH_MINUTES', '15');
    $last = max($st['at'] ? strtotime($st['at']) : 0, (int)($st['triedAt'] ?? 0));
    if (time() - $last < $every) return;
    later(function () {
      $lock = fopen(DATA_DIR . '/.takvim.lock', 'c');
      if (!flock($lock, LOCK_EX | LOCK_NB)) return;
      try { self::refresh(); } finally { flock($lock, LOCK_UN); fclose($lock); }
    });
  }

  static function events(): array { return self::configured() ? (self::state()['events'] ?? []) : []; }
  static function busy(): array { return self::state()['busy'] ?? []; } // milisaniye cinsinden [başlangıç, bitiş]
  static function status(): array {
    $s = self::state();
    return ['configured' => self::configured(), 'busyConfigured' => self::busyUrl() !== '', 'at' => $s['at'] ?? null, 'count' => count(self::events()), 'lastError' => $s['lastError'] ?? null];
  }

  // Randevuların Google Takvim'e abone olunacak gizli akış adresi için anahtar
  static function feedKey(): string {
    if (env('TAKVIM_ANAHTARI')) return env('TAKVIM_ANAHTARI');
    $f = DATA_DIR . '/takvim-anahtari.txt';
    if (is_file($f)) return trim((string)file_get_contents($f));
    $k = rand_hex(16);
    file_put_contents($f, $k);
    return $k;
  }
}
