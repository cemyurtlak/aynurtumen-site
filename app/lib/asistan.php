<?php
// WhatsApp asistanı: sık soruları Claude ile cevaplar, kayıt/dekont/kişisel konularda konuşmayı insana bırakır.
// Bilgi kaynağı sitedeki /bilgi sayfası ve gerçek (örnek olmayan) etkinlikler; orası güncellenince asistan da güncellenir.
// Ayarlar (sunucudaki app/ayarlar.env):
//   ANTHROPIC_API_KEY=...            Claude API anahtarı (Console → API Keys)
//   ASISTAN_MODEL=claude-haiku-5-5   (isteğe bağlı)
//   ASISTAN_AYLIK_TAVAN_USD=5        Bu tutar aşılırsa yapay zekâ susar, mesajlar insana kalır
//   ASISTAN_BILDIRIM_NUMARASI=90...  Devir bildirimi gidecek WhatsApp numarası (Meryem Hanım ya da Cem)
//   ASISTAN_TAKIP=1                  Cevap vermeyenlere ertesi gün (24 saat dolmadan) nazik takip mesajı
//   ASISTAN_KAPALI=1                 Acil durumda asistanı tamamen susturur (mesajlar yine kaydedilir)
final class Asistan {
  const SAKLA_GUN = 90;           // konuşmalar 90 gün sonra silinir (KVKK)
  const GUNLUK_KISI_SINIRI = 40;  // bir kişiye günde en çok bu kadar yapay zekâ cevabı
  const DEVIR_SAAT = 12;          // insana devredilen konuşmada asistan bu kadar saat susar

  static function dir(): string { $d = DATA_DIR . '/whatsapp'; if (!is_dir($d)) @mkdir($d, 0775, true); return $d; }
  private static function file(string $phone): string { return self::dir() . '/k_' . substr(hash('sha256', $phone), 0, 24) . '.json'; }
  static function load(string $phone): array {
    $f = self::file($phone);
    $c = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    return $c + ['phone' => $phone, 'name' => '', 'msgs' => [], 'handoffUntil' => 0, 'lastUser' => 0, 'followed' => false, 'lang' => 'tr', 'created' => time()];
  }
  static function save(array $c): void {
    $c['msgs'] = array_slice($c['msgs'], -60);
    $f = self::file($c['phone']);
    file_put_contents("$f.tmp", json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX); rename("$f.tmp", $f);
  }
  static function log(string $line): void {
    @file_put_contents(self::dir() . '/_gunluk.log', gmdate('Y-m-d H:i:s') . " $line\n", FILE_APPEND | LOCK_EX);
  }

  // Meta aynı mesajı birden çok kez yollayabilir: işlenenler hatırlanır
  private static function seen(string $id): bool {
    if ($id === '') return false;
    $f = self::dir() . '/_islenen.json';
    $fh = fopen($f, 'c+'); flock($fh, LOCK_EX);
    $ids = json_decode((string)stream_get_contents($fh), true) ?: [];
    $dup = in_array($id, $ids, true);
    if (!$dup) { $ids[] = $id; $ids = array_slice($ids, -500); ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($ids)); }
    flock($fh, LOCK_UN); fclose($fh);
    return $dup;
  }

  /* ---------- harcama takibi ---------- */
  private static function usage(): array {
    $f = self::dir() . '/_kullanim.json';
    $u = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    return ($u['ay'] ?? '') === gmdate('Y-m') ? $u : ['ay' => gmdate('Y-m'), 'usd' => 0.0, 'istek' => 0, 'girdi' => 0, 'cikti' => 0];
  }
  private static function addUsage(array $tok): void {
    $u = self::usage();
    // Claude Haiku 5.5 fiyatı (milyon token başına, ≤100K istek): girdi 0.10, çıktı 0.50, önbellek okuma 0.01, önbellek yazma 0.125
    $usd = (($tok['input_tokens'] ?? 0) * 0.10 + ($tok['output_tokens'] ?? 0) * 0.50
      + ($tok['cache_read_input_tokens'] ?? 0) * 0.01 + ($tok['cache_creation_input_tokens'] ?? 0) * 0.125) / 1e6;
    $u['usd'] = round($u['usd'] + $usd, 6); $u['istek']++;
    $u['girdi'] += ($tok['input_tokens'] ?? 0) + ($tok['cache_read_input_tokens'] ?? 0) + ($tok['cache_creation_input_tokens'] ?? 0);
    $u['cikti'] += $tok['output_tokens'] ?? 0;
    @file_put_contents(self::dir() . '/_kullanim.json', json_encode($u), LOCK_EX);
  }
  static function overBudget(): bool { return self::usage()['usd'] >= (float)(env('ASISTAN_AYLIK_TAVAN_USD') ?: 5); }

  /* ---------- bilgi ve talimat ---------- */
  static function bilgi(): string {
    $h = str_replace('</span><b>', ': <b>', preg_replace('/<!--i18n-(off|on)-->/', '', bilgi_body()));
    $h = preg_replace('/<(\/?)(h2|h3|li|p|div|article)\b[^>]*>/i', "\n", $h);
    $t = html_entity_decode(strip_tags($h), ENT_QUOTES, 'UTF-8');
    $t = preg_replace("/[ \t]+/u", ' ', $t);
    $t = trim(preg_replace("/\n\s*\n+/u", "\n", $t));
    $ev = array_filter(Events::upcoming(), fn($e) => empty($e['ornek']));
    $evs = $ev ? implode("\n", array_map(function ($e) { $d = I18n::dateParts($e['start'], 'tr'); return '- ' . $e['title'] . ' · ' . $d['day'] . ' ' . $d['month'] . ' ' . $d['year'] . ' ' . $d['dow'] . ' ' . $d['hm'] . ' · ' . $e['location']; }, $ev))
      : '- Şu an duyurulmuş tarih yok. Yeni tarihler için ekibimiz bilgi verecek.';
    return $t . "\n\nYAKLAŞAN TARİHLER\n" . $evs;
  }

  static function system(): string {
    $site = rtrim(env('SITE_URL') ?: 'https://www.aynurtumen.com.tr', '/');
    return <<<TXT
Sen, aile dizimi (konstelasyon) kolaylaştırıcısı ve yaşam koçu Aynur Tümen'in WhatsApp hattındaki dijital karşılama asistanısın. Görevin, yazan kişiye çalışmalar, ücretler, saatler ve kayıt adımları hakkında doğru ve sıcak bir ilk bilgi vermek; gerisini ekibe (Meryem Hanım ve Aynur Hanım) bırakmaktır.

KURALLAR
1. Yalnızca aşağıdaki BİLGİ bölümüne dayan. Orada olmayan hiçbir şeyi (fiyat, tarih, adres, indirim, IBAN, söz) uydurma. Bilmiyorsan "Bunu ekibimiz size netleştirecek" de ve devret.
2. Kim olduğun sorulursa Aynur Tümen ekibinin dijital asistanı olduğunu açıkça söyle. Kendini Aynur Hanım ya da bir insan gibi tanıtma.
3. Ücret ya da genel bilgi sorana kısa özet ver ve bilgilendirme sayfasını paylaş: Türkçe {$site}/bilgi , İngilizce {$site}/en/bilgi , Rusça {$site}/ru/bilgi , Almanca {$site}/de/bilgi .
4. Kayıt olmak, randevu almak, ödeme yapmak isteyene: hangi çalışmayı (online/yüz yüze, bireysel/grup, açılımcı/temsilci) istediğini netleştir. Netleşince "IBAN bilgisi ve gün-saat için ekibimiz size buradan dönecek" de ve devret (neden: kayıt). IBAN yazma, gün/saat verme, kesin kayıt sözü verme.
5. Kişi dekont/ödeme gönderdiğini söylerse teşekkür et, ekibin kontrol edip döneceğini söyle ve devret (neden: dekont).
6. Kişi kendi sorununu, ailesini, duygularını, sağlığını anlatmaya başlarsa: tek cümleyle şefkatle karşıla, yorum, analiz, teşhis ya da tavsiye yapma; "Aynur Hanım/ekibimiz size dönecek" de ve devret (neden: kişisel). Kişi kendine ya da başkasına zarar verme düşüncesinden söz ederse 112'yi aramasını nazikçe söyle ve hemen devret (neden: acil).
7. Şikâyet, iade, iptal, erteleme, kurumsal teklif, basın, işbirliği → kısa cevap ver ve devret.
8. Üslup: sıcak, sade, saygılı; "siz" diye hitap et; en fazla 4-5 kısa cümle; en fazla bir emoji (🌸 gibi). Madde işareti gerekirse WhatsApp'a uygun kısa satırlar kullan, Markdown başlığı kullanma.
9. Kişinin yazdığı dilde cevap ver (Türkçe, İngilizce, Rusça ya da Almanca). Ücretleri TL olarak aynen yaz.
10. Selam ya da teşekkür gibi kısa mesajlara kısa ve sıcak karşılık ver; konuyu uzatma.

ÇIKTI
Yalnızca şu biçimde tek bir JSON nesnesi döndür, başka hiçbir şey yazma:
{"cevap": "kişiye gidecek mesaj", "devret": false, "neden": "", "dil": "tr"}
"devret" true ise "neden" şunlardan biri olsun: kayıt, dekont, kişisel, acil, bilinmiyor, diğer.

BİLGİ
TXT . "\n" . self::bilgi() . "\n\nBugünün tarihi: " . date('d.m.Y');
  }

  /* ---------- Claude ---------- */
  static function claude(array $msgs): array {
    $key = env('ANTHROPIC_API_KEY');
    if ($key === '') throw new RuntimeException('ANTHROPIC_API_KEY ayarlı değil');
    $body = ['model' => env('ASISTAN_MODEL') ?: 'claude-haiku-5-5', 'max_tokens' => 700,
      'system' => [['type' => 'text', 'text' => self::system(), 'cache_control' => ['type' => 'ephemeral']]],
      'messages' => $msgs];
    $ch = curl_init((env('ASISTAN_API_BASE') ?: 'https://api.anthropic.com') . '/v1/messages');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40,
      CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
      CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE)]);
    $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $cerr = curl_error($ch); curl_close($ch);
    if ($res === false) throw new RuntimeException('Claude bağlantı: ' . $cerr);
    $j = json_decode((string)$res, true) ?: [];
    if ($code !== 200) throw new RuntimeException("Claude HTTP $code: " . mb_substr((string)($j['error']['message'] ?? $res), 0, 200));
    self::addUsage($j['usage'] ?? []);
    $t = ''; foreach ($j['content'] ?? [] as $p) if (($p['type'] ?? '') === 'text') $t .= $p['text'];
    $t = trim(preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($t)));
    if (preg_match('/\{.*\}/s', $t, $m)) $o = json_decode($m[0], true);
    if (empty($o) || !isset($o['cevap'])) $o = ['cevap' => $t, 'devret' => false, 'neden' => '', 'dil' => 'tr'];
    return $o;
  }

  // Konuşma geçmişini Claude'un istediği sıraya çevirir (kullanıcı/asistan dönüşümlü, ilk mesaj kullanıcıdan)
  private static function history(array $c): array {
    $out = [];
    foreach (array_slice($c['msgs'], -20) as $m) {
      if (!in_array($m['r'], ['u', 'a'], true) || trim($m['t']) === '') continue;
      $role = $m['r'] === 'u' ? 'user' : 'assistant';
      if ($out && end($out)['role'] === $role) { $out[count($out) - 1]['content'] .= "\n" . $m['t']; continue; }
      if (!$out && $role === 'assistant') continue;
      $out[] = ['role' => $role, 'content' => $m['t']];
    }
    return $out;
  }

  /* ---------- bildirim ---------- */
  static function notify(array $c, string $why, string $last): void {
    $to = preg_replace('/\D/', '', env('ASISTAN_BILDIRIM_NUMARASI'));
    $label = ['kayit' => 'Kayıt isteği', 'kayıt' => 'Kayıt isteği', 'dekont' => 'Dekont geldi', 'kisisel' => 'Kişisel konu', 'kişisel' => 'Kişisel konu',
      'acil' => '⚠️ ACİL', 'bilinmiyor' => 'Cevabı bilinmeyen soru', 'butce' => 'Asistan bütçesi doldu', 'medya' => 'Dosya/ses geldi'][$why] ?? 'İnsan cevabı gerekiyor';
    self::log("DEVIR {$c['phone']} $why");
    if ($to === '' || $to === $c['phone']) return;
    WhatsApp::sendText($to, "🔔 $label\n" . ($c['name'] ?: 'İsimsiz') . ' · +' . $c['phone'] . "\nSon mesaj: " . mb_substr($last, 0, 300) . "\n\nAsistan bu konuşmada " . self::DEVIR_SAAT . ' saat susacak; cevabı WhatsApp Business uygulamasından verebilirsiniz.');
  }

  /* ---------- gelen mesaj ---------- */
  static function handle(array $m): void {
    if ($m['from'] === '' || self::seen($m['id'])) return;
    $c = self::load($m['from']);
    if ($m['name'] !== '') $c['name'] = $m['name'];
    $now = time();
    $label = $m['text'] !== '' ? $m['text'] : '[' . $m['type'] . ']';
    $c['msgs'][] = ['r' => 'u', 't' => $label, 'ts' => $now, 'type' => $m['type']];
    $c['lastUser'] = $now; $c['followed'] = false;
    WhatsApp::markRead($m['id']);

    $reply = null; $handoff = null;
    if (env('ASISTAN_KAPALI') === '1' || !WhatsApp::on()) { self::save($c); return; }
    if ($c['handoffUntil'] > $now) { self::save($c); return; } // insan ilgileniyor, asistan susar

    if (in_array($m['type'], ['image', 'document'], true)) {
      $reply = self::fixed('dekont', $c['lang']); $handoff = 'dekont';
    } elseif (in_array($m['type'], ['audio', 'video', 'sticker', 'location', 'contacts'], true) || $m['text'] === '') {
      $reply = self::fixed('medya', $c['lang']); $handoff = 'medya';
    } elseif (self::overBudget()) {
      $reply = self::fixed('butce', $c['lang']); $handoff = 'butce';
    } elseif (count(array_filter($c['msgs'], fn($x) => $x['r'] === 'a' && $x['ts'] > $now - 86400)) >= self::GUNLUK_KISI_SINIRI) {
      $handoff = 'bilinmiyor';
    } else {
      try {
        $o = self::claude(self::history($c));
        $reply = trim((string)$o['cevap']);
        if (!empty($o['dil']) && in_array($o['dil'], ['tr', 'en', 'ru', 'de'], true)) $c['lang'] = $o['dil'];
        if (!empty($o['devret'])) $handoff = (string)($o['neden'] ?: 'diğer');
      } catch (Throwable $e) {
        self::log('HATA ' . $e->getMessage());
        $reply = self::fixed('butce', $c['lang']); $handoff = 'bilinmiyor';
      }
    }
    if ($reply !== null && $reply !== '') {
      $r = WhatsApp::sendText($m['from'], $reply);
      $c['msgs'][] = ['r' => 'a', 't' => $reply, 'ts' => time(), 'ok' => $r['ok']];
      if (!$r['ok']) self::log('GONDERILEMEDI ' . $r['error']);
    }
    if ($handoff !== null) {
      $c['handoffUntil'] = time() + self::DEVIR_SAAT * 3600;
      $c['msgs'][] = ['r' => 's', 't' => "devir: $handoff", 'ts' => time()];
      self::save($c);
      self::notify($c, $handoff, $label);
      return;
    }
    self::save($c);
  }

  static function fixed(string $k, string $l): string {
    $T = [
      'dekont' => ['tr' => 'Teşekkür ederiz 🌸 Gönderdiğiniz dosya ekibimize iletildi. Kontrol edildikten sonra size buradan dönüş yapılacak.',
                   'en' => 'Thank you 🌸 Your file has been passed to our team. We will get back to you here once it has been checked.',
                   'ru' => 'Спасибо 🌸 Ваш файл передан нашей команде. После проверки мы ответим вам здесь.',
                   'de' => 'Vielen Dank 🌸 Ihre Datei wurde an unser Team weitergeleitet. Nach der Prüfung melden wir uns hier bei Ihnen.'],
      'medya' => ['tr' => 'Mesajınız için teşekkürler 🌸 Sesli mesaj ve dosyaları şu an ekibimiz dinleyip size dönecek. Dilerseniz sorunuzu yazılı olarak da iletebilirsiniz.',
                  'en' => 'Thank you for your message 🌸 Our team will listen to it and get back to you. You are also welcome to send your question in writing.',
                  'ru' => 'Спасибо за сообщение 🌸 Наша команда прослушает его и ответит вам. Вы также можете написать свой вопрос текстом.',
                  'de' => 'Danke für Ihre Nachricht 🌸 Unser Team hört sie sich an und meldet sich bei Ihnen. Gern können Sie Ihre Frage auch schriftlich senden.'],
      'butce' => ['tr' => 'Mesajınız için teşekkürler 🌸 Ekibimiz en kısa sürede size buradan dönüş yapacak.',
                  'en' => 'Thank you for your message 🌸 Our team will get back to you here as soon as possible.',
                  'ru' => 'Спасибо за сообщение 🌸 Наша команда ответит вам здесь в ближайшее время.',
                  'de' => 'Danke für Ihre Nachricht 🌸 Unser Team meldet sich so bald wie möglich hier bei Ihnen.'],
      'takip' => ['tr' => "Merhaba, umarım iyisinizdir 🙏 Dün aile dizimi çalışmalarımızla ilgili bilgi almıştınız. Mesajımızı inceleme fırsatınız oldu mu?\n\nMerak ettiğiniz ya da netleştirmek istediğiniz bir konu varsa memnuniyetle yardımcı oluruz 🌸\nSevgilerimizle",
                  'en' => "Hello, we hope you are well 🙏 Yesterday you asked about our family constellation sessions. Have you had a chance to look at our message?\n\nIf there is anything you would like to ask or clarify, we are happy to help 🌸\nWarm regards",
                  'ru' => "Здравствуйте, надеемся, у вас всё хорошо 🙏 Вчера вы интересовались нашими семейными расстановками. Удалось ли вам ознакомиться с нашим сообщением?\n\nЕсли у вас есть вопросы или что-то нужно уточнить, мы с радостью поможем 🌸\nС теплом",
                  'de' => "Hallo, wir hoffen, es geht Ihnen gut 🙏 Gestern haben Sie sich nach unseren Familienaufstellungen erkundigt. Hatten Sie schon Gelegenheit, unsere Nachricht anzusehen?\n\nWenn Sie Fragen haben oder etwas klären möchten, helfen wir Ihnen gern 🌸\nHerzliche Grüße"],
    ];
    return $T[$k][$l] ?? $T[$k]['tr'];
  }

  /* ---------- zamanlı işler: ertesi gün takip, eski konuşmaları silme ---------- */
  // Ziyaretçi geldikçe en çok 10 dakikada bir çalışır (Hostinger cron kurulursa cron.php de çağırır)
  static function tick(bool $force = false): void {
    if (!WhatsApp::on()) return;
    $f = DATA_DIR . '/.asistan-tick';
    if (!$force && is_file($f) && time() - (int)filemtime($f) < 600) return;
    @touch($f);
    $force ? self::periodic() : later([self::class, 'periodic']);
  }
  static function periodic(): void {
    $now = time();
    foreach (glob(self::dir() . '/k_*.json') ?: [] as $file) {
      $c = json_decode((string)file_get_contents($file), true); if (!$c) continue;
      $lastAny = $c['msgs'] ? (int)(end($c['msgs'])['ts'] ?? 0) : (int)($c['lastUser'] ?? 0);
      if ($lastAny < $now - self::SAKLA_GUN * 86400) { @unlink($file); continue; }
      // Takip: kişi en son ~20-23,5 saat önce yazdıysa, son mesaj bizdense, devir yoksa ve takip yapılmadıysa
      // (24 saatlik pencere içinde olduğu için Meta ücret almaz)
      $age = $now - (int)$c['lastUser'];
      $lastMsg = $c['msgs'] ? end($c['msgs']) : [];
      if (env('ASISTAN_TAKIP', '1') !== '0' && env('ASISTAN_KAPALI') !== '1' && empty($c['followed'])
        && $age >= 20 * 3600 && $age < 23.5 * 3600 && ($lastMsg['r'] ?? '') === 'a' && (int)$c['handoffUntil'] < $now) {
        $txt = self::fixed('takip', $c['lang'] ?? 'tr');
        $r = WhatsApp::sendText($c['phone'], $txt);
        $c['followed'] = true;
        $c['msgs'][] = ['r' => 'a', 't' => $txt, 'ts' => $now, 'ok' => $r['ok'], 'takip' => true];
        self::save($c);
        self::log("TAKIP {$c['phone']} " . ($r['ok'] ? 'ok' : $r['error']));
      }
    }
  }

  static function status(): array {
    $n = count(glob(self::dir() . '/k_*.json') ?: []);
    return ['whatsapp' => WhatsApp::on(), 'claude' => env('ANTHROPIC_API_KEY') !== '', 'konusma' => $n, 'kullanim' => self::usage(),
      'tavan_usd' => (float)(env('ASISTAN_AYLIK_TAVAN_USD') ?: 5)];
  }
}
