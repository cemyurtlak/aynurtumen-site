<?php
// YouTube kanalındaki yeni videoları ve Shorts'ları otomatik çeker (API anahtarı gerekmez, RSS akışı).
// Sonuç 30 dakika data/youtube-onbellek.json dosyasında tutulur; YouTube'a ulaşılamazsa yedek liste gösterilir.
final class YouTube {
  const TTL = 1800;

  private static function feed(string $url): array {
    [$code, $xml] = http_get($url, 6);
    if ($code !== 200 || $xml === '') throw new RuntimeException("HTTP $code");
    $doc = @simplexml_load_string($xml);
    if (!$doc) throw new RuntimeException('XML okunamadı');
    $out = [];
    foreach ($doc->entry as $e) {
      $yt = $e->children('http://www.youtube.com/xml/schemas/2015');
      $media = $e->children('http://search.yahoo.com/mrss/');
      $views = 0;
      if (isset($media->group->community->statistics)) $views = (int)$media->group->community->statistics->attributes()['views'];
      $id = (string)$yt->videoId;
      if ($id === '') continue;
      $out[] = ['id' => $id, 'title' => trim(preg_replace('/\s*@AynurT(ü|u)men\s*/iu', ' ', (string)$e->title)), 'published' => (string)$e->published, 'views' => $views];
    }
    return $out;
  }

  private static function topic(string $t): string {
    $s = tr_lower($t);
    if (preg_match('/para|borç|kıtlık|bolluk|bereket|zengin|kazan/u', $s)) return 'para';
    if (str_contains($s, 'baba')) return 'baba';
    if (str_contains($s, 'anne')) return 'anne';
    if (preg_match('/ilişki|aşk|evlilik|partner|sevgi|vermek/u', $s)) return 'iliski';
    return 'aile';
  }

  static function get(): array {
    $c = Store::read('youtube-onbellek', null);
    if ($c && time() - $c['at'] < self::TTL) return $c['data'];
    $fallback = Store::read('youtube-fallback', ['videos' => [], 'shorts' => [], 'popular' => []]);
    $base = substr((string)(Store::read('settings', [])['youtubeChannelId'] ?? ''), 2);
    try {
      $videos = self::feed("https://www.youtube.com/feeds/videos.xml?playlist_id=UULF$base");
      $shorts = self::feed("https://www.youtube.com/feeds/videos.xml?playlist_id=UUSH$base");
      if (!$videos && !$shorts) throw new RuntimeException('boş akış');
      $data = ['live' => true, 'videos' => $videos ?: $fallback['videos'],
        'shorts' => $shorts ? array_map(fn($s) => $s + ['topic' => self::topic($s['title'])], $shorts) : $fallback['shorts'],
        'popular' => $fallback['popular']];
      Store::write('youtube-onbellek', ['at' => time(), 'data' => $data]);
    } catch (Throwable $e) {
      $data = ['live' => false] + $fallback;
      Store::write('youtube-onbellek', ['at' => time() - self::TTL + 300, 'data' => $data]); // 5 dk sonra tekrar dene
    }
    return $data;
  }
}
