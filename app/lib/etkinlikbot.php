<?php
// WhatsApp'tan etkinlik takvimi yönetimi (yalnızca yetkili numaralar).
//
// Güvenlik ve dikkat kuralları:
//  - Yalnızca ETKINLIK_YETKILI_NUMARA (yoksa ASISTAN_BILDIRIM_NUMARASI) numaralarından gelen mesajlar dikkate alınır.
//  - Her mesaj takvim komutu sayılmaz: yalnızca takvimle ilgili kelime/tarih geçerse ya da açık bir taslak varsa devreye girer.
//    Emin olunamazsa önce sorulur; konu takvim değilse asistanın normal akışı sürer.
//  - Değişiklik ancak bu dosyanın kendisi tarafından hazırlanan özet gösterildikten ve "evet" yazıldıktan sonra uygulanır.
//    Özeti yapay zekâ değil bu kod yazar; böylece gösterilen ile uygulanan birebir aynıdır.
//  - Her değişiklikten önce app/data/yedek/ içine yedek alınır; "geri al" son değişikliği geri getirir.
final class EtkinlikBot {
  const TASLAK_DK = 120;   // açık taslak bu kadar dakika sonra düşer
  const TURLER = ['online' => 'Online', 'yuzyuze' => 'Yüz yüze', 'kamp' => 'Kamp'];
  const GUNLER = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];
  const AYLAR = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

  static function yetkili(string $from): bool {
    $raw = env('ETKINLIK_YETKILI_NUMARA') ?: env('ASISTAN_BILDIRIM_NUMARASI');
    $list = array_filter(preg_split('/[\s,;]+/', preg_replace('/[^\d,; ]/', '', (string)$raw)) ?: []);
    return $from !== '' && in_array($from, $list, true);
  }

  private static function file(): string { return Asistan::dir() . '/_etkinlik.json'; }
  private static function state(): array {
    $f = self::file();
    $s = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $s += ['taslak' => null, 'hazir' => false, 'bekliyor' => false, 'ts' => 0, 'konusma' => [], 'sonYedek' => null, 'sonIslem' => ''];
    if ($s['ts'] < time() - self::TASLAK_DK * 60) { $s['taslak'] = null; $s['hazir'] = false; $s['bekliyor'] = false; $s['konusma'] = []; }
    return $s;
  }
  private static function save(array $s): void {
    $s['ts'] = time();
    $s['konusma'] = array_slice($s['konusma'], -12);
    file_put_contents(self::file(), json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
  }
  static function acik(): bool { $s = self::state(); return $s['taslak'] !== null || $s['bekliyor']; }
  private static function reset(array $s): array {
    $s['taslak'] = null; $s['hazir'] = false; $s['bekliyor'] = false; $s['konusma'] = [];
    return $s;
  }

  // Takvimle ilgili olabilecek mesaj mı? (yalnızca ilk tetikleme için; asıl kararı sonra yapay zekâ ve onay verir)
  static function tetik(string $low): bool {
    // mevcut bir etkinliğin adı geçiyorsa (ilk iki kelimesi)
    foreach (Store::read('events', []) as $e) {
      $w = array_slice(preg_split('/[\s:,]+/u', tr_lower((string)$e['title']), -1, PREG_SPLIT_NO_EMPTY), 0, 2);
      if (count($w) === 2 && mb_strlen($w[0] . $w[1]) >= 6 && str_contains($low, $w[0] . ' ' . $w[1])) return true;
    }
    return (bool)preg_match('/\b(sil|ekle|ertele|iptal et|kaldır|kaldir)\b|saatini|tarihini|ücretini|ucretini|kontenjan|yerini|açıklamasını|aciklamasini/u', $low) || (bool)preg_match('/etkinli|takvim|atölye|atolye|kamp|seminer|webinar|dolunay|yeni ?ay|grup aile dizimi|grup çalışma|grup calisma'
      . '|\b\d{1,2}[.:]\d{2}\b|\b\d{1,2} (ocak|şubat|subat|mart|nisan|mayıs|mayis|haziran|temmuz|ağustos|agustos|eylül|eylul|ekim|kasım|kasim|aralık|aralik)/u', $low);
  }

  /** Mesajı işler; takvimle ilgili değilse null döner (asistanın normal akışı sürer). */
  static function handle(string $from, string $text): ?string {
    if (!self::yetkili($from) || trim($text) === '') return null;
    $low = tr_lower(trim($text));
    $s = self::state();
    $acik = $s['taslak'] !== null || $s['bekliyor'];

    // Açık onay bekleyen değişiklik
    if ($s['hazir'] && $s['taslak'] && preg_match('/^\s*(evet|onay|onaylıyorum|onayliyorum|onaylarım|onaylarim|evet onaylıyorum|evet onayliyorum)\s*[.!👍✅]*\s*$/u', $low)) {
      try { $msg = self::uygula($s['taslak'], $s); }
      catch (Throwable $e) { error_log('[etkinlik] ' . $e->getMessage()); $msg = '⚠️ Değişiklik uygulanamadı, takvim olduğu gibi duruyor. (' . $e->getMessage() . ')'; }
      self::save(self::reset($s));
      return $msg;
    }
    if ($acik && preg_match('/^\s*(hayır|hayir|iptal|vazgeç|vazgec|vazgeçtim|vazgectim|boşver|bosver)\b/u', $low)) {
      self::save(self::reset($s));
      return '👍 Tamam, takvimde hiçbir değişiklik yapılmadı.';
    }
    if (preg_match('/^\s*geri al\s*[.!]*\s*$/u', $low)) return self::geriAl($s);
    if (preg_match('/^\s*(etkinlikler|takvim|etkinlik listesi)\s*[?.!]*\s*$/u', $low)) return self::liste();

    if (!$acik && !self::tetik($low)) return null;

    try { $o = self::anla($text, $s); }
    catch (Throwable $e) {
      error_log('[etkinlik] ' . $e->getMessage());
      return $acik ? '⚠️ Şu an mesajınızı işleyemedim, takvimde değişiklik yapılmadı. Birazdan tekrar yazabilir misiniz?' : null;
    }
    $niyet = $o['niyet'] ?? 'degil';
    if ($niyet === 'degil') return null; // takvimle ilgisi yok: normal asistan cevaplasın (açık taslak korunur)
    $s['konusma'][] = ['r' => 'u', 't' => mb_substr($text, 0, 800)];

    if ($niyet === 'liste') {
      self::save($s);
      $h = !empty($o['hedef_id']) ? self::bul((string)$o['hedef_id']) : null;
      return $h ? implode("\n", self::satirlar($h)) . (!empty($h['ornek']) ? "\n(örnek etkinlik)" : '') : self::liste();
    }
    if ($niyet === 'iptal') { self::save(self::reset($s)); return '👍 Tamam, takvimde hiçbir değişiklik yapılmadı.'; }

    $cevap = trim((string)($o['cevap'] ?? ''));
    if ($niyet === 'belirsiz') {
      $s['bekliyor'] = true; $s['hazir'] = false;
      $cevap = $cevap !== '' ? $cevap : 'Sitedeki etkinlik takviminde bir değişiklik yapmamı mı istiyorsunuz? (ekleme, saat/tarih değişikliği ya da silme)';
      $s['konusma'][] = ['r' => 'a', 't' => $cevap];
      self::save($s);
      return $cevap;
    }

    // niyet: degisiklik → taslağı güncelle, kodla doğrula
    $t = self::taslakBirlestir($s['taslak'], $o);
    $s['taslak'] = $t; $s['bekliyor'] = true;
    [$eksik, $hata] = self::dogrula($t);
    if ($hata || $eksik) {
      $s['hazir'] = false;
      $out = $cevap;
      if ($hata) $out = '⚠️ ' . implode("\n⚠️ ", $hata) . ($out !== '' ? "\n\n$out" : '');
      elseif ($out === '') $out = 'Şu bilgiler eksik: ' . implode(', ', $eksik) . '. Yazar mısınız?';
      $s['konusma'][] = ['r' => 'a', 't' => $out];
      self::save($s);
      return $out;
    }
    $s['hazir'] = true;
    $ozet = self::onizleme($t);
    $s['konusma'][] = ['r' => 'a', 't' => $ozet];
    self::save($s);
    return $ozet;
  }

  /* ---------- yapay zekâ: mesajı anla ---------- */
  private static function anla(string $text, array $s): array {
    if (Asistan::overBudget()) throw new RuntimeException('aylık yapay zekâ bütçesi doldu');
    $ev = [];
    foreach (Store::read('events', []) as $e) $ev[] = ['id' => $e['id'], 'baslik' => $e['title'], 'baslangic' => $e['start'], 'sure_dk' => $e['duration'],
      'tur' => $e['type'], 'yer' => $e['location'], 'kontenjan' => $e['capacity'], 'ucret' => $e['price'], 'aciklama' => $e['description'] ?? '', 'ornek' => !empty($e['ornek'])];
    $bugun = date('Y-m-d') . ' ' . self::GUNLER[(int)date('w')] . ', saat ' . date('H:i');
    $sys = "Aynur Tümen'in web sitesindeki etkinlik takvimini WhatsApp üzerinden yöneten yardımcısın. Mesajı yazan kişi ekipten yetkili biri. "
      . "Görevin mesajı anlamak ve sonucu 'takvim' aracıyla bildirmek. Takvimde hiçbir şeyi kendin değiştirmezsin; değişiklik ancak kişi sonradan 'evet' diyerek onaylarsa yapılır.\n"
      . "Bugün: $bugun (Türkiye saati).\n\n"
      . "NİYET:\n"
      . "- degil: Mesaj takvimi değiştirmekle ilgili değil (sohbet, soru, test, müşteri gibi yazma, başka bir iş). Şüphedeysen ve kişi değişiklik istediğini söylemiyorsa 'belirsiz' seç.\n"
      . "- belirsiz: Etkinlik/takvim geçiyor ama değişiklik isteyip istemediği net değil. 'cevap' alanında kibarca ne yapmak istediğini sor (ekleme mi, değişiklik mi, silme mi).\n"
      . "- degisiklik: Açıkça ekleme, güncelleme (saat, tarih, ücret, yer, kontenjan, açıklama vb.) ya da silme istiyor. Açık bir taslak varsa ve kişi ona bilgi ekliyor/düzeltiyorsa da degisiklik.\n"
      . "- liste: Mevcut etkinlikleri ya da tek bir etkinliğin ayrıntısını görmek istiyor (tek etkinlikse hedef_id ver).\n"
      . "- iptal: Vazgeçtiğini söylüyor.\n\n"
      . "KURALLAR:\n"
      . "1. Yalnızca kişinin yazdığını kaydet; hiçbir alanı tahminle doldurma. Bilmediğin alanı boş bırak.\n"
      . "2. Güncelleme ve silmede hedef etkinliği aşağıdaki listeden 'hedef_id' ile seç. Birden fazla etkinlik uyabiliyorsa ya da hiçbiri uymuyorsa hedef_id'yi boş bırak ve 'cevap' alanında hangisini kastettiğini seçeneklerle (tarihleriyle) sor.\n"
      . "3. Yeni etkinlik için gereken alanlar: başlık, tarih, saat, tür (online / yuzyuze / kamp). Ayrıca süre, ücret, kontenjan ve yer (yüz yüze/kampsa şehir ya da adres) sorulmalı. Eksikleri 'cevap' alanında tek mesajda, kısa ve madde madde sor; uygun olduğunda öneri sun (örneğin benzer etkinliklerin süresi ve ücreti, 'online ise süre genelde 90 dk' gibi). Önerin kişi kabul etmeden alana yazılmaz.\n"
      . "4. Tarih ve saati kişinin yazdığı şekilde çöz: 'yarın', 'cumartesi', '14'ü' gibi ifadeleri bugüne göre YYYY-MM-DD'ye çevir. Yıl verilmemişse en yakın gelecek tarihi seç. Saati HH:MM yaz.\n"
      . "4b. Kişi hem gün adı hem tarih yazdıysa ve bunlar uyuşmuyorsa (örneğin 20 Aralık 2026 Pazar'a denk gelirken 'cumartesi' demişse) tarihi alanlara yazma; 'cevap' alanında hangisinin doğru olduğunu sor.\n"
      . "5. Ücret TL olarak tam sayı; 'ücretsiz' ise 0. Süre dakika olarak (2 saat = 120, tam gün kamp için gün sayısı × 1440).\n"
      . "6. AÇIKLAMA: Yeni etkinlikte açıklama zorunlu sayılır. Kişi açıklama yazmadıysa, diğer bilgiler tamamlandığında (ya da eksikleri sorarken) başlığa, türe ve mevcut etkinliklerin üslubuna uygun 1-2 cümlelik sıcak bir Türkçe açıklama öner; 'cevap' içinde tırnak içinde göster ve 'Bunu kullanayım mı, yoksa kendiniz mi yazmak istersiniz?' diye sor. Öneriyi 'aciklama' alanına YALNIZCA kişi kabul ederse (evet, olur, bunu kullan, güzel vb.) yaz; kişi değiştirirse onun metnini yaz. Kişi açıklama istemediğini söylerse 'aciklama_yok': true ver. Mevcut bir etkinliğin açıklamasını değiştirmek ya da güzelleştirmek isterse de aynı şekilde öneri sun ve onay bekle. Açıklamada fiyat, tarih ya da söz uydurma.\n"
      . "7. 'cevap' alanı Türkçe, sıcak ve kısa olsun. Değişikliğin uygulandığını asla söyleme; onay özetini sistem kendisi gösterecek.\n\n"
      . "MEVCUT ETKİNLİKLER (JSON; 'ornek': true olanlar sitedeki örnek/deneme etkinlikleridir):\n" . json_encode($ev, JSON_UNESCAPED_UNICODE) . "\n\n"
      . "AÇIK TASLAK (kişiyle üzerinde çalışılan, henüz uygulanmamış değişiklik): " . ($s['taslak'] ? json_encode($s['taslak'], JSON_UNESCAPED_UNICODE) : 'yok');
    $alan = ['type' => 'object', 'properties' => [
      'baslik' => ['type' => 'string'], 'tarih' => ['type' => 'string', 'description' => 'YYYY-MM-DD'], 'saat' => ['type' => 'string', 'description' => 'HH:MM'],
      'sure_dk' => ['type' => 'integer'], 'tur' => ['type' => 'string', 'enum' => ['online', 'yuzyuze', 'kamp']], 'yer' => ['type' => 'string'],
      'kontenjan' => ['type' => 'integer'], 'ucret' => ['type' => 'integer'], 'aciklama' => ['type' => 'string'], 'aciklama_yok' => ['type' => 'boolean', 'description' => 'Kişi açıklama istemiyorsa true'], 'kayit_acik' => ['type' => 'boolean']]];
    $tool = ['name' => 'takvim', 'description' => 'Mesajın takvimle ilgili anlamını bildirir.', 'input_schema' => ['type' => 'object', 'required' => ['niyet'], 'properties' => [
      'niyet' => ['type' => 'string', 'enum' => ['degil', 'belirsiz', 'degisiklik', 'liste', 'iptal']],
      'islem' => ['type' => 'string', 'enum' => ['ekle', 'guncelle', 'sil']],
      'hedef_id' => ['type' => 'string', 'description' => 'Güncellenecek/silinecek etkinliğin id değeri'],
      'alanlar' => $alan + ['description' => 'Yalnızca kişinin bu mesajda verdiği ya da açıkça onayladığı değerler'],
      'cevap' => ['type' => 'string', 'description' => 'Eksik bilgi sorusu, öneri ya da netleştirme sorusu (Türkçe)']]]];
    $msgs = [];
    foreach ($s['konusma'] as $k) $msgs[] = ['role' => $k['r'] === 'u' ? 'user' : 'assistant', 'content' => $k['t']];
    $msgs[] = ['role' => 'user', 'content' => mb_substr($text, 0, 1500)];
    // ardışık aynı rol olmasın
    $clean = [];
    foreach ($msgs as $m) { if ($clean && end($clean)['role'] === $m['role']) $clean[count($clean) - 1]['content'] .= "\n" . $m['content']; else $clean[] = $m; }
    if ($clean[0]['role'] !== 'user') array_shift($clean);
    $content = Asistan::api(['model' => env('ASISTAN_MODEL') ?: 'claude-haiku-5-5', 'max_tokens' => 1200, 'system' => $sys,
      'tools' => [$tool], 'tool_choice' => ['type' => 'tool', 'name' => 'takvim'], 'messages' => $clean]);
    foreach ($content as $p) if (($p['type'] ?? '') === 'tool_use' && is_array($p['input'] ?? null)) return $p['input'];
    throw new RuntimeException('yapay zekâ yapılandırılmış cevap vermedi');
  }

  /* ---------- taslak ve doğrulama (kod) ---------- */
  private static function taslakBirlestir(?array $t, array $o): array {
    $t = $t ?: ['islem' => '', 'hedef_id' => '', 'alanlar' => []];
    if (!empty($o['islem'])) {
      if ($t['islem'] !== '' && $t['islem'] !== $o['islem']) $t = ['islem' => '', 'hedef_id' => '', 'alanlar' => []]; // işlem türü değişti: baştan
      $t['islem'] = $o['islem'];
    }
    if (!empty($o['hedef_id'])) $t['hedef_id'] = (string)$o['hedef_id'];
    foreach ((array)($o['alanlar'] ?? []) as $k => $v) {
      if ($v === null || $v === '') continue;
      $t['alanlar'][$k] = is_string($v) ? trim($v) : $v;
    }
    return $t;
  }

  private static function bul(string $id): ?array { foreach (Store::read('events', []) as $e) if ($e['id'] === $id) return $e; return null; }

  /** @return array{0: string[], 1: string[]} [eksikler, hatalar] */
  private static function dogrula(array $t): array {
    $eksik = []; $hata = []; $a = $t['alanlar'] ?? [];
    $islem = $t['islem'] ?? '';
    if (!in_array($islem, ['ekle', 'guncelle', 'sil'], true)) return [['yapılacak işlem (ekleme, değişiklik ya da silme)'], []];
    $hedef = null;
    if ($islem !== 'ekle') {
      if (($t['hedef_id'] ?? '') === '') return [['hangi etkinlik olduğu'], []];
      $hedef = self::bul($t['hedef_id']);
      if (!$hedef) return [[], ['Bu etkinliği takvimde bulamadım. "etkinlikler" yazarak listeyi görebilirsiniz.']];
      if ($islem === 'sil') return [[], []];
      $degisen = array_filter($a, fn($v) => $v !== null && $v !== '');
      if (!$degisen) return [['neyin değişeceği (saat, tarih, ücret vb.)'], []];
    }
    if ($islem === 'ekle') {
      foreach (['baslik' => 'başlık', 'tarih' => 'tarih', 'saat' => 'saat', 'tur' => 'tür (online / yüz yüze / kamp)', 'sure_dk' => 'süre', 'ucret' => 'ücret (ücretsizse "ücretsiz")'] as $k => $ad)
        if (!isset($a[$k]) || $a[$k] === '') $eksik[] = $ad;
      if (in_array($a['tur'] ?? '', ['yuzyuze', 'kamp'], true) && trim((string)($a['yer'] ?? '')) === '') $eksik[] = 'yer (şehir ya da adres)';
      if (trim((string)($a['aciklama'] ?? '')) === '' && empty($a['aciklama_yok'])) $eksik[] = 'açıklama';
    }
    if (isset($a['tarih']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$a['tarih'])) $hata[] = 'Tarih anlaşılamadı.';
    if (isset($a['saat']) && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$a['saat'])) $hata[] = 'Saat anlaşılamadı (ör. 14:00).';
    if (isset($a['tur']) && !isset(self::TURLER[$a['tur']])) $hata[] = 'Tür online, yüz yüze ya da kamp olmalı.';
    if (isset($a['sure_dk']) && ((int)$a['sure_dk'] < 15 || (int)$a['sure_dk'] > 20160)) $hata[] = 'Süre anlaşılamadı.';
    if (isset($a['ucret']) && ((int)$a['ucret'] < 0 || (int)$a['ucret'] > 1000000)) $hata[] = 'Ücret anlaşılamadı.';
    if (isset($a['kontenjan']) && ((int)$a['kontenjan'] < 1 || (int)$a['kontenjan'] > 2000)) $hata[] = 'Kontenjan anlaşılamadı.';
    if (isset($a['baslik']) && mb_strlen((string)$a['baslik']) > 120) $hata[] = 'Başlık çok uzun.';
    if (!$hata) {
      $tarih = $a['tarih'] ?? ($hedef ? substr($hedef['start'], 0, 10) : null);
      $saat = $a['saat'] ?? ($hedef ? substr($hedef['start'], 11, 5) : null);
      if ($tarih && $saat && strtotime("{$tarih}T{$saat}:00+03:00") < time()) $hata[] = 'Bu tarih ve saat geçmişte kalıyor.';
      if ($tarih && !checkdate((int)substr($tarih, 5, 2), (int)substr($tarih, 8, 2), (int)substr($tarih, 0, 4))) $hata[] = 'Böyle bir tarih yok.';
    }
    return [$eksik, $hata];
  }

  /* ---------- gösterim ---------- */
  private static function tarihYaz(string $iso): string {
    $ts = strtotime($iso);
    return (int)date('j', $ts) . ' ' . self::AYLAR[(int)date('n', $ts)] . ' ' . date('Y', $ts) . ' ' . self::GUNLER[(int)date('w', $ts)] . ', ' . date('H:i', $ts);
  }
  private static function sure(int $dk): string {
    if ($dk >= 1440 && $dk % 1440 === 0) return ($dk / 1440) . ' gün';
    return $dk >= 60 ? (intdiv($dk, 60) . ' saat' . ($dk % 60 ? ' ' . ($dk % 60) . ' dk' : '')) : "$dk dk";
  }
  private static function ucret(int $tl): string { return $tl === 0 ? 'Ücretsiz' : number_format($tl, 0, ',', '.') . ' TL'; }
  private static function satirlar(array $e): array {
    return ['📌 ' . $e['title'], '🗓 ' . self::tarihYaz($e['start']), '⏱ ' . self::sure((int)$e['duration']), '🏷 ' . (self::TURLER[$e['type']] ?? $e['type']),
      '📍 ' . ($e['location'] ?: '—'), '👥 Kontenjan: ' . $e['capacity'], '💳 ' . self::ucret((int)$e['price'])]
      + (($e['registration'] ?? true) === false ? [7 => '🔕 Sitede kayıt düğmesi yok'] : [])
      + (trim((string)$e['description']) !== '' ? [8 => '📝 ' . $e['description']] : []);
  }

  // Taslaktan uygulanacak son etkinlik kaydını üretir (önizleme ve uygulama aynı fonksiyonu kullanır)
  private static function sonuc(array $t): ?array {
    $a = $t['alanlar'] ?? [];
    if ($t['islem'] === 'sil') return null;
    $e = $t['islem'] === 'guncelle' ? self::bul($t['hedef_id']) : [
      'id' => '', 'title' => '', 'type' => 'online', 'start' => '', 'duration' => 90, 'location' => '', 'capacity' => (int)(Store::read('settings', [])['eventDefaultCapacity'] ?? 30),
      'price' => 0, 'description' => ''];
    if (isset($a['baslik'])) $e['title'] = (string)$a['baslik'];
    if (isset($a['tur'])) $e['type'] = (string)$a['tur'];
    if (isset($a['tarih']) || isset($a['saat'])) {
      $tarih = $a['tarih'] ?? substr($e['start'], 0, 10); $saat = $a['saat'] ?? substr($e['start'], 11, 5);
      $e['start'] = "{$tarih}T{$saat}:00+03:00";
    }
    if (isset($a['sure_dk'])) $e['duration'] = (int)$a['sure_dk'];
    if (isset($a['yer'])) $e['location'] = (string)$a['yer'];
    if ($e['location'] === '' && $e['type'] === 'online') $e['location'] = 'Online, canlı';
    if (isset($a['kontenjan'])) $e['capacity'] = (int)$a['kontenjan'];
    if (isset($a['ucret'])) $e['price'] = (int)$a['ucret'];
    if (isset($a['aciklama'])) $e['description'] = (string)$a['aciklama'];
    unset($e['aciklama_yok']);
    if (isset($a['kayit_acik'])) $e['registration'] = (bool)$a['kayit_acik'];
    unset($e['ornek']);
    return $e;
  }

  private static function onizleme(array $t): string {
    if ($t['islem'] === 'sil') {
      $e = self::bul($t['hedef_id']);
      return "🗑 Şu etkinliği sitedeki takvimden SİLECEĞİM:\n\n" . implode("\n", self::satirlar($e)) . "\n\nOnaylıyor musunuz? (evet / hayır)";
    }
    $yeni = self::sonuc($t);
    if ($t['islem'] === 'ekle') {
      $yok = trim((string)$yeni['description']) === '' ? "\n\n(Açıklama olmadan eklenecek.)" : '';
      return "➕ Sitedeki takvime şu etkinliği EKLEYECEĞİM:\n\n" . implode("\n", self::satirlar($yeni)) . $yok
        . "\n\nBaşlık ve açıklama İngilizce, Rusça ve Almancaya otomatik çevrilecek.\nOnaylıyor musunuz? (evet / hayır)";
    }
    $eski = self::bul($t['hedef_id']);
    $fark = [];
    $alanAd = ['title' => 'Başlık', 'start' => 'Tarih/saat', 'duration' => 'Süre', 'type' => 'Tür', 'location' => 'Yer', 'capacity' => 'Kontenjan', 'price' => 'Ücret', 'description' => 'Açıklama', 'registration' => 'Kayıt düğmesi'];
    $yaz = fn($k, $v) => match ($k) { 'start' => self::tarihYaz($v), 'duration' => self::sure((int)$v), 'type' => self::TURLER[$v] ?? $v, 'price' => self::ucret((int)$v),
      'registration' => ($v === false ? 'kapalı' : 'açık'), default => (string)$v };
    foreach ($alanAd as $k => $ad) {
      $o = $eski[$k] ?? ($k === 'registration' ? true : ''); $n = $yeni[$k] ?? ($k === 'registration' ? true : '');
      if ($o != $n) $fark[] = "• $ad: " . $yaz($k, $o) . ' → ' . $yaz($k, $n);
    }
    if (!$fark) return 'Bu bilgiler zaten takvimdekiyle aynı görünüyor; değişecek bir şey bulamadım.';
    return "✏️ \"{$eski['title']}\" etkinliğinde şunu DEĞİŞTİRECEĞİM:\n\n" . implode("\n", $fark) . "\n\nOnaylıyor musunuz? (evet / hayır)";
  }

  static function liste(): string {
    $now = time();
    $l = array_values(array_filter(Store::read('events', []), fn($e) => strtotime($e['start']) > $now));
    usort($l, fn($a, $b) => strtotime($a['start']) <=> strtotime($b['start']));
    if (!$l) return 'Sitede yaklaşan etkinlik yok. Eklemek için örneğin şöyle yazabilirsiniz: "20 Aralık Pazar 11:00, online grup aile dizimi ekle"';
    $parca = [];
    foreach ($l as $i => $e) {
      $sat = self::satirlar($e);
      $sat[0] = '*' . ($i + 1) . '. ' . $e['title'] . '*' . (!empty($e['ornek']) ? ' (örnek)' : '');
      $parca[] = implode("\n", $sat);
    }
    $out = "🗓 Sitedeki yaklaşan etkinlikler:\n\n" . implode("\n\n", $parca)
      . "\n\nDeğiştirmek istediğinizi normal cümleyle yazabilirsiniz (ör. \"Dolunay çalışmasının açıklamasını değiştir\"); her değişiklikten önce onayınızı alırım.";
    if (mb_strlen($out) > 3900) { // WhatsApp mesaj sınırı: açıklamaları kısalt
      $parca = [];
      foreach ($l as $i => $e) {
        $d = trim((string)$e['description']);
        $parca[] = '*' . ($i + 1) . '. ' . $e['title'] . '*' . (!empty($e['ornek']) ? ' (örnek)' : '') . "\n🗓 " . self::tarihYaz($e['start']) . ' · ' . (self::TURLER[$e['type']] ?? $e['type']) . ' · ' . self::ucret((int)$e['price'])
          . ($d !== '' ? "\n📝 " . (mb_strlen($d) > 140 ? mb_substr($d, 0, 137) . '…' : $d) : '');
      }
      $out = "🗓 Sitedeki yaklaşan etkinlikler:\n\n" . implode("\n\n", $parca) . "\n\nBir etkinliğin tüm ayrıntısı için adını yazıp \"ayrıntı\" demeniz yeterli.";
      if (mb_strlen($out) > 3900) $out = mb_substr($out, 0, 3880) . '…';
    }
    return $out;
  }


  /* ---------- uygulama ---------- */
  private static function yedekle(): string {
    $dir = DATA_DIR . '/yedek'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $f = $dir . '/' . date('Ymd-His') . '-etkinlik-wa-events.json';
    $src = DATA_DIR . '/events.json';
    if (!copy($src, $f)) throw new RuntimeException('yedek alınamadı');
    return $f;
  }

  private static function uygula(array $t, array &$s): string {
    [$eksik, $hata] = self::dogrula($t); // onay anında tekrar kontrol (bu arada takvim değişmiş olabilir)
    if ($eksik || $hata) throw new RuntimeException(implode(' ', $hata ?: ['eksik bilgi: ' . implode(', ', $eksik)]));
    $yedek = self::yedekle();
    $site = rtrim(env('SITE_URL') ?: 'https://www.aynurtumen.com.tr', '/');
    if ($t['islem'] === 'sil') {
      $e = self::bul($t['hedef_id']);
      Store::update('events', [], fn($l) => array_values(array_filter($l, fn($x) => $x['id'] !== $t['hedef_id'])));
      $s['sonYedek'] = $yedek; $s['sonIslem'] = 'silme: ' . $e['title'];
      return "✅ \"{$e['title']}\" sitedeki takvimden silindi.\nYanlışlıkla olduysa \"geri al\" yazmanız yeterli.";
    }
    $yeni = self::sonuc($t);
    if ($t['islem'] === 'ekle') {
      $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtr(tr_lower($yeni['title']), ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u'])), '-');
      $yeni['id'] = substr($slug ?: 'etkinlik', 0, 40) . '-' . rand_hex(2);
      Store::update('events', [], function ($l) use ($yeni) { $l[] = $yeni; return $l; });
    } else {
      Store::update('events', [], fn($l) => array_map(fn($x) => $x['id'] === $yeni['id'] ? $yeni : $x, $l));
    }
    $kayitli = self::bul($yeni['id']);
    if (!$kayitli || $kayitli['start'] !== $yeni['start'] || $kayitli['title'] !== $yeni['title']) throw new RuntimeException('kayıt doğrulanamadı');
    $s['sonYedek'] = $yedek; $s['sonIslem'] = ($t['islem'] === 'ekle' ? 'ekleme: ' : 'değişiklik: ') . $yeni['title'];
    $cev = self::cevir($yeni);
    return ($t['islem'] === 'ekle' ? '✅ Etkinlik sitedeki takvime eklendi.' : '✅ Değişiklik sitedeki takvime işlendi.')
      . "\n$site/etkinlikler" . ($cev ? '' : "\n(Çeviri şu an yapılamadı; diğer dillerde Türkçe görünecek, sonra tekrar denenecek.)")
      . "\nYanlışlıkla olduysa \"geri al\" yazmanız yeterli.";
  }

  // Başlık, açıklama ve yeri İngilizce/Rusça/Almancaya çevirip otomatik sözlüğe yazar
  private static function cevir(array $e): bool {
    $metin = array_values(array_unique(array_filter([I18n::norm($e['title']), I18n::norm($e['description']), I18n::norm($e['location'])], fn($x) => mb_strlen($x) >= 2)));
    if (!$metin) return true;
    try {
      $prop = []; foreach (['en', 'ru', 'de'] as $l) $prop[$l] = ['type' => 'array', 'items' => ['type' => 'string'], 'description' => "Metinlerin aynı sırayla $l çevirisi"];
      $content = Asistan::api(['model' => env('ASISTAN_MODEL') ?: 'claude-haiku-5-5', 'max_tokens' => 2000,
        'system' => 'Aile dizimi (konstelasyon) alanında çalışan Aynur Tümen\'in web sitesindeki etkinlik metinlerini çevir. Sıcak, doğal ve resmî hitapla (Almancada Sie, Rusçada Вы) çevir. Kişi ve yer adlarını değiştirme. "Online, canlı" gibi kısa ifadeleri de çevir.',
        'tools' => [['name' => 'ceviri', 'description' => 'Çevirileri kaydeder', 'input_schema' => ['type' => 'object', 'required' => ['en', 'ru', 'de'], 'properties' => $prop]]],
        'tool_choice' => ['type' => 'tool', 'name' => 'ceviri'],
        'messages' => [['role' => 'user', 'content' => "Şu Türkçe metinleri çevir (JSON dizi):\n" . json_encode($metin, JSON_UNESCAPED_UNICODE)]]]);
      $out = null; foreach ($content as $p) if (($p['type'] ?? '') === 'tool_use') $out = $p['input'];
      if (!$out) throw new RuntimeException('çeviri gelmedi');
      @mkdir(LOCALE_DIR . '/otomatik', 0775, true);
      foreach (['en', 'ru', 'de'] as $l) {
        $tr = $out[$l] ?? [];
        if (count($tr) !== count($metin)) throw new RuntimeException("$l çevirisi eksik");
        $file = LOCALE_DIR . "/otomatik/$l.json";
        $auto = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
        foreach ($metin as $i => $src) if (is_string($tr[$i]) && trim($tr[$i]) !== '') $auto[$src] = trim($tr[$i]);
        file_put_contents($file, json_encode($auto, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
      }
      return true;
    } catch (Throwable $x) {
      error_log('[etkinlik çeviri] ' . $x->getMessage());
      if (class_exists('Translate')) Translate::request($metin); // DeepL ayarlıysa o dener
      return false;
    }
  }

  private static function geriAl(array $s): string {
    $f = $s['sonYedek'] ?? null;
    if (!$f || !is_file($f)) return 'Geri alınacak yakın tarihli bir değişiklik bulamadım.';
    $j = json_decode((string)file_get_contents($f), true);
    if (!is_array($j)) return '⚠️ Yedek okunamadı, takvim olduğu gibi duruyor.';
    self::yedekle(); // geri almadan önceki hâli de sakla
    Store::write('events', $j);
    $ne = $s['sonIslem'] ?: 'son değişiklik';
    $s['sonYedek'] = null; $s['sonIslem'] = '';
    self::save(self::reset($s));
    return "↩️ Geri alındı ($ne). Takvim değişiklikten önceki hâline döndü.";
  }
}
