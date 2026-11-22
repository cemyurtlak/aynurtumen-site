<?php
// Instagram'daki son gönderiler. IG_ACCESS_TOKEN tanımlıysa resmi API'den, değilse data/instagram-fallback.json'dan.
final class Instagram {
  const TTL = 3600;
  static function posts(int $limit = 6): array {
    $fallback = Store::read('instagram-fallback', ['posts' => []])['posts'];
    $token = env('IG_ACCESS_TOKEN');
    if ($token === '') return array_slice($fallback, 0, $limit);
    $c = Store::read('instagram-onbellek', null);
    if ($c && time() - $c['at'] < self::TTL) return array_slice($c['data'], 0, $limit);
    try {
      [$code, $body] = http_get('https://graph.instagram.com/me/media?fields=permalink,media_type,timestamp&limit=12&access_token=' . rawurlencode($token), 8);
      $j = json_decode($body, true);
      if ($code !== 200 || !is_array($j['data'] ?? null)) throw new RuntimeException($j['error']['message'] ?? 'Instagram yanıtı okunamadı');
      $posts = array_values(array_filter(array_column($j['data'], 'permalink')));
      $data = $posts ?: $fallback;
      Store::write('instagram-onbellek', ['at' => time(), 'data' => $data]);
    } catch (Throwable $e) {
      error_log('[instagram] ' . $e->getMessage());
      $data = $fallback;
      Store::write('instagram-onbellek', ['at' => time() - self::TTL + 600, 'data' => $data]);
    }
    return array_slice($data, 0, $limit);
  }
}
