<?php
// İstek yönlendirici: hangi adres hangi sayfayı açar
require __DIR__ . '/bootstrap.php';

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$rawPath = rawurldecode(parse_url($uri, PHP_URL_PATH) ?: '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'HEAD') $method = 'GET'; // HEAD istekleri (ör. arama motoru, bağlantı önizleme) GET gibi yanıtlanır; gövde gönderilmez
[$lang, $path] = I18n::splitPath($rawPath);
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$base = rtrim(env('SITE_URL') ?: (($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')), '/');
I18n::set($lang, $base);
$isAdmin = str_starts_with($path, '/admin');

function html(string $s, int $code = 200): void {
  global $isAdmin;
  http_response_code($code);
  header('Content-Type: text/html; charset=utf-8');
  echo $isAdmin ? $s : I18n::translateHtml($s);
}
function redirect(string $to, int $code = 302): void {
  http_response_code($code);
  header('Location: ' . I18n::prefix($to));
}
function json_out($v, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo js_json($v);
}
function body(): array {
  if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) return json_decode((string)file_get_contents('php://input'), true) ?: [];
  return $_POST;
}
function posts(): array { return Store::read('posts', ['posts' => []])['posts'] ?? []; }
// Yazılarda arama: başlık, kategori, özet ve yazının tamamında; Türkçe harfsiz yazılanı da bulur (baba = Baba, iliski = ilişki)
function fold_tr(string $s): string { return strtr(tr_lower($s), ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u', 'â' => 'a', 'î' => 'i', 'û' => 'u']); }
function search_posts(array $posts, string $q, string $lang = 'tr'): array {
  $words = array_values(array_filter(preg_split('/\s+/u', fold_tr(trim(mb_substr($q, 0, 80)))), fn($w) => $w !== ''));
  if (!$words) return array_column($posts, 'slug');
  $out = [];
  foreach ($posts as $p) {
    $hay = fold_tr(($p['title'] ?? '') . ' ' . ($p['category'] ?? '') . ' ' . ($p['excerpt'] ?? '') . ' ' . html_entity_decode(strip_tags($p['html'] ?? ''), ENT_QUOTES, 'UTF-8'));
    if ($lang !== 'tr' && ($t = YaziCeviri::get($p, $lang))) $hay .= ' ' . fold_tr($t['title'] . ' ' . $t['excerpt'] . ' ' . html_entity_decode(strip_tags($t['html']), ENT_QUOTES, 'UTF-8'));
    foreach ($words as $w) if (!str_contains($hay, $w)) continue 2;
    $out[] = $p['slug'];
  }
  return $out;
}

// Arka plan işleri: takvim ve çeviri süresi dolduysa yanıttan sonra yenilenir
Calendar::tick();
Translate::tick();
YaziCeviri::tick();

// Yönetim paneli oturumu
function admin_ok(): bool {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('at_admin');
    session_set_cookie_params(['lifetime' => 12 * 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => (bool)$GLOBALS['https']]);
    session_start();
  }
  return !empty($_SESSION['admin']) && $_SESSION['admin'] > time();
}
function need_admin(): bool { if (admin_ok()) return true; redirect('/admin'); return false; }

$m = [];
$route = $method . ' ' . $path;
switch (true) {
  /* ---------- Eski sitedeki adreslerden yönlendirme (Google sıralamasını korur) ---------- */
  case $path === '/index.php' && $method === 'GET':
    $r = (string)($_GET['route'] ?? '');
    if ($r === 'journal3/blog/post') {
      $p = null; foreach (posts() as $x) if ((string)($x['oldId'] ?? '') === (string)($_GET['journal_blog_post_id'] ?? '')) $p = $x;
      redirect($p ? '/yazilar/' . $p['slug'] : '/yazilar', 301); break;
    }
    $map = ['journal3/blog' => '/yazilar', 'information/contact' => '/iletisim', 'common/home' => '/'];
    if (isset($map[$r])) { redirect($map[$r], 301); break; }
    if ($r === 'information/information') { redirect(['10' => '/hakkinda', '11' => '/hizmetler'][(string)($_GET['information_id'] ?? '')] ?? '/', 301); break; }
    redirect('/', 301); break;

  /* ---------- Sayfalar ---------- */
  case $route === 'GET /': html(page_home(YouTube::get(), Instagram::posts(6), posts())); break;
  case $route === 'GET /aile-dizimi': html(page_aileDizimi()); break;
  case $route === 'GET /hizmetler': html(page_hizmetler()); break;
  case $route === 'GET /etkinlikler': html(page_etkinlikler()); break;
  case $route === 'GET /videolar': html(page_videolar(YouTube::get())); break;
  case $route === 'GET /kitap': html(page_kitap()); break;
  case $route === 'GET /yazilar': html(page_yazilar(posts())); break;
  case $method === 'GET' && (bool)preg_match('#^/yazilar/([^/]+)$#', $path, $m):
    $post = null; foreach (posts() as $p) if ($p['slug'] === $m[1]) $post = $p;
    $post ? html(page_yazi($post, posts())) : html(page_notFound(), 404); break;
  case $route === 'GET /hakkinda': html(page_hakkinda(YouTube::get())); break;
  case $route === 'GET /galeri': html(page_galeri(Instagram::posts(6))); break;
  case $route === 'GET /iletisim': html(page_iletisim()); break;
  case $route === 'POST /iletisim':
    $b = $_POST;
    if (!empty($b['website'])) { html(page_iletisim(true)); break; } // bot tuzağı
    if (empty($b['name']) || (mail_on() && empty($b['email'])) || empty($b['message']) || empty($b['kvkk'])) { html(page_iletisim(false, mail_on() ? 'Lütfen ad, e-posta ve mesaj alanlarını doldurup KVKK onayını işaretleyin.' : 'Lütfen ad ve mesaj alanlarını doldurup KVKK onayını işaretleyin.'), 400); break; }
    $msg = ['id' => rand_hex(4), 'at' => gmdate('Y-m-d\TH:i:s.v\Z'), 'name' => (string)$b['name'], 'email' => (string)$b['email'], 'phone' => (string)($b['phone'] ?? ''), 'topic' => (string)($b['topic'] ?? ''), 'message' => mb_substr((string)$b['message'], 0, 3000), 'read' => false];
    Store::update('messages', [], function ($l) use ($msg) { $l[] = $msg; return $l; });
    if (!mail_on()) { // e-posta kapalı: mesaj kaydedilir ve WhatsApp'ta hazır yazılmış olarak açılır
      $wa = implode("\n", array_merge(['Merhaba, web sitenizden yazıyorum.', '', 'Ad soyad: ' . $msg['name']], $msg['phone'] !== '' ? ['Telefon: ' . $msg['phone']] : [], ['Konu: ' . $msg['topic'], '', $msg['message']]));
      redirect('https://wa.me/' . settings()['phoneIntl'] . '?text=' . js_uri($wa), 303); break;
    }
    if (env('ADMIN_EMAIL')) later(fn() => Mailer::send(env('ADMIN_EMAIL'), 'Siteden yeni mesaj: ' . $msg['name'], '<p><b>' . esc($msg['name']) . '</b> · ' . esc($msg['email']) . ' · ' . esc($msg['phone']) . ' · ' . esc($msg['topic']) . '</p><p>' . nl2br(esc($msg['message'])) . '</p>'));
    html(page_iletisim(true)); break;
  case $route === 'GET /yasal': html(page_yasal()); break;
  case $route === 'GET /bilgi': header('X-Robots-Tag: noindex'); html(page_bilgi()); break;

  /* ---------- Randevu ---------- */
  case $route === 'GET /randevu': html(page_randevu($_GET)); break;
  case $route === 'GET /api/yazi-ara': json_out(['slugs' => search_posts(posts(), (string)($_GET['q'] ?? ''), (string)($_GET['lang'] ?? 'tr'))]); break;
  // Yazı çevirisi (Gemini): GitHub'daki zamanlanmış iş çağırır; güncelleme anahtarıyla korunur
  case $route === 'GET /api/yazi-cevir':
    $oz = is_file(APP_DIR . '/guncelle_anahtar.php') ? (string)(include APP_DIR . '/guncelle_anahtar.php') : '';
    $k = (string)($_GET['k'] ?? '');
    if ($oz === '' || $k === '' || !hash_equals($oz, hash('sha256', $k))) { html(page_notFound(), 404); break; }
    @set_time_limit(90); ignore_user_abort(true); header('X-Robots-Tag: noindex');
    json_out(YaziCeviri::run(max(15, min(45, (int)($_GET['sure'] ?? 40))))); break;
  case $route === 'GET /api/slots': json_out(['slots' => Booking::slotsFor((string)($_GET['date'] ?? ''), (string)($_GET['service'] ?? ''))]); break;
  case $route === 'GET /api/days':
    // Önümüzdeki günlerden hangilerinde boş saat olduğunu döndürür
    $service = (string)($_GET['service'] ?? '');
    $days = (int)(Store::read('settings', [])['bookingDaysAhead'] ?? 60);
    $out = [];
    for ($i = 0; $i <= $days; $i++) {
      $d = gmdate('Y-m-d', time() + 3 * 3600 + $i * 86400);
      $out[$d] = false;
      foreach (Booking::slotsFor($d, $service) as $s) if ($s['free']) { $out[$d] = true; break; }
    }
    json_out(['days' => (object)$out]); break;
  case $route === 'POST /api/bookings':
    $r = Booking::create(body());
    if (isset($r['errors'])) { json_out(['errors' => $r['errors']], 400); break; }
    $b = $r['booking'];
    later(fn() => Booking::requested($b));
    json_out(['wa' => Booking::waUrl($b), 'redirect' => "/randevu/talep/{$b['id']}?k={$b['key']}"]); break;
  case $method === 'GET' && (bool)preg_match('#^/randevu/talep/(\w+)$#', $path, $m):
    $b = Booking::find($m[1], (string)($_GET['k'] ?? ''));
    if (!$b) { html(page_notFound(), 404); break; }
    if ($b['status'] === 'confirmed') { redirect("/randevu/onay/{$b['id']}?k={$b['key']}"); break; }
    html(page_talep($b)); break;
  case $method === 'GET' && (bool)preg_match('#^/randevu/onay/(\w+)$#', $path, $m):
    $b = Booking::find($m[1], (string)($_GET['k'] ?? ''));
    (!$b || $b['status'] !== 'confirmed') ? html(page_notFound(), 404) : html(page_onay($b)); break;
  case $method === 'GET' && (bool)preg_match('#^/randevu/(\w+)/takvim\.ics$#', $path, $m):
    $b = Booking::find($m[1], (string)($_GET['k'] ?? ''));
    if (!$b) { http_response_code(404); echo 'Not Found'; break; }
    $f = fn($t) => gmdate('Ymd\THis\Z', $t);
    $st = strtotime($b['start']); $end = $st + $b['duration'] * 60;
    $room = !empty($b['meeting']) ? $base . I18n::prefix("/gorusme/{$b['id']}?k={$b['key']}") : '';
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="aynur-tumen-randevu.ics"');
    echo implode("\r\n", array_filter(['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Aynur Tumen//TR', 'BEGIN:VEVENT',
      "UID:{$b['id']}@aynurtumen", 'DTSTAMP:' . $f(time()), 'DTSTART:' . $f($st), 'DTEND:' . $f($end),
      'SUMMARY:' . t($b['itemName']) . ' · Aynur Tümen', 'DESCRIPTION:' . ($room ? str_replace('{url}', $room, (string)t('Görüşme odası: {url}')) : t('Adres WhatsApp ile iletilecek.')),
      $room ? "URL:$room" : '', 'BEGIN:VALARM', 'TRIGGER:-PT1H', 'ACTION:DISPLAY', 'DESCRIPTION:' . t('Görüşmeniz 1 saat sonra'), 'END:VALARM', 'END:VEVENT', 'END:VCALENDAR']));
    break;
  case $method === 'GET' && (bool)preg_match('#^/gorusme/(\w+)$#', $path, $m):
    $b = Booking::find($m[1], (string)($_GET['k'] ?? ''));
    if (!$b || $b['status'] !== 'confirmed' || empty($b['meeting'])) { html(page_message('Görüşme bulunamadı', 'Bağlantı hatalı ya da randevu iptal edilmiş olabilir. Onay e-postanızdaki bağlantıyı kullanın.'), 404); break; }
    html(page_gorusme($b)); break;

  /* ---------- Yönetim paneli ---------- */
  case $route === 'GET /admin': admin_ok() ? redirect('/admin/randevular') : html(admin_login()); break;
  case $route === 'POST /admin/giris':
    $pw = env('ADMIN_PASSWORD');
    if (mb_strlen($pw) < 10 || $pw === 'aynur2026') { html(admin_login('Güvenlik için yönetim paneli kilitli: app/ayarlar.env dosyasında en az 10 karakterlik yeni bir ADMIN_PASSWORD belirleyin.'), 403); break; }
    admin_ok();
    $fails = $_SESSION['fails'] ?? [0, 0];
    if ($fails[0] >= 5 && time() - $fails[1] < 600) { html(admin_login('Çok fazla hatalı deneme. 10 dakika sonra tekrar deneyin.'), 429); break; }
    if (!hash_equals(hash('sha256', $pw), hash('sha256', (string)($_POST['password'] ?? '')))) {
      $_SESSION['fails'] = [$fails[0] + 1, time()];
      html(admin_login('Şifre hatalı.'), 401); break;
    }
    session_regenerate_id(true);
    $_SESSION['admin'] = time() + 12 * 3600; unset($_SESSION['fails']);
    redirect('/admin/randevular', 303); break;
  case $route === 'GET /admin/cikis': admin_ok(); $_SESSION = []; session_destroy(); redirect('/admin'); break;
  case $route === 'GET /admin/randevular': if (need_admin()) html(admin_bookings()); break;
  case $method === 'POST' && (bool)preg_match('#^/admin/randevular/(\w+)/onayla$#', $path, $m):
    if (!need_admin()) break;
    try { Booking::confirm($m[1], ['provider' => 'manuel', 'at' => gmdate('Y-m-d\TH:i:s.v\Z')]); } catch (Throwable $e) { error_log('[onay] ' . $e->getMessage()); }
    redirect('/admin/randevular', 303); break;
  case $method === 'POST' && (bool)preg_match('#^/admin/randevular/(\w+)/iptal$#', $path, $m):
    if (!need_admin()) break;
    $id = $m[1];
    Store::update('bookings', [], fn($l) => array_map(fn($b) => $b['id'] === $id ? array_merge($b, ['status' => 'cancelled', 'cancelledAt' => gmdate('Y-m-d\TH:i:s.v\Z')]) : $b, $l));
    redirect('/admin/randevular', 303); break;
  case $route === 'GET /admin/etkinlikler': if (need_admin()) html(admin_events($base)); break;
  case $route === 'POST /admin/takvim/yenile': if (!need_admin()) break; Calendar::refresh(); redirect('/admin/etkinlikler', 303); break;
  case $route === 'POST /admin/baglantilar':
    if (!need_admin()) break;
    $b = $_POST; $bad = false;
    foreach (['GOOGLE_CALENDAR_ICS_URL', 'GOOGLE_BUSY_ICS_URL'] as $k) if (!empty($b[$k]) && !preg_match('/^(https?|webcal):\/\/\S+$/', trim($b[$k]))) $bad = true;
    if (!$bad) Config::save($b);
    Calendar::refresh();
    later([Translate::class, 'syncNow']);
    redirect('/admin/etkinlikler', 303); break;
  case $route === 'POST /admin/etkinlikler':
    if (!need_admin()) break;
    $b = $_POST;
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtr(tr_lower((string)($b['title'] ?: 'etkinlik')), ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u'])), '-');
    $ev = ['id' => $slug . '-' . rand_hex(2), 'title' => (string)$b['title'], 'type' => (string)$b['type'], 'start' => "{$b['date']}T{$b['time']}:00+03:00", 'duration' => (int)$b['duration'] ?: 90,
      'location' => (string)($b['location'] ?: ($b['type'] === 'online' ? 'Online, canlı' : '')), 'capacity' => (int)$b['capacity'] ?: 30, 'price' => (int)$b['price'], 'description' => (string)($b['description'] ?? '')];
    Store::update('events', [], function ($l) use ($ev) { $l[] = $ev; return $l; });
    later([Translate::class, 'syncNow']);
    redirect('/admin/etkinlikler', 303); break;
  case $method === 'POST' && (bool)preg_match('#^/admin/etkinlikler/([\w-]+)/sil$#', $path, $m):
    if (!need_admin()) break;
    $id = $m[1];
    Store::update('events', [], fn($l) => array_values(array_filter($l, fn($e) => $e['id'] !== $id)));
    redirect('/admin/etkinlikler', 303); break;
  case $route === 'GET /admin/hizmetler': if (need_admin()) html(admin_services()); break;
  case $route === 'POST /admin/hizmetler':
    if (!need_admin()) break;
    $p = $_POST;
    Store::update('services', [], fn($l) => array_map(function ($x) use ($p) {
      if (isset($p['price_' . $x['id']]) && $p['price_' . $x['id']] !== '') $x['price'] = (int)$p['price_' . $x['id']];
      if (!empty($p['duration_' . $x['id']])) $x['duration'] = (int)$p['duration_' . $x['id']];
      if (!empty($p['short_' . $x['id']])) $x['short'] = (string)$p['short_' . $x['id']];
      return $x;
    }, $l));
    later([Translate::class, 'syncNow']);
    redirect('/admin/hizmetler', 303); break;
  case $route === 'GET /admin/mesajlar':
    if (!need_admin()) break;
    $out = admin_messages();
    Store::update('messages', [], fn($l) => array_map(fn($x) => array_merge($x, ['read' => true]), $l));
    html($out); break;

  /* ---------- Randevuların Google Takvim'e akışı (gizli adres) ---------- */
  case $route === 'GET /takvim/randevular.ics':
    if (($_GET['anahtar'] ?? '') !== Calendar::feedKey()) { http_response_code(404); echo 'Not Found'; break; }
    $f = fn($t) => gmdate('Ymd\THis\Z', $t);
    $txt = fn($s) => preg_replace('/([,;])/', '\\\\$1', str_replace(["\r\n", "\n"], '\\n', str_replace('\\', '\\\\', (string)$s)));
    $since = time() - 60 * 86400;
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Aynur Tumen//Randevular//TR', 'X-WR-CALNAME:Site randevuları', 'X-WR-TIMEZONE:Europe/Istanbul', 'REFRESH-INTERVAL;VALUE=DURATION:PT1H'];
    foreach (Store::read('bookings', []) as $b) {
      if ($b['status'] !== 'confirmed' || strtotime($b['start']) <= $since) continue;
      $st = strtotime($b['start']);
      $room = !empty($b['meeting']) ? "$base/gorusme/{$b['id']}?k={$b['key']}" : '';
      $desc = array_filter([$b['mode'] === 'online' ? 'Online' : 'Yüz yüze', $b['phone'], $b['email'], !empty($b['lang']) && $b['lang'] !== 'tr' ? 'Dil: ' . I18n::NAMES[$b['lang']] : '', $b['note'] ? 'Not: ' . $b['note'] : '', $room ? 'Görüşme odası: ' . $room : '']);
      array_push($lines, 'BEGIN:VEVENT', "UID:{$b['id']}@aynurtumen-randevu", 'DTSTAMP:' . $f(strtotime($b['confirmedAt'] ?? $b['createdAt'])), 'DTSTART:' . $f($st), 'DTEND:' . $f($st + $b['duration'] * 60),
        'SUMMARY:' . $txt("{$b['name']} · {$b['itemName']}"), 'DESCRIPTION:' . $txt(implode("\n", $desc)), $room ? "URL:$room" : '', 'END:VEVENT');
    }
    $lines[] = 'END:VCALENDAR';
    header('Content-Type: text/calendar; charset=utf-8');
    echo implode("\r\n", array_filter($lines)); break;

  /* ---------- SEO dosyaları ---------- */
  case $route === 'GET /robots.txt':
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\nDisallow: /admin\nDisallow: /randevu/\nDisallow: /gorusme/\nDisallow: /odeme/\nDisallow: /takvim/\nSitemap: $base/sitemap.xml\n"; break;
  case $route === 'GET /sitemap.xml':
    $urls = ['/', '/aile-dizimi', '/hizmetler', '/etkinlikler', '/videolar', '/kitap', '/yazilar', '/hakkinda', '/galeri', '/iletisim'];
    $alt = fn($u) => join_map(I18n::LANGS, fn($l) => '<xhtml:link rel="alternate" hreflang="' . $l . '" href="' . $base . I18n::prefix($u, $l) . '"/>');
    $x = '';
    foreach ($urls as $u) foreach (I18n::LANGS as $l) $x .= '<url><loc>' . $base . I18n::prefix($u, $l) . '</loc>' . $alt($u) . '</url>';
    // Yazılar: Türkçe + çevirisi tamamlanmış diller
    foreach (posts() as $p) {
      $u = '/yazilar/' . $p['slug']; $ls = YaziCeviri::langsFor($p);
      $a = count($ls) > 1 ? join_map($ls, fn($l) => '<xhtml:link rel="alternate" hreflang="' . $l . '" href="' . $base . esc(I18n::prefix($u, $l)) . '"/>') : '';
      foreach ($ls as $l) $x .= '<url><loc>' . $base . esc(I18n::prefix($u, $l)) . '</loc>' . $a . '</url>';
    }
    header('Content-Type: application/xml; charset=utf-8');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" xmlns:xhtml=\"http://www.w3.org/1999/xhtml\">$x</urlset>"; break;

  default: html(page_notFound(), 404);
}
