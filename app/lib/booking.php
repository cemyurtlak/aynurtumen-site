<?php
// Randevu mantığı: uygun saatler, kayıt oluşturma, WhatsApp mesajı, onay.
// Sitede satın alma yok: kayıt "talep" olarak tutulur, bilgiler hazır bir WhatsApp mesajı olarak açılır.
final class Booking {
  const TZ = '+03:00'; // Türkiye yaz/kış saati uygulamıyor
  const HOLD_MINUTES = 30;
  const REQUEST_HOURS = 72; // talep, Aynur Hanım dönene kadar saati bu süre tutar

  private static function nowMs(): int { return (int)floor(microtime(true) * 1000); }
  private static function ms($iso): int { return strtotime((string)$iso) * 1000; }
  private static function nowIso(): string { $t = microtime(true); return gmdate('Y-m-d\TH:i:s', (int)$t) . sprintf('.%03dZ', (int)(($t - floor($t)) * 1000)); }

  static function active(): array {
    $now = self::nowMs();
    return array_values(array_filter(Store::read('bookings', []), fn($b) =>
      $b['status'] === 'confirmed'
      || ($b['status'] === 'pending' && $now - self::ms($b['createdAt']) < self::HOLD_MINUTES * 60000)
      || ($b['status'] === 'request' && $now - self::ms($b['createdAt']) < self::REQUEST_HOURS * 3600000)));
  }

  private static function toMin(string $hm): int { [$h, $m] = array_map('intval', explode(':', $hm)); return $h * 60 + $m; }

  static function slotsFor(string $date, string $serviceId): array {
    $settings = Store::read('settings', []);
    $service = null;
    foreach (Store::read('services', []) as $s) if ($s['id'] === $serviceId && !empty($s['bookable'])) $service = $s;
    if (!$service || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return [];
    $dow = (int)gmdate('w', strtotime("{$date}T12:00:00" . self::TZ));
    $hours = $settings['hours'][(string)$dow] ?? null;
    if (!$hours) return [];
    [$open, $close] = [self::toMin($hours[0]), self::toMin($hours[1])];
    $buffer = (int)($settings['bufferMinutes'] ?? 0) * 60000;
    $dayStart = strtotime("{$date}T00:00:00" . self::TZ) * 1000; $dayEnd = $dayStart + 86400000;
    $busy = [];
    foreach (self::active() as $b) if (!empty($b['start']) && str_starts_with($b['start'], $date)) { $s = self::ms($b['start']); $busy[] = [$s - $buffer, $s + $b['duration'] * 60000 + $buffer]; }
    foreach (Calendar::busy() as [$s, $e]) if ($s < $dayEnd && $e > $dayStart) $busy[] = [$s - $buffer, $e + $buffer]; // Google Takvim'deki dolu saatler
    $minStart = self::nowMs() + 12 * 3600 * 1000; // en az 12 saat önceden
    $out = [];
    for ($m = $open; $m + $service['duration'] <= $close; $m += 30) {
      $hm = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
      $iso = "{$date}T{$hm}:00" . self::TZ;
      $s = self::ms($iso); $e = $s + $service['duration'] * 60000;
      $free = $s >= $minStart;
      if ($free) foreach ($busy as [$bs, $be]) if ($s < $be && $e > $bs) { $free = false; break; }
      $out[] = ['time' => $hm, 'start' => $iso, 'free' => $free];
    }
    return $out;
  }

  static function seatsLeft(array $ev): int {
    $taken = count(array_filter(self::active(), fn($b) => ($b['eventId'] ?? null) === $ev['id']));
    return max(0, (int)$ev['capacity'] - $taken);
  }

  static function create(array $in): array {
    $errors = [];
    $name = trim((string)($in['name'] ?? ''));
    $phone = preg_replace('/[^\d+]/', '', (string)($in['phone'] ?? ''));
    $email = trim((string)($in['email'] ?? ''));
    if (mb_strlen($name) < 3) $errors['name'] = 'Ad ve soyadınızı yazın.';
    if (strlen(preg_replace('/\D/', '', $phone)) < 10) $errors['phone'] = 'Telefon numaranızı 05xx xxx xx xx biçiminde yazın.';
    if (!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/u', $email)) $errors['email'] = 'Geçerli bir e-posta adresi yazın.';
    if (empty($in['kvkk'])) $errors['kvkk'] = 'Devam etmek için KVKK aydınlatma metnini onaylayın.';
    $item = null; $start = $duration = $mode = $price = null; $eventId = null;
    if (!empty($in['eventId'])) {
      $ev = Events::find((string)$in['eventId']);
      if (!$ev) $errors['item'] = 'Etkinlik bulunamadı.';
      elseif (strtotime($ev['start']) < time()) $errors['item'] = 'Bu etkinliğin tarihi geçti.';
      elseif (($ev['registration'] ?? true) === false) $errors['item'] = 'Bu etkinlik için online kayıt alınmıyor.';
      elseif (self::seatsLeft($ev) < 1) $errors['item'] = 'Bu etkinlikte yer kalmadı.';
      else { $item = ['id' => $ev['id'], 'name' => $ev['title']]; $start = $ev['start']; $duration = $ev['duration']; $mode = $ev['type'] === 'online' ? 'online' : 'yuzyuze'; $price = $ev['price']; $eventId = $ev['id']; }
    } else {
      $svc = null;
      foreach (Store::read('services', []) as $s) if ($s['id'] === ($in['serviceId'] ?? null) && !empty($s['bookable'])) $svc = $s;
      if (!$svc) $errors['item'] = 'Bir hizmet seçin.';
      else {
        $mode = in_array($in['mode'] ?? null, $svc['modes'], true) ? $in['mode'] : $svc['modes'][0];
        $slot = null;
        foreach (self::slotsFor((string)($in['date'] ?? ''), $svc['id']) as $x) if ($x['time'] === ($in['time'] ?? null) && $x['free']) $slot = $x;
        if (!$slot) $errors['time'] = 'Seçtiğiniz saat artık uygun değil, lütfen başka bir saat seçin.';
        else { $item = ['id' => $svc['id'], 'name' => $svc['name']]; $start = $slot['start']; $duration = $svc['duration']; $price = $svc['price'] ?? 0; }
      }
    }
    if ($errors) return ['errors' => $errors];
    $b = [
      'id' => rand_hex(5), 'key' => rand_hex(12),
      'itemId' => $item['id'], 'itemName' => $item['name'], 'eventId' => $eventId,
      'start' => $start, 'duration' => $duration, 'mode' => $mode, 'price' => $price,
      'name' => $name, 'phone' => $phone, 'email' => $email, 'note' => mb_substr((string)($in['note'] ?? ''), 0, 1000),
      'marketing' => !empty($in['marketing']),
      'lang' => in_array($in['lang'] ?? '', I18n::LANGS, true) ? $in['lang'] : 'tr',
      'status' => 'request',
      'createdAt' => self::nowIso(),
    ];
    Store::update('bookings', [], function ($list) use ($b) { $list[] = $b; return $list; });
    return ['booking' => $b, 'item' => $item];
  }

  static function fmtDate($iso, string $l = 'tr'): string { return intl_fmt(strtotime((string)$iso), I18n::LOCALE[$l], 'dMMMMEEEEHHmm'); }
  private static function base(): string { return rtrim(env('SITE_URL') ?: I18n::$base, '/'); }

  // Kayıt alındığında: danışana ve Aynur Hanım'a e-posta
  static function requested(array $b): void {
    $L = $b['lang'] ?: 'tr'; $tt = fn($x) => t($x, $L);
    Mailer::send($b['email'], $tt('Talebiniz alındı') . ': ' . $tt($b['itemName']),
      '<p>' . $tt('Merhaba') . ' ' . esc($b['name']) . ',</p><p>' . $tt('Talebiniz alındı:') . ' <b>' . esc($tt($b['itemName'])) . '</b></p><p>' . $tt('Zaman') . ': ' . self::fmtDate($b['start'], $L) . '</p><p>' . $tt('Onay için en kısa sürede WhatsApp üzerinden size döneceğiz. Saatiniz bu sürede sizin için ayrılmıştır.') . '</p><p>Aynur Tümen</p>');
    if (env('ADMIN_EMAIL')) Mailer::send(env('ADMIN_EMAIL'), 'Yeni randevu talebi: ' . $b['itemName'] . ' · ' . $b['name'],
      '<p>' . self::fmtDate($b['start']) . ' · ' . esc($b['phone']) . ' · ' . esc($b['email']) . ($L !== 'tr' ? ' · ' . I18n::NAMES[$L] : '') . '</p><p>' . esc($b['note']) . '</p><p>Danışan bilgilerini WhatsApp\'tan da gönderecek. Görüşmeyi kesinleştirdikten sonra yönetim panelinde "Onayla" düğmesine basın; danışana onay e-postası ve (online ise) görüşme bağlantısı gider. Saat ' . self::REQUEST_HOURS . ' saat boyunca ayrılı tutulur.</p>');
  }

  static function confirm(string $id, ?array $payment = null): array {
    $list = Store::read('bookings', []);
    $b = null; foreach ($list as $x) if ($x['id'] === $id) $b = $x;
    if (!$b) throw new RuntimeException('Randevu bulunamadı');
    if ($b['status'] === 'confirmed') return $b;
    $meeting = null;
    if ($b['mode'] === 'online') {
      // Aynı etkinliğe kayıt olan herkes aynı odaya girer
      foreach ($list as $x) if ($b['eventId'] && ($x['eventId'] ?? null) === $b['eventId'] && !empty($x['meeting'])) { $meeting = $x['meeting']; break; }
      if (!$meeting) { $domain = env('JITSI_DOMAIN', 'meet.jit.si'); $room = 'AynurTumen-' . rand_hex(6); $meeting = ['provider' => 'jitsi', 'domain' => $domain, 'room' => $room, 'joinUrl' => "https://$domain/$room"]; }
    }
    $now = self::nowIso();
    $updated = Store::update('bookings', [], fn($all) => array_map(fn($x) => $x['id'] === $id ? array_merge($x, ['status' => 'confirmed', 'meeting' => $meeting, 'payment' => $payment, 'confirmedAt' => $now]) : $x, $all));
    $done = null; foreach ($updated as $x) if ($x['id'] === $id) $done = $x;
    $L = $done['lang'] ?: 'tr'; $tt = fn($x) => t($x, $L);
    $room = self::base() . I18n::prefix("/gorusme/{$done['id']}?k={$done['key']}", $L);
    Mailer::send($done['email'], $tt('Randevunuz onaylandı') . ': ' . $tt($done['itemName']),
      '<p>' . $tt('Merhaba') . ' ' . esc($done['name']) . ',</p><p>' . $tt('Kaydınız onaylandı:') . ' <b>' . esc($tt($done['itemName'])) . '</b></p><p>' . $tt('Zaman') . ': ' . self::fmtDate($done['start'], $L) . '</p>' .
      ($meeting ? '<p>' . $tt('Görüşme odası') . ': <a href="' . esc($room) . '">' . esc($room) . '</a></p>' : '<p>' . $tt('Adres bilgisi WhatsApp ile iletilecek.') . '</p>') .
      '<p>' . $tt('Sorunuz olursa WhatsApp hattımızdan yazabilirsiniz.') . '</p><p>Aynur Tümen</p>');
    if (env('ADMIN_EMAIL')) Mailer::send(env('ADMIN_EMAIL'), 'Randevu onaylandı: ' . $done['itemName'] . ' · ' . $done['name'], '<p>' . self::fmtDate($done['start']) . ' · ' . esc($done['phone']) . ' · ' . esc($done['email']) . '</p>');
    return $done;
  }

  // Randevu bilgilerinden Aynur Hanım'ın WhatsApp hattına gidecek hazır mesaj
  static function waUrl(array $b): string {
    $L = $b['lang'] ?: 'tr'; $tt = fn($x) => t($x, $L);
    $ev = !empty($b['eventId']) ? Events::find($b['eventId']) : null;
    $mode = $ev ? ($ev['type'] === 'online' ? 'Online' : $tt($ev['location'])) : ($b['mode'] === 'online' ? 'Online' : $tt('Yüz yüze'));
    $lines = [
      $tt(!empty($b['eventId']) ? 'Merhaba, etkinliğe kayıt olmak istiyorum.' : 'Merhaba, randevu almak istiyorum.'), '',
      $tt('Çalışma') . ': ' . $tt($b['itemName']),
      $tt('Şekil') . ': ' . $mode,
      $tt('Zaman') . ': ' . self::fmtDate($b['start'], $L) . ' (' . $tt('Türkiye saati') . ')',
      $tt('Ad soyad') . ': ' . $b['name'],
      $tt('Telefon') . ': ' . $b['phone'],
      $tt('E-posta') . ': ' . $b['email'],
    ];
    if ($b['note'] !== '') $lines[] = $tt('Not') . ': ' . $b['note'];
    $lines[] = $tt('Kayıt no') . ': ' . $b['id'];
    return 'https://wa.me/' . Store::read('settings', [])['phoneIntl'] . '?text=' . js_uri(implode("\n", $lines));
  }

  static function find(string $id, ?string $key = null): ?array {
    foreach (Store::read('bookings', []) as $b) if ($b['id'] === $id) return ($key !== null && $b['key'] !== $key) ? null : $b;
    return null;
  }
}
