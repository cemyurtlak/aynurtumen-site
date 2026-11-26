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
  const GUNLUK_KISI_SINIRI = 30;  // bir kişiye günde en çok bu kadar yapay zekâ cevabı (sonrası ekibe kalır)
  const DAKIKA_SINIRI = 5;        // 2 dakikada bundan fazla mesaj gelirse asistan cevaplamaz (seri mesaj/spam)
  const MESAJ_UZUNLUK = 1500;     // tek mesajın en fazla bu kadar karakteri yapay zekâya gider
  const INSAN_SAAT = 2;           // ekip WhatsApp Business uygulamasından yazınca asistan bu kadar saat araya girmez
  const BILDIRIM_ARA_SAAT = 6;    // aynı kişi için aynı nedenle en sık bu aralıkla bildirim

  static function dir(): string { $d = DATA_DIR . '/whatsapp'; if (!is_dir($d)) @mkdir($d, 0775, true); return $d; }
  private static function file(string $phone): string { return self::dir() . '/k_' . substr(hash('sha256', $phone), 0, 24) . '.json'; }
  static function load(string $phone): array {
    $f = self::file($phone);
    $c = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    return $c + ['phone' => $phone, 'name' => '', 'msgs' => [], 'handoffAt' => 0, 'handoffWhy' => '', 'notifiedAt' => 0, 'humanUntil' => 0, 'lastUser' => 0, 'followed' => false, 'lang' => 'tr', 'created' => time()];
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
3. Ücret ya da genel bilgi sorana kısa özet ver ve bilgilendirme sayfasını paylaş: Türkçe {$site}/bilgi , İngilizce {$site}/en/bilgi , Rusça {$site}/ru/bilgi , Almanca {$site}/de/bilgi . Özetin sonunda kişiye "hangi çalışmayla ilgilendiğinizi yazın" diye sorma; kişi hangi çalışmanın kendisine uygun olduğunu bilmeyebilir. Bunun yerine ekibin dönüp birlikte karar vermesini teklif et; bunun için KANAL notunda verilen dönüş cümlesini aynen kullan. Kişi kabul edince devret (neden: diğer) ve "özet" alanına çalışma seçiminde yardım istediğini yaz.
4. Kayıt olmak, randevu almak, ödeme yapmak isteyene: hangi çalışmayı (online/yüz yüze, bireysel/grup, açılımcı/temsilci) istediğini netleştir. Netleşince "IBAN bilgisi ve gün-saat için ekibimiz size buradan dönecek" de ve devret (neden: kayıt). IBAN yazma, gün/saat verme, kesin kayıt sözü verme.
5. Kişi dekont/ödeme gönderdiğini söylerse teşekkür et, ekibin kontrol edip döneceğini söyle ve devret (neden: dekont).
6. Kişi kendi sorununu, ailesini, duygularını, sağlığını anlatmaya başlarsa: tek cümleyle şefkatle karşıla, yorum, analiz, teşhis ya da tavsiye yapma; "Aynur Hanım/ekibimiz size dönecek" de ve devret (neden: kişisel). Kişi kendine ya da başkasına zarar verme düşüncesinden söz ederse 112'yi aramasını nazikçe söyle ve hemen devret (neden: acil).
7. Şikâyet, iade, iptal, erteleme, kurumsal teklif, basın, işbirliği → kısa cevap ver ve devret.
8. Üslup: sıcak, sade, saygılı; "siz" diye hitap et; en fazla 4-5 kısa cümle; en fazla bir emoji (🌸 gibi). Madde işareti gerekirse WhatsApp'a uygun kısa satırlar kullan, Markdown başlığı kullanma.
9. Kişinin yazdığı dilde cevap ver (Türkçe, İngilizce, Rusça ya da Almanca). Ücretleri TL olarak aynen yaz.
10. Selam ya da teşekkür gibi kısa mesajlara kısa ve sıcak karşılık ver; konuyu uzatma.
11. Yönlendirme: Kişinin anlattığı ihtiyaca en uygun çalışmayı (bireysel/grup, online/yüz yüze) kısa gerekçesiyle öner ve nazik bir sonraki adım teklif et ("Sizin için uygun bir zaman ayarlamamızı ister misiniz?" gibi). Kararsızsa soru sormasına alan bırak. Baskı kurma, aciliyet ya da indirim uydurma, kişinin duygusal durumunu satış için kullanma; "hayır" ya da "düşüneceğim" cevabına saygı göster.
12. Doğal konuş: kalıp cümleler ve her mesajda kendini tanıtma yok; ama sorulursa dijital asistan olduğunu her zaman dürüstçe söyle.

ÇIKTI
Cevabını her zaman "yanit" aracıyla ver (alanlar: cevap, devret, neden, dil, ozet, ad, telefon). "cevap" alanına yalnızca kişiye gidecek düz metni yaz; içine JSON, alan adı ya da tırnaklı yapı koyma.
"ozet": Konuşmanın tamamından, yalnızca kişinin kendi söylediklerine dayanan 1-3 cümlelik not: ilgilendiği çalışma, tercihleri (online/yüz yüze, gün/saat, dil), sorduğu ve takıldığı noktalar, verdiği iletişim bilgisi. Kişilik, ruh hâli ya da psikolojik yorum ekleme; kişinin anlattığı özel/aile konusunu ayrıntılandırma ("kişisel bir konu paylaştı" demen yeterli).
"devret" true ise "neden" şunlardan biri olsun: kayıt, dekont, kişisel, acil, bilinmiyor, güncelleme, diğer.

BİLGİ
TXT . "\n" . self::bilgi() . "\n\nBugünün tarihi: " . date('d.m.Y');
  }

  /* ---------- Claude ---------- */
  static function claude(array $msgs, string $durum = ''): array {
    $key = env('ANTHROPIC_API_KEY');
    if ($key === '') throw new RuntimeException('ANTHROPIC_API_KEY ayarlı değil');
    // Cevap her zaman "yanit" aracıyla, API'nin doğruladığı yapılandırılmış veri olarak gelir;
    // böylece ham JSON metni hiçbir zaman kişiye gösterilmez.
    $tool = ['name' => 'yanit', 'description' => 'Kişiye gidecek mesajı ve ekip notunu kaydeder.',
      'input_schema' => ['type' => 'object', 'required' => ['cevap', 'devret'], 'properties' => [
        'cevap' => ['type' => 'string', 'description' => 'Kişiye gidecek mesaj (düz metin)'],
        'devret' => ['type' => 'boolean'],
        'neden' => ['type' => 'string', 'enum' => ['', 'kayit', 'kayıt', 'dekont', 'kişisel', 'acil', 'bilinmiyor', 'güncelleme', 'diğer']],
        'dil' => ['type' => 'string'],
        'ozet' => ['type' => 'string', 'description' => 'Ekip için 1-3 cümlelik kısa not'],
        'ad' => ['type' => 'string'], 'telefon' => ['type' => 'string']]]];
    $body = ['model' => env('ASISTAN_MODEL') ?: 'claude-haiku-5-5', 'max_tokens' => 1500,
      'system' => array_merge([['type' => 'text', 'text' => self::system(), 'cache_control' => ['type' => 'ephemeral']]],
        $durum !== '' ? [['type' => 'text', 'text' => $durum]] : []),
      'tools' => [$tool], 'tool_choice' => ['type' => 'tool', 'name' => 'yanit'],
      'messages' => $msgs];
    return self::parseOut(self::api($body));
  }

  // Claude Messages API çağrısı; harcamayı kaydeder, içerik bloklarını döndürür
  static function api(array $body): array {
    $key = env('ANTHROPIC_API_KEY');
    if ($key === '') throw new RuntimeException('ANTHROPIC_API_KEY ayarlı değil');
    $ch = curl_init((env('ASISTAN_API_BASE') ?: 'https://api.anthropic.com') . '/v1/messages');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 50,
      CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
      CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE)]);
    $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $cerr = curl_error($ch); curl_close($ch);
    if ($res === false) throw new RuntimeException('Claude bağlantı: ' . $cerr);
    $j = json_decode((string)$res, true) ?: [];
    if ($code !== 200) throw new RuntimeException("Claude HTTP $code: " . mb_substr((string)($j['error']['message'] ?? $res), 0, 200));
    self::addUsage($j['usage'] ?? []);
    return $j['content'] ?? [];
  }

  // Model çıktısından {cevap, devret, ...} çıkarır; ham JSON asla kişiye gitmez.
  static function parseOut(array $content): array {
    $o = null; $t = '';
    foreach ($content as $p) {
      if (($p['type'] ?? '') === 'tool_use' && is_array($p['input'] ?? null)) $o = $p['input'];
      if (($p['type'] ?? '') === 'text') $t .= $p['text'];
    }
    if (!$o) {
      $t = trim(preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($t)));
      if (preg_match('/\{.*\}/s', $t, $m)) $o = json_decode($m[0], true);
      // Yarım kalmış JSON: en azından "cevap" alanını kurtar
      if (empty($o['cevap']) && preg_match('/"cevap"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/su', $t, $m)) {
        $o = ['cevap' => json_decode('"' . $m[1] . '"') ?? stripcslashes($m[1])];
      }
      if (empty($o['cevap']) && $t !== '' && !str_contains($t, '"cevap"')) $o = ['cevap' => $t];
    }
    if (empty($o['cevap']) || !is_string($o['cevap'])) {
      $o = ['cevap' => 'Mesajınızı aldık, teşekkür ederiz 🌸 Ekibimiz en kısa sürede size dönecek.', 'devret' => true, 'neden' => 'bilinmiyor'];
    }
    if (($o['neden'] ?? '') === 'kayit') $o['neden'] = 'kayıt';
    return $o + ['devret' => false, 'neden' => '', 'dil' => 'tr', 'ozet' => '', 'ad' => '', 'telefon' => ''];
  }

  // Konuşma geçmişini Claude'un istediği sıraya çevirir (kullanıcı/asistan dönüşümlü, ilk mesaj kullanıcıdan)
  private static function history(array $c): array {
    $out = [];
    foreach (array_slice($c['msgs'], -20) as $m) {
      if (!in_array($m['r'], ['u', 'a', 'h'], true) || trim($m['t']) === '') continue;
      $role = $m['r'] === 'u' ? 'user' : 'assistant';
      if ($m['r'] === 'h') $m['t'] = '[Bu mesajı ekipten bir kişi WhatsApp Business uygulamasından yazdı] ' . $m['t'];
      if ($out && end($out)['role'] === $role) { $out[count($out) - 1]['content'] .= "\n" . $m['t']; continue; }
      if (!$out && $role === 'assistant') continue;
      $out[] = ['role' => $role, 'content' => $m['t']];
    }
    return $out;
  }

  /* ---------- bildirim ---------- */
  static function notify(array $c, string $why, string $last, string $ozet = ''): void {
    $to = preg_replace('/\D/', '', env('ASISTAN_BILDIRIM_NUMARASI'));
    $label = ['kayit' => 'Kayıt isteği', 'kayıt' => 'Kayıt isteği', 'dekont' => 'Dekont geldi', 'kisisel' => 'Kişisel konu', 'kişisel' => 'Kişisel konu',
      'acil' => '⚠️ ACİL', 'bilinmiyor' => 'Cevabı bilinmeyen soru', 'butce' => 'Asistan bütçesi doldu', 'medya' => 'Dosya/ses geldi', 'güncelleme' => 'Kişi tercihini değiştirdi', 'guncelleme' => 'Kişi tercihini değiştirdi'][$why] ?? 'İnsan cevabı gerekiyor';
    self::log("DEVIR {$c['phone']} $why");
    self::team("🔔 $label (WhatsApp)", ($c['name'] ?: 'İsimsiz') . ' · +' . $c['phone'],
      'Son mesaj: ' . mb_substr($last, 0, 300) . ($ozet !== '' ? "\n📝 Özet: " . mb_substr($ozet, 0, 500) : '') . "\n👉 wa.me/" . $c['phone'],
      'Asistan genel soruları cevaplamaya devam eder. Siz bu kişiye WhatsApp Business uygulamasından yazdığınızda ' . self::INSAN_SAAT . ' saat araya girmez.');
  }

  // Ekibe WhatsApp bildirimi: önce düz mesaj (ücretsiz, 24 saatlik pencere içindeyse gider);
  // olmazsa onaylı şablon (WA_SABLON_BILDIRIM ayarlıysa). Şablon gövdesi: {{1}} başlık, {{2}} kişi, {{3}} ayrıntı.
  static function team(string $title, string $person, string $detail, string $footer = ''): bool {
    $to = preg_replace('/\D/', '', env('ASISTAN_BILDIRIM_NUMARASI'));
    if ($to === '' || !WhatsApp::on()) return false;
    $r = WhatsApp::sendText($to, "$title\n$person\n$detail" . ($footer !== '' ? "\n\n$footer" : ''));
    if ($r['ok']) return true;
    $tpl = env('WA_SABLON_BILDIRIM');
    if ($tpl !== '') {
      $r2 = WhatsApp::sendTemplate($to, $tpl, [$title, $person, $detail]);
      if ($r2['ok']) return true;
      self::log('SABLON GONDERILEMEDI ' . $r2['error']);
    }
    self::log('EKIP BILDIRIMI GONDERILEMEDI ' . $r['error']);
    // WhatsApp'a ulaşılamadı: aynı bilgiler ADMIN_EMAIL adresine e-postayla gider (e-posta sistemi kapalı olsa da)
    if (($admin = env('ADMIN_EMAIL')) !== '') {
      $html = '<p><b>' . esc($title) . '</b></p><p>' . esc($person) . '</p><p>' . nl2br(esc($detail)) . '</p>'
        . ($footer !== '' ? '<p style="color:#666">' . esc($footer) . '</p>' : '')
        . '<p style="color:#666;font-size:12px">Bu e-posta, WhatsApp bildirimi gönderilemediği için yedek olarak gönderildi.</p>';
      $html = preg_replace('#wa\.me/(\d+)#', '<a href="https://wa.me/$1">wa.me/$1</a>', $html);
      $ok = Mailer::send($admin, trim(preg_replace('/[^\p{L}\p{N} \-:·]/u', '', $title)) . ' · ' . $person, $html, true);
      self::log('YEDEK E-POSTA ' . ($ok ? 'gonderildi' : 'gonderilemedi'));
    }
    return false;
  }

  /* ---------- web sitesi sohbet penceresi ---------- */
  private static function webFile(string $sid): string { return self::dir() . '/w_' . $sid . '.json'; }
  static function web(string $sid, string $text, string $lang, string $ip, string $page): array {
    if (!preg_match('/^[a-f0-9]{16,40}$/', $sid)) return ['ok' => false, 'cevap' => ''];
    $f = self::webFile($sid);
    $c = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $c += ['sid' => $sid, 'msgs' => [], 'lang' => $lang, 'created' => time(), 'ipHash' => substr(hash('sha256', $ip . 'aynur'), 0, 16), 'notified' => '', 'ad' => '', 'telefon' => '', 'ozet' => ''];
    $now = time();
    $text = trim(mb_substr($text, 0, self::MESAJ_UZUNLUK));
    if ($text === '') return ['ok' => false, 'cevap' => ''];
    $c['msgs'][] = ['r' => 'u', 't' => $text, 'ts' => $now, 'p' => mb_substr($page, 0, 80)];
    $fallback = ['tr' => 'Şu an yanıt veremiyorum 🌸 Dilerseniz WhatsApp’tan yazın, ekibimiz size dönsün.', 'en' => 'I can’t reply right now 🌸 Please write to us on WhatsApp and our team will get back to you.',
      'ru' => 'Сейчас я не могу ответить 🌸 Напишите нам в WhatsApp, и наша команда свяжется с вами.', 'de' => 'Ich kann gerade nicht antworten 🌸 Schreiben Sie uns gern per WhatsApp, unser Team meldet sich.'][$lang] ?? '';
    // sınırlar: oturum başına günlük, IP başına günlük, seri mesaj, aylık bütçe
    $ipCount = 0;
    foreach (glob(self::dir() . '/w_*.json') ?: [] as $wf) {
      if (filemtime($wf) < $now - 86400) continue;
      $w = json_decode((string)file_get_contents($wf), true);
      if (($w['ipHash'] ?? '') === $c['ipHash']) $ipCount += count(array_filter($w['msgs'] ?? [], fn($x) => $x['r'] === 'u' && $x['ts'] > $now - 86400));
    }
    $mine = count(array_filter($c['msgs'], fn($x) => $x['r'] === 'u' && $x['ts'] > $now - 86400));
    $burst = count(array_filter($c['msgs'], fn($x) => $x['r'] === 'u' && $x['ts'] > $now - 120));
    if ($mine > self::GUNLUK_KISI_SINIRI || $ipCount > 60 || $burst > self::DAKIKA_SINIRI || self::overBudget() || env('ASISTAN_KAPALI') === '1') {
      self::saveWeb($c); return ['ok' => true, 'cevap' => $fallback, 'wa' => true];
    }
    $site = rtrim(env('SITE_URL') ?: 'https://www.aynurtumen.com.tr', '/');
    $durum = "KANAL: Web sitesindeki sohbet penceresi (sayfa: $page, site dili: $lang). Kişi henüz bizimle WhatsApp'ta değil ve telefon numarasını bilmiyoruz; ekibin dönmesi gereken her durumda adıyla birlikte telefon (WhatsApp) numarasını da mutlaka iste. "
      . 'Dönüş cümlesi (ücret özetinden sonra aynen kullan): "Hangi çalışmanın size uygun olduğundan emin değilseniz, ekibimizin size dönüp birlikte en uygun çalışmayı belirleyebilmesi için telefon numaranızı paylaşmanız yeterli." Adın yeterli olduğunu asla söyleme; ekip ancak telefon numarasıyla dönebilir. '
      . 'Kişi kayıt, randevu, görüşme ya da kendisine dönülmesini isterse adını ve telefon (WhatsApp) numarasını nazikçe iste; ikisini aldığında devret (neden: kayıt ya da diğer) ve ekibin kısa süre içinde WhatsApp’tan döneceğini söyle. '
      . 'Kişi isterse doğrudan WhatsApp’tan da yazabilir: https://wa.me/' . settings()['phoneIntl'] . ' . İletişim bilgisi vermek istemezse ısrar etme. '
      . 'Karşılama mesajı ("Merhaba, hoş geldiniz") pencerede zaten gösterildi; tekrar selamlama ya da kendini tanıtma, doğrudan konuya gir. Telefon numarası ve adı "ad" ve "telefon" alanlarına yaz (verilmediyse boş bırak). Numara olmadan ekip kişiye ulaşamaz; devretmen gerekiyorsa ve numara yoksa, ekibin dönebilmesi için WhatsApp numarasını nazikçe iste (vermezse doğrudan WhatsApp’tan yazabileceğini hatırlat). Bilgilendirme sayfası bağlantısında site dilini kullan.'
      . ($c['notified'] ? ' Bu kişinin bilgileri ekibe zaten iletildi; tekrar isteme.' : '');
    try {
      $hist = [];
      foreach (array_slice($c['msgs'], -20) as $m) {
        $role = $m['r'] === 'u' ? 'user' : 'assistant';
        if ($hist && end($hist)['role'] === $role) { $hist[count($hist) - 1]['content'] .= "\n" . $m['t']; continue; }
        if (!$hist && $role === 'assistant') continue;
        $hist[] = ['role' => $role, 'content' => $m['t']];
      }
      $o = self::claude($hist, $durum);
    } catch (Throwable $e) {
      self::log('WEB HATA ' . $e->getMessage());
      self::saveWeb($c); return ['ok' => true, 'cevap' => $fallback, 'wa' => true];
    }
    $reply = trim((string)($o['cevap'] ?? ''));
    $c['msgs'][] = ['r' => 'a', 't' => $reply, 'ts' => time()];
    foreach (['ad', 'telefon'] as $k) if (!empty($o[$k])) $c[$k] = mb_substr(trim((string)$o[$k]), 0, 80);
    if (!empty($o['ozet'])) $c['ozet'] = mb_substr(trim((string)$o['ozet']), 0, 600);
    $why = !empty($o['devret']) ? (string)($o['neden'] ?: 'diğer') : '';
    // Telefon numarası olmadan ekibe bildirim gitmez (kişiye ulaşılamaz). Devir isteği beklemede tutulur;
    // numara sonradan gelince bildirim o zaman gönderilir.
    if ($why !== '') $c['bekleyen'] = $why;
    $tel = preg_replace('/\D/', '', $c['telefon']);
    $telOk = strlen($tel) >= 10;
    if ($why === '' && $telOk && !empty($c['bekleyen'])) $why = $c['bekleyen'];
    $key = $why . '|' . $tel;
    if ($why !== '' && $telOk && ($key !== $c['notified'] || $why === 'acil')) {
      $c['bekleyen'] = '';
      if (strlen($tel) === 10 && $tel[0] === '5') $tel = '90' . $tel; elseif (strlen($tel) === 11 && $tel[0] === '0') $tel = '9' . $tel;
      $label = ['kayit' => 'Kayıt isteği', 'kayıt' => 'Kayıt isteği', 'kisisel' => 'Kişisel konu', 'kişisel' => 'Kişisel konu', 'acil' => '⚠️ ACİL', 'güncelleme' => 'Tercih değişti'][$why] ?? 'Dönüş bekliyor';
      self::team("🔔 $label (web sohbeti)", ($c['ad'] ?: 'İsim vermedi') . ' · ' . ($c['telefon'] ?: 'telefon vermedi'),
        ($c['ozet'] !== '' ? '📝 Özet: ' . $c['ozet'] : 'Son mesaj: ' . mb_substr($text, 0, 300)) . ($tel !== '' ? "\n👉 wa.me/$tel" : ''));
      $c['notified'] = $key;
      self::log("WEB DEVIR $sid $why");
    }
    self::saveWeb($c);
    return ['ok' => true, 'cevap' => $reply];
  }
  private static function saveWeb(array $c): void {
    $c['msgs'] = array_slice($c['msgs'], -60);
    $f = self::webFile($c['sid']);
    file_put_contents("$f.tmp", json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX); rename("$f.tmp", $f);
  }

  /* ---------- gelen mesaj ---------- */
  static function handle(array $m): void {
    if ($m['from'] === '' || self::seen($m['id'])) return;
    $c = self::load($m['from']);
    if ($m['name'] !== '') $c['name'] = $m['name'];
    $now = time();
    $label = $m['text'] !== '' ? mb_substr($m['text'], 0, self::MESAJ_UZUNLUK) : '[' . $m['type'] . ']';
    $c['msgs'][] = ['r' => 'u', 't' => $label, 'ts' => $now, 'type' => $m['type']];
    $c['lastUser'] = $now; $c['followed'] = false;
    WhatsApp::markRead($m['id']);

    $reply = null; $handoff = null;
    if (env('ASISTAN_KAPALI') === '1' || !WhatsApp::on()) { self::save($c); return; }
    // Ekip (bildirim) numarası yazdı: günün ilk mesajında pencerenin açıldığını bildir; komutlara cevap ver
    $team = array_filter(preg_split('/[\s,;]+/', preg_replace('/[^\d,; ]/', '', env('ASISTAN_BILDIRIM_NUMARASI'))) ?: []);
    if (in_array($m['from'], $team, true)) {
      $low = mb_strtolower($m['text']);
      $teamF = self::dir() . '/_ekip.json';
      $t = is_file($teamF) ? (json_decode((string)file_get_contents($teamF), true) ?: []) : [];
      // Sessiz pencere açma kodu: yalnız 24 saatlik ücretsiz bildirim penceresini açar; cevap yok, yapay zekâ çalışmaz
      if (preg_match('/^\s*(\.|aç|ac|etkinleştir|etkinlestir)\s*[.!]*\s*$/u', $low)) {
        $t['ack'] = date('Y-m-d'); @file_put_contents($teamF, json_encode($t), LOCK_EX);
        self::save($c); return;
      }
      if (preg_match('/^\s*(bitti|tamam|tamamlandı)\s+\+?(\d{4,})/u', $low, $mm)) {
        $n = self::resolve($mm[2]);
        self::save($c); WhatsApp::sendText($m['from'], $n ? "✅ $n kişinin bekleyen işi kapatıldı." : 'Bu numarayla bekleyen bir iş bulamadım.'); return;
      }
      if (!EtkinlikBot::acik() && preg_match('/durum|özet|ozet|atladı|atladi|bekleyen|yapılacak|yapilacak|ne var/u', $low)) {
        self::save($c); WhatsApp::sendText($m['from'], self::summary()); return;
      }
      if (($t['ack'] ?? '') !== date('Y-m-d')) {
        $t['ack'] = date('Y-m-d'); @file_put_contents($teamF, json_encode($t), LOCK_EX);
        WhatsApp::sendText($m['from'], "✅ Bildirim penceresi açıldı: bugün size gelecek bildirimler 24 saat boyunca ücretsiz.\n\n• \"durum\" yazın → günün özeti ve bekleyen işler\n• \"bitti 4321\" yazın → o numarayla ilgili işi kapatır (son 4 hane yeter)\n• \"etkinlikler\" yazın → sitedeki takvim; etkinlik eklemek/değiştirmek için normal cümleyle yazın, onayınız olmadan değişiklik yapılmaz\n• Sadece pencereyi açmak için \"aç\" yazın → cevap gelmez\n• \"test\" ile başlayan mesaj → asistanı müşteri gibi dener");
      }
    }
    // Yetkili numaradan etkinlik takvimi yönetimi (yalnızca takvimle ilgili mesajlarda devreye girer, onaysız değişiklik yapmaz)
    if ($m['type'] === 'text' && EtkinlikBot::yetkili($m['from'])) {
      $eb = EtkinlikBot::handle($m['from'], $m['text']);
      if ($eb !== null) {
        $c['msgs'][] = ['r' => 'a', 't' => $eb, 'ts' => $now];
        self::save($c); WhatsApp::sendText($m['from'], $eb); return;
      }
    }
    // Ekip numarası müşteri asistanını yalnız "test" ile başlayan mesajlarda dener; diğer mesajlara cevap verilmez (boşuna token harcanmaz)
    if (in_array($m['from'], $team, true)) {
      if (!preg_match('/^\s*test\b[\s:,-]*/u', $low ?? '', $tm)) { self::save($c); return; }
      $m['text'] = trim(mb_substr($m['text'], mb_strlen($tm[0])));
      if ($m['text'] === '') { self::save($c); return; }
      $c['msgs'][count($c['msgs']) - 1]['t'] = $m['text'];
    }
    // sonra normal akış
    if ((int)($c['humanUntil'] ?? 0) > $now) { self::save($c); return; } // ekipten biri şu an yazışıyor, asistan araya girmez

    if (in_array($m['type'], ['image', 'document'], true)) {
      $reply = self::fixed('dekont', $c['lang']); $handoff = 'dekont';
    } elseif (in_array($m['type'], ['audio', 'video', 'sticker', 'location', 'contacts'], true) || $m['text'] === '') {
      $reply = self::fixed('medya', $c['lang']); $handoff = 'medya';
    } elseif (count(array_filter($c['msgs'], fn($x) => $x['r'] === 'u' && $x['ts'] > $now - 120)) > self::DAKIKA_SINIRI) {
      self::save($c); return; // art arda çok mesaj: kaydet ama cevaplama (sonraki mesajda toplu cevap verir)
    } elseif (self::overBudget()) {
      $reply = self::fixed('butce', $c['lang']); $handoff = 'butce';
    } elseif (count(array_filter($c['msgs'], fn($x) => $x['r'] === 'a' && $x['ts'] > $now - 86400)) >= self::GUNLUK_KISI_SINIRI) {
      $handoff = 'bilinmiyor';
    } else {
      try {
        $kanal = 'KANAL: WhatsApp. Kişinin numarası zaten belli; ondan numara isteme. Dönüş cümlesi (ücret özetinden sonra aynen kullan): "Hangi çalışmanın size uygun olduğundan emin değilseniz, ekibimiz size buradan dönüp birlikte en uygun çalışmayı belirleyebilir; istemeniz yeterli." ';
        $durum = '';
        if ((int)($c['handoffAt'] ?? 0) > $now - 3 * 86400)
          $durum = 'DURUM: Bu kişinin konusu ' . date('d.m H:i', (int)$c['handoffAt']) . ' tarihinde ekibe iletildi (neden: ' . ($c['handoffWhy'] ?? '') . '). '
            . 'Ekip kişiye dönecek. Kişi yeni sorular sorarsa kurallara göre cevaplamaya devam et; fikrini ya da tercihini değiştirirse bunu kabul et, not aldığını söyle ve devret (neden: güncelleme) ki ekip yeni tercihi görsün; '
            . 'IBAN, gün-saat ve kesin kayıt için yine ekibin döneceğini hatırlat. Yeni bir neden yoksa tekrar devretmene gerek yok.';
        $h = array_values(array_filter($c['msgs'], fn($x) => $x['r'] === 'h' && $x['ts'] > $now - 7 * 86400));
        if ($h) $durum = 'DURUM: Ekip bu kişiyle son olarak ' . date('d.m H:i', (int)end($h)['ts']) . ' tarihinde doğrudan yazıştı (geçmişte "[Bu mesajı ekipten..." diye işaretli mesajlar). '
          . 'Ekibin söylediklerini (ödeme, IBAN, gün-saat, adres) bil ve onlarla çelişme; bunları kendin yeniden yazma, "ekibimizin yukarıdaki mesajında" diye yönlendir. '
          . 'Ödeme yaptığını, dekont gönderdiğini, saat değiştirmek ya da iptal etmek istediğini söylerse devret. Selam ya da genel soru ise sıcak ve kısa cevap ver; konuyu baştan anlatma.';
        $o = self::claude(self::history($c), $kanal . $durum);
        $reply = trim((string)$o['cevap']);
        if (!empty($o['dil']) && in_array($o['dil'], ['tr', 'en', 'ru', 'de'], true)) $c['lang'] = $o['dil'];
        if (!empty($o['devret'])) $handoff = (string)($o['neden'] ?: 'diğer');
        if (!empty($o['ozet'])) $c['ozet'] = mb_substr(trim((string)$o['ozet']), 0, 600);
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
      $c['msgs'][] = ['r' => 's', 't' => "devir: $handoff", 'ts' => time()];
      // aynı nedenle sık sık bildirim gitmesin; neden değişirse ya da süre geçtiyse yeniden bildir
      $again = $handoff !== ($c['handoffWhy'] ?? '') || (int)($c['notifiedAt'] ?? 0) < time() - self::BILDIRIM_ARA_SAAT * 3600 || in_array($handoff, ['acil', 'güncelleme', 'guncelleme'], true);
      $c['handoffAt'] = time(); if (!in_array($handoff, ['güncelleme', 'guncelleme'], true)) $c['handoffWhy'] = $handoff;
      if ($again) $c['notifiedAt'] = time();
      self::save($c);
      if ($again) self::notify($c, $handoff, $label, (string)($c['ozet'] ?? ''));
      return;
    }
    self::save($c);
  }

  // Ekip, WhatsApp Business uygulamasından bu kişiye yazdı (coexistence yankısı): asistan bir süre araya girmez
  static function humanReply(string $to, string $text): void {
    if ($to === '') return;
    $c = self::load($to);
    $c['humanUntil'] = time() + self::INSAN_SAAT * 3600;
    $c['msgs'][] = ['r' => 'h', 't' => $text, 'ts' => time()];
    self::save($c);
  }

  /* ---------- ekip komutları: günün özeti, bekleyen işler ---------- */
  static function pending(): array {
    $now = time(); $out = [];
    foreach (glob(self::dir() . '/k_*.json') ?: [] as $f) {
      $c = json_decode((string)file_get_contents($f), true); if (!$c) continue;
      $h = (int)($c['handoffAt'] ?? 0);
      if ($h < $now - 3 * 86400 || (int)($c['resolvedAt'] ?? 0) > $h) continue;
      foreach ($c['msgs'] as $x) if ($x['r'] === 'h' && $x['ts'] > $h) continue 2; // ekip yazmış
      $out[] = ['kanal' => 'WhatsApp', 'ad' => $c['name'] ?: 'İsimsiz', 'tel' => $c['phone'], 'neden' => $c['handoffWhy'] ?? '', 'ozet' => $c['ozet'] ?? '', 'ts' => $h];
    }
    foreach (glob(self::dir() . '/w_*.json') ?: [] as $f) {
      $c = json_decode((string)file_get_contents($f), true); if (!$c || empty($c['notified']) || filemtime($f) < $now - 3 * 86400 || !empty($c['resolvedAt'])) continue;
      $tel = preg_replace('/\D/', '', (string)($c['telefon'] ?? ''));
      $out[] = ['kanal' => 'Web sohbeti', 'ad' => $c['ad'] ?: 'İsim vermedi', 'tel' => $tel, 'neden' => explode('|', $c['notified'])[0], 'ozet' => $c['ozet'] ?? '', 'ts' => filemtime($f), 'wf' => $f];
    }
    foreach (Store::read('messages', []) as $msg) {
      $ts = strtotime((string)($msg['at'] ?? '')) ?: 0;
      if ($ts < $now - 3 * 86400 || !empty($msg['done'])) continue;
      $out[] = ['kanal' => 'İletişim formu', 'ad' => $msg['name'] ?? '', 'tel' => preg_replace('/\D/', '', (string)($msg['phone'] ?? '')), 'neden' => $msg['topic'] ?? '', 'ozet' => mb_substr((string)($msg['message'] ?? ''), 0, 160), 'ts' => $ts, 'mid' => $msg['id'] ?? ''];
    }
    usort($out, fn($a, $b) => $a['ts'] <=> $b['ts']);
    return $out;
  }
  static function resolve(string $digits): int {
    $n = 0; $now = time();
    foreach (glob(self::dir() . '/k_*.json') ?: [] as $f) {
      $c = json_decode((string)file_get_contents($f), true);
      if ($c && str_ends_with($c['phone'], $digits)) { $c['resolvedAt'] = $now; self::save($c); $n++; }
    }
    foreach (glob(self::dir() . '/w_*.json') ?: [] as $f) {
      $c = json_decode((string)file_get_contents($f), true);
      if ($c && ($t = preg_replace('/\D/', '', (string)($c['telefon'] ?? ''))) !== '' && str_ends_with($t, $digits)) { $c['resolvedAt'] = $now; self::saveWeb($c); $n++; }
    }
    Store::update('messages', [], function ($l) use ($digits, &$n) {
      foreach ($l as &$x) if (($t = preg_replace('/\D/', '', (string)($x['phone'] ?? ''))) !== '' && str_ends_with($t, $digits) && empty($x['done'])) { $x['done'] = true; $n++; }
      return $l;
    });
    return $n;
  }
  static function summary(): string {
    $now = time(); $since = $now - 86400; $wa = 0; $web = 0; $new = 0;
    foreach (glob(self::dir() . '/k_*.json') ?: [] as $f) {
      $c = json_decode((string)file_get_contents($f), true); if (!$c) continue;
      if (array_filter($c['msgs'], fn($x) => $x['r'] === 'u' && $x['ts'] > $since)) { $wa++; if ((int)($c['created'] ?? 0) > $since) $new++; }
    }
    foreach (glob(self::dir() . '/w_*.json') ?: [] as $f) if (filemtime($f) > $since) $web++;
    $forms = count(array_filter(Store::read('messages', []), fn($x) => (strtotime((string)($x['at'] ?? '')) ?: 0) > $since));
    $u = self::usage();
    $p = self::pending();
    $lines = ['📊 Son 24 saat', "• WhatsApp'ta yazışan: $wa kişi ($new yeni)", "• Site sohbeti: $web konuşma", "• İletişim formu: $forms mesaj",
      '• Asistan harcaması bu ay: $' . number_format($u['usd'], 2) . ' / $' . number_format((float)(env('ASISTAN_AYLIK_TAVAN_USD') ?: 5), 2), ''];
    if (!$p) $lines[] = '✅ Bekleyen iş yok.';
    else {
      $lines[] = '📋 Dönüş bekleyenler (' . count($p) . '):';
      foreach (array_slice($p, 0, 15) as $i => $x) {
        $lines[] = ($i + 1) . '. ' . $x['ad'] . ' · ' . $x['kanal'] . ' · ' . ($x['neden'] ?: '-') . ' · ' . date('d.m H:i', $x['ts']);
        if ($x['ozet'] !== '') $lines[] = '   ' . mb_substr($x['ozet'], 0, 160);
        if ($x['tel'] !== '') $lines[] = '   👉 wa.me/' . (strlen($x['tel']) === 10 && $x['tel'][0] === '5' ? '90' . $x['tel'] : (strlen($x['tel']) === 11 && $x['tel'][0] === '0' ? '9' . $x['tel'] : $x['tel']));
      }
      $lines[] = ''; $lines[] = 'İşi biten için "bitti" ve numaranın son 4 hanesini yazın (ör. bitti 4321).';
    }
    return implode("\n", $lines);
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
    foreach (glob(self::dir() . '/w_*.json') ?: [] as $file) if (filemtime($file) < $now - self::SAKLA_GUN * 86400) @unlink($file);
    foreach (glob(self::dir() . '/k_*.json') ?: [] as $file) {
      $c = json_decode((string)file_get_contents($file), true); if (!$c) continue;
      $lastAny = $c['msgs'] ? (int)(end($c['msgs'])['ts'] ?? 0) : (int)($c['lastUser'] ?? 0);
      if ($lastAny < $now - self::SAKLA_GUN * 86400) { @unlink($file); continue; }
      // Takip: kişi en son ~20-23,5 saat önce yazdıysa, son mesaj bizdense, devir yoksa ve takip yapılmadıysa
      // (24 saatlik pencere içinde olduğu için Meta ücret almaz)
      $age = $now - (int)$c['lastUser'];
      $lastMsg = $c['msgs'] ? end($c['msgs']) : [];
      if (env('ASISTAN_TAKIP', '1') !== '0' && env('ASISTAN_KAPALI') !== '1' && empty($c['followed'])
        && $age >= 20 * 3600 && $age < 23.5 * 3600 && ($lastMsg['r'] ?? '') === 'a' && (int)($c['handoffAt'] ?? 0) < $now - 86400 && (int)($c['humanUntil'] ?? 0) < $now) {
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
