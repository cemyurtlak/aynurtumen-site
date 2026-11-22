<?php
// Yönetim paneli sayfaları
function admin_when($iso): string { return $iso ? intl_fmt(strtotime((string)$iso), 'tr-TR', 'dMMMMHHmm') : '—'; }

function admin_shell(string $body, ?string $tab = null): string {
  $tabs = [['randevular', 'Randevular'], ['etkinlikler', 'Etkinlikler'], ['hizmetler', 'Hizmetler ve ücretler'], ['mesajlar', 'Mesajlar']];
  $nav = $tab ? join_map($tabs, fn($x) => '<a href="/admin/' . $x[0] . '"' . ($x[0] === $tab ? ' aria-current="page"' : '') . '>' . $x[1] . '</a>') : '';
  return '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Yönetim paneli · Aynur Tümen</title><link rel="stylesheet" href="/css/site.css?v=' . asset_version() . '"></head>
  <body class="admin"><header class="admin-top"><div class="wrap admin-top-in"><b>Aynur Tümen · Yönetim</b><nav>' . $nav . '<a href="/" target="_blank">Siteyi aç</a>' . ($tab ? '<a href="/admin/cikis">Çıkış</a>' : '') . '</nav></div></header>
  <main class="wrap admin-main">' . $body . '</main></body></html>';
}

function admin_login(?string $error = null): string {
  return admin_shell('<form class="form admin-login" method="post" action="/admin/giris"><h1>Yönetim paneli</h1>' . ($error ? '<p class="form-error">' . esc($error) . '</p>' : '') . '<label>Şifre<input type="password" name="password" required autofocus></label><button class="btn btn-violet">Giriş yap</button></form>');
}

const STATUS_LABEL = ['confirmed' => 'Onaylandı', 'pending' => 'Ödeme bekleniyor', 'request' => 'Talep', 'cancelled' => 'İptal edildi', 'confirmed_pending_meeting' => 'Onaylanıyor'];

function admin_bookings(): string {
  $list = Store::read('bookings', []);
  usort($list, fn($a, $b) => strtotime($a['start']) <=> strtotime($b['start']));
  $cut = time() - 3 * 3600;
  $upcoming = array_values(array_filter($list, fn($b) => strtotime($b['start']) >= $cut));
  $past = array_reverse(array_values(array_filter($list, fn($b) => strtotime($b['start']) < $cut)));
  $base = rtrim(env('SITE_URL'), '/');
  $row = function ($b) use ($base) {
    $d = I18n::dateParts($b['start'], 'tr');
    $link = !empty($b['meeting']) ? "$base/gorusme/{$b['id']}?k={$b['key']}" : '';
    $msg = 'Merhaba ' . explode(' ', $b['name'])[0] . ", {$b['itemName']} randevunuz {$d['day']} {$d['month']} {$d['hm']} için onaylandı." . ($link ? " Görüşme odası: $link" : '');
    $phone = preg_replace('/^0/', '90', preg_replace('/\D/', '', $b['phone']));
    $lang = !empty($b['lang']) && $b['lang'] !== 'tr' ? ' <span class="status" title="Kayıt dili">' . ['en' => 'İngilizce', 'ru' => 'Rusça', 'de' => 'Almanca'][$b['lang']] . '</span>' : '';
    $wa = 'https://wa.me/' . $phone . '?text=' . js_uri($msg);
    return '<tr>
      <td><b>' . $d['day'] . ' ' . $d['month'] . '</b><br>' . $d['dow'] . ' ' . $d['hm'] . '</td>
      <td>' . esc($b['itemName']) . '<br><small>' . ($b['mode'] === 'online' ? 'Online' : 'Yüz yüze') . ' · ' . I18n::duration((int)$b['duration'], 'tr') . '</small></td>
      <td>' . esc($b['name']) . $lang . '<br><small>' . esc($b['phone']) . ' · ' . esc($b['email']) . '</small>' . ($b['note'] ? '<br><small class="note-cell">“' . esc($b['note']) . '”</small>' : '') . '</td>
      <td><span class="status status-' . esc($b['status']) . '">' . (STATUS_LABEL[$b['status']] ?? esc($b['status'])) . '</span></td>
      <td><div class="actions">
        ' . (!empty($b['meeting']) ? '<a class="btn btn-violet btn-xs" href="/gorusme/' . $b['id'] . '?k=' . $b['key'] . '" target="_blank">Odaya gir</a>' : '') . '
        <a class="btn btn-wa btn-xs" href="' . esc($wa) . '" target="_blank">WhatsApp</a>
        ' . ($b['status'] === 'request' || $b['status'] === 'pending' ? '<form method="post" action="/admin/randevular/' . $b['id'] . '/onayla" onsubmit="return confirm(\'Görüşme kesinleşti mi? Onaylanınca danışana onay e-postası gider.\')"><button class="btn btn-violet btn-xs">Onayla</button></form>' : '') . '
        ' . ($b['status'] !== 'cancelled' ? '<form method="post" action="/admin/randevular/' . $b['id'] . '/iptal" onsubmit="return confirm(\'Bu randevu iptal edilsin mi?\')"><button class="btn btn-line btn-xs">İptal et</button></form>' : '') . '
      </div></td></tr>';
  };
  $table = fn($rows) => $rows ? '<div class="table-wrap"><table class="admin-table"><thead><tr><th>Zaman</th><th>Çalışma</th><th>Danışan</th><th>Durum</th><th></th></tr></thead><tbody>' . join_map($rows, $row) . '</tbody></table></div>' : '<p class="muted">Kayıt yok.</p>';
  $confirmed = count(array_filter($upcoming, fn($b) => $b['status'] === 'confirmed'));
  $requests = count(array_filter($upcoming, fn($b) => $b['status'] === 'request'));
  $unread = count(array_filter(Store::read('messages', []), fn($m) => empty($m['read'])));
  return admin_shell("<div class=\"admin-stats\"><div><b>$requests</b><span>bekleyen talep</span></div><div><b>$confirmed</b><span>yaklaşan onaylı randevu</span></div><div><b>$unread</b><span>okunmamış mesaj</span></div></div>
  <h1>Yaklaşan randevular</h1>" . $table($upcoming) . '<h2>Geçmiş</h2>' . $table($past), 'randevular');
}

function admin_events(string $base = ''): string {
  $cs = Calendar::status(); $ts = Translate::status();
  $now = time();
  $gcal = array_values(array_filter(Calendar::events(), fn($e) => strtotime($e['start']) > $now));
  $manual = array_values(array_filter(Store::read('events', []), fn($e) => !(!empty($e['ornek']) && $cs['configured'])));
  usort($manual, fn($a, $b) => strtotime($a['start']) <=> strtotime($b['start']));
  $row = function ($e, $google) {
    $d = I18n::dateParts($e['start'], 'tr');
    $reg = ($e['registration'] ?? true) !== false;
    return '<tr><td><b>' . $d['day'] . ' ' . $d['month'] . ' ' . $d['year'] . '</b><br>' . $d['hm'] . '</td><td>' . esc($e['title']) . (!empty($e['ornek']) ? ' <span class="status">örnek</span>' : '') . (!$reg ? ' <span class="status">yalnızca duyuru</span>' : '') . '<br><small>' . esc($e['location']) . ' · ' . I18n::duration((int)$e['duration'], 'tr') . '</small></td><td>' . (['online' => 'Online', 'yuzyuze' => 'Yüz yüze', 'kamp' => 'Kamp'][$e['type']] ?? esc($e['type'])) . '</td><td>' . (!empty($e['price']) ? tl($e['price']) : 'Ücretsiz') . '</td><td>' . ($reg ? $e['capacity'] : '—') . '</td><td>'
      . ($google ? '<small class="muted">Google Takvim\'den düzenlenir</small>' : '<form method="post" action="/admin/etkinlikler/' . esc($e['id']) . '/sil" onsubmit="return confirm(\'Etkinlik silinsin mi?\')"><button class="btn btn-line btn-xs">Sil</button></form>') . '</td></tr>';
  };
  $head = '<thead><tr><th>Tarih</th><th>Etkinlik</th><th>Tür</th><th>Ücret</th><th>Kontenjan</th><th></th></tr></thead>';
  $feed = $base . '/takvim/randevular.ics?anahtar=' . Calendar::feedKey();
  $every = (int)env('CALENDAR_REFRESH_MINUTES', '15');
  $status = $cs['configured']
    ? '<p><span class="status status-confirmed">Bağlı</span> Son güncelleme: ' . admin_when($cs['at']) . ' · ' . count($gcal) . " yaklaşan etkinlik. Takvim her $every dakikada bir okunur.</p>"
      . ($cs['lastError'] ? '<p class="form-error">Son okumada sorun: ' . esc($cs['lastError']) . '. Önceki liste gösterilmeye devam ediyor.</p>' : '')
      . '<form method="post" action="/admin/takvim/yenile"><button class="btn btn-line btn-xs">Şimdi yenile</button></form>'
    : '<p><span class="status">Bağlı değil</span> Aşağıdaki "Bağlantı ayarları" bölümüne "Site Etkinlikleri" takviminin gizli iCal adresi yazıldığında, o takvimdeki her etkinlik burada ve sitede kendiliğinden görünür. Kurulum: BENI-OKU.md, bölüm 9.</p>';
  $deepl = $ts['enabled'] ? 'açık' . ($ts['pending'] ? ' · ' . $ts['pending'] . ' metin sırada' : '') . ($ts['lastError'] ? ' · <span class="form-error">' . esc($ts['lastError']) . '</span>' : '') : 'kapalı (DeepL anahtarı yok); yeni metinler diğer dillerde Türkçe görünür';
  $fields = join_map([['GOOGLE_CALENDAR_ICS_URL', 'Site Etkinlikleri takviminin gizli iCal adresi', 'https://calendar.google.com/calendar/ical/.../basic.ics'], ['GOOGLE_BUSY_ICS_URL', "Aynur Hanım'ın kendi takviminin gizli iCal adresi (isteğe bağlı)", 'https://calendar.google.com/calendar/ical/.../basic.ics'], ['DEEPL_API_KEY', 'DeepL anahtarı', 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx:fx']],
    fn($x) => Config::fromEnv($x[0]) ? '<p class="muted">' . $x[1] . ': .env dosyasından ayarlı.</p>' : '<label>' . $x[1] . '<input name="' . $x[0] . '" type="' . ($x[0] === 'DEEPL_API_KEY' ? 'password' : 'url') . '" placeholder="' . $x[2] . '" value="' . esc(Config::get($x[0])) . '" autocomplete="off"></label>');
  $gTable = $cs['configured'] ? '<h2>Google Takvim\'den gelenler</h2><div class="table-wrap"><table class="admin-table">' . $head . '<tbody>' . (join_map($gcal, fn($e) => $row($e, true)) ?: '<tr><td colspan="6">Takvimde yaklaşan etkinlik yok.</td></tr>') . '</tbody></table></div><h2>Elle eklenenler</h2>' : '';
  return admin_shell('<h1>Etkinlikler</h1>
  <section class="admin-box">
    <h2>Google Takvim</h2>
    ' . $status . '
    <p class="muted">Kendi takvim meşguliyeti: ' . ($cs['busyConfigured'] ? 'bağlı, dolu saatler randevuya kapalı' : 'bağlı değil') . '.</p>
    <p class="muted">Otomatik çeviri (DeepL): ' . $deepl . '.</p>
    <details' . ($cs['configured'] && $ts['enabled'] ? '' : ' open') . '><summary>Bağlantı ayarları</summary>
      <form class="form" method="post" action="/admin/baglantilar">
        ' . $fields . '
        <button class="btn btn-violet btn-sm">Kaydet ve takvimi oku</button>
      </form>
    </details>
    <details><summary>Randevuları Google Takvim\'de görmek için</summary>
      <p>Google Takvim → sol taraftaki "Diğer takvimler" yanındaki + → "URL ile" → aşağıdaki adresi yapıştırın. Onaylanan randevular takvimde görünür (Google bu tür takvimleri birkaç saatte bir yeniler). Adresi kimseyle paylaşmayın.</p>
      <input readonly value="' . esc($feed) . '" onclick="this.select()">
    </details>
  </section>
  ' . $gTable . '
  <div class="table-wrap"><table class="admin-table">' . $head . '<tbody>' . (join_map($manual, fn($e) => $row($e, false)) ?: '<tr><td colspan="6">Etkinlik yok.</td></tr>') . '</tbody></table></div>
  <h2>Yeni etkinlik ekle</h2>
  <form class="form admin-form" method="post" action="/admin/etkinlikler">
    <label>Başlık<input name="title" required placeholder="Örn. Dolunay çalışması ve niyet"></label>
    <div class="row"><label>Tarih<input type="date" name="date" required></label><label>Saat<input type="time" name="time" required></label></div>
    <div class="row"><label>Tür<select name="type"><option value="online">Online</option><option value="yuzyuze">Yüz yüze</option><option value="kamp">Kamp</option></select></label><label>Süre (dakika)<input type="number" name="duration" value="90" min="15"></label></div>
    <div class="row"><label>Ücret (₺, ücretsizse 0; sitede gösterilmez)<input type="number" name="price" value="0" min="0"></label><label>Kontenjan<input type="number" name="capacity" value="30" min="1"></label></div>
    <label>Yer<input name="location" placeholder="Online, canlı · ya da Sarıyer, İstanbul"></label>
    <label>Açıklama<textarea name="description" rows="3"></textarea></label>
    <button class="btn btn-violet">Etkinliği yayınla</button>
  </form>', 'etkinlikler');
}

function admin_services(): string {
  $list = Store::read('services', []);
  return admin_shell('<h1>Hizmetler</h1><p class="muted">Süre ve açıklamalar sitede ve randevu ekranında hemen güncellenir. Ücretler sitede gösterilmez; yalnızca kayıt için tutulur.</p>
  <form class="form admin-form" method="post" action="/admin/hizmetler">
    ' . join_map($list, fn($x) => '<fieldset class="svc-edit"><legend>' . esc($x['name']) . '</legend>
      <div class="row"><label>Ücret (₺)<input type="number" min="0" name="price_' . $x['id'] . '" value="' . ($x['price'] ?? '') . '" ' . (!empty($x['bookable']) ? '' : 'disabled') . '></label><label>Süre (dakika)<input type="number" min="0" name="duration_' . $x['id'] . '" value="' . ($x['duration'] ?? '') . '" ' . (!empty($x['bookable']) ? '' : 'disabled') . '></label></div>
      <label>Kısa açıklama<textarea name="short_' . $x['id'] . '" rows="2">' . esc($x['short']) . '</textarea></label></fieldset>') . '
    <button class="btn btn-violet">Kaydet</button>
  </form>', 'hizmetler');
}

function admin_messages(): string {
  $list = array_reverse(Store::read('messages', []));
  return admin_shell('<h1>Mesajlar</h1>' . ($list ? join_map($list, fn($m) => '<article class="msg ' . (!empty($m['read']) ? '' : 'msg-new') . '"><header><b>' . esc($m['name']) . '</b> · ' . esc($m['topic']) . ' · <small>' . intl_fmt(strtotime($m['at']), 'tr-TR', 'dMyHms') . '</small></header><p>' . esc($m['message']) . '</p><small>' . esc($m['email']) . ' ' . ($m['phone'] ? '· ' . esc($m['phone']) : '') . '</small></article>') : '<p class="muted">Mesaj yok.</p>'), 'mesajlar');
}
