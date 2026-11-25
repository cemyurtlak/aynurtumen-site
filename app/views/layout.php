<?php
// Sayfa iskeleti: başlık, menü, alt bilgi, paylaşım etiketleri
function asset_version(): string {
  static $v = null;
  if ($v !== null) return $v;
  $m = 0;
  foreach (['/css/site.css', '/js/site.js', '/js/booking.js', '/js/i18n.js', '/js/room.js'] as $f) $m = max($m, (int)@filemtime(PUBLIC_DIR . $f));
  return $v = base_convert((string)$m, 10, 36); // dosya değişince tarayıcı yeni sürümü alır
}

const NAV = [
  ['/', 'Ana sayfa'], ['/aile-dizimi', 'Aile dizimi'], ['/hizmetler', 'Çalışmalar'], ['/etkinlikler', 'Etkinlikler'],
  ['/videolar', 'Videolar'], ['/kitap', 'Kitapları'], ['/yazilar', 'Yazılar'], ['/hakkinda', 'Hakkında'], ['/iletisim', 'İletişim'],
];
const GLOBE = '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm-8.5 9h17M12 3c2.4 2.5 3.6 5.5 3.6 9s-1.2 6.5-3.6 9c-2.4-2.5-3.6-5.5-3.6-9S9.6 5.5 12 3Z"/></svg>';

// Menüde yer darsa kısa etiket: sözlükte "menü: Aile dizimi" gibi bir kayıt varsa o kullanılır
function navLabel(string $label): string { return I18n::lang() === 'tr' ? $label : (I18n::exact('menü: ' . $label) ?? t($label)); }

function layout(array $o): string {
  $_ = fn($v) => $v;
  $title = $o['title'] ?? null; $description = $o['description'] ?? null; $path = $o['path'] ?? '/'; $body = $o['body'];
  $head = $o['head'] ?? ''; $scripts = $o['scripts'] ?? ''; $bodyClass = $o['bodyClass'] ?? ''; $client = $o['client'] ?? false;
  $V = asset_version();
  $L = I18n::lang();
  $s = settings();
  $site = rtrim(env('SITE_URL') ?: I18n::$base, '/');
  $wa = waLink($s['phoneIntl'], 'Merhaba, web sitenizden yazıyorum. Aile dizimi çalışmaları hakkında bilgi almak istiyorum.');
  $fullTitle = $title ? t($title) . ' | Aynur Tümen' : 'Aynur Tümen | ' . t('Aile Dizimi ve Konstelasyon Çalışmaları');
  $desc = t($description ?: 'Aynur Tümen ile bireysel ve grup aile dizimi (konstelasyon) çalışmaları, online randevu, etkinlikler ve kamplar.');
  $self = $o['canonical'] ?? $path;
  // langs: içeriğin bulunduğu diller (yazılar: Türkçe + çevrilmiş olanlar). Olmayan dildeki kopyası dizine girmez.
  $langs = $o['langs'] ?? (!empty($o['trOnly']) ? ['tr'] : I18n::LANGS);
  $alt = count($langs) < 2 ? '' : join_map($langs, fn($l) => '<link rel="alternate" hreflang="' . $l . '" href="@@' . esc($site . I18n::prefix($self, $l)) . '">') . '<link rel="alternate" hreflang="x-default" href="@@' . esc($site . $self) . '">';
  $robots = !empty($o['noindex']) || !in_array($L, $langs, true) ? '<meta name="robots" content="noindex, follow">' : '';
  $langMenu = '<div class="lang" data-lang><button class="lang-btn" type="button" aria-expanded="false" aria-label="Dil / Language">' . GLOBE . '<span>' . strtoupper($L) . '</span></button><div class="lang-menu">'
    . join_map(I18n::LANGS, fn($l) => '<a href="@@' . esc(I18n::prefix($self, $l)) . '" hreflang="' . $l . '" lang="' . $l . '"' . ($l === $L ? ' aria-current="true"' : '') . '><b>' . strtoupper($l) . '</b>' . I18n::NAMES[$l] . '</a>') . '</div></div>';
  $social = $s['social'] ?? [];
  $nav = join_map(NAV, function ($n) use ($path) {
    [$href, $tr] = $n;
    $cur = $href === '/' ? $path === '/' : ($path === $href || str_starts_with($path, $href . '/') || ($href === '/hakkinda' && $path === '/galeri'));
    return '<a href="' . $href . '"' . ($cur ? ' aria-current="page"' : '') . '>' . navLabel($tr) . '</a>';
  });
  $ogAlt = join_map(array_filter(I18n::LANGS, fn($l) => $l !== $L), fn($l) => '<meta property="og:locale:alternate" content="' . I18n::OG[$l] . '">');
  $socialLi = fn($k, $label) => !empty($social[$k]) ? '<li><a href="' . esc($social[$k]) . '" target="_blank" rel="noopener">' . $label . '</a></li>' : '';
  $year = date('Y');
  // Sohbet asistanı (sağ altta). Metinler burada Türkçe; sayfa diline translateHtml çevirir.
  $chat = chat_on() && empty($o['noChat']) ? '<div class="chat" data-chat>'
    . '<button class="chat-fab" type="button" data-chat-open aria-label="Bizimle iletişime geçin" aria-expanded="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v8a2.5 2.5 0 0 1-2.5 2.5H10l-4.2 3.6a.5.5 0 0 1-.8-.4V16h-.5A2.5 2.5 0 0 1 2 13.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><circle cx="8" cy="9.5" r="1.1" fill="currentColor"/><circle cx="12" cy="9.5" r="1.1" fill="currentColor"/><circle cx="16" cy="9.5" r="1.1" fill="currentColor"/></svg><span>Bizimle iletişime geçin</span></button>'
    . '<section class="chat-panel" data-chat-panel role="dialog" aria-label="Sohbet" hidden>'
    . '<header><div><b>Aynur Tümen ekibi</b></div><button type="button" class="chat-x" data-chat-close aria-label="Kapat">×</button></header>'
    . '<div class="chat-log" data-chat-log aria-live="polite"><p class="msg bot" data-chat-hello>Merhaba, hoş geldiniz 🌸 Size nasıl yardımcı olabiliriz? Aklınızdan geçen her şeyi yazabilirsiniz.</p></div>'
    . '<form class="chat-form" data-chat-form><label class="sr-only" for="chat-in">Mesajınız</label><textarea id="chat-in" rows="1" maxlength="1500" placeholder="Mesajınızı yazın…" required></textarea><button type="submit" aria-label="Gönder"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12 20 4l-6 16-2.5-6.5L4 12Z" fill="currentColor"/></svg></button></form>'
    . '<p class="chat-note"><a href="/yasal#kvkk">KVKK</a></p>'
    . '<span hidden data-chat-err>Bağlantı kurulamadı. Lütfen biraz sonra tekrar deneyin ya da WhatsApp’tan yazın.</span>'
    . '</section></div>' : '';
  $clientJs = $client && $L !== 'tr' ? '<script>window.I18N=' . str_replace('<', '\\u003c', js_json((object)I18n::clientDict($L))) . '</script><script src="/js/i18n.js?v=' . $V . '" defer></script>' : '';
  $canon = esc($site . I18n::prefix($self, $L));
  $img = esc("$site/images/paylasim-$L.jpg?v=3");
  // WhatsApp bağlantı önizlemesinde küçük kare kesit gösterir: ona kare görsel ver
  $wa = stripos((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 'whatsapp') !== false;
  $ogImg = $wa ? esc("$site/images/paylasim-kare.jpg?v=1") : $img;
  $ogW = $wa ? 600 : 1200; $ogH = $wa ? 600 : 630;
  return <<<HTML
<!doctype html>
<html lang="$L">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, interactive-widget=resizes-content">
<title>{$_(esc($fullTitle))}</title>
<meta name="description" content="{$_(esc($desc))}">
<link rel="canonical" href="@@$canon">
$robots$alt
<meta property="og:title" content="{$_(esc($fullTitle))}">
<meta property="og:description" content="{$_(esc($desc))}">
<meta property="og:type" content="website">
<meta property="og:locale" content="{$_(I18n::OG[$L])}">
$ogAlt
<meta property="og:site_name" content="Aynur Tümen">
<meta property="og:url" content="$canon">
<meta property="og:image" content="$ogImg">
<meta property="og:image:width" content="$ogW">
<meta property="og:image:height" content="$ogH">
<meta property="og:image:alt" content="{$_(esc('Aynur Tümen · ' . t('Aile Dizimi ve Konstelasyon Çalışmaları')))}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{$_(esc($fullTitle))}">
<meta name="twitter:description" content="{$_(esc($desc))}">
<meta name="twitter:image" content="$img">
<meta name="theme-color" content="#1D1240">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/icon-180.png">
<link rel="manifest" href="/site.webmanifest">
<link rel="preload" href="/fonts/fraunces-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/css/site.css?v=$V">
$head
</head>
<body class="$bodyClass">
<a class="skip" href="#icerik">İçeriğe geç</a>
<header class="topbar" data-topbar>
  <div class="wrap topbar-in">
    <a class="logo" href="/" aria-label="Aynur Tümen ana sayfa"><span class="logo-name">Aynur Tümen</span><span class="logo-sub">Aile Dizimi &amp; Konstelasyon</span></a>
    <nav class="nav" id="nav" aria-label="Ana menü">
      $nav
      $langMenu
      <a class="btn btn-gold nav-cta" href="/randevu">Randevu al</a>
    </nav>
    <button class="burger" aria-expanded="false" aria-controls="nav" aria-label="Menüyü aç"><span></span><span></span></button>
  </div>
</header>
<main id="icerik">
$body
</main>
<footer class="footer">
  <div class="aurora aurora-soft" aria-hidden="true"><i></i><i></i><i></i></div>
  <div class="wrap footer-grid">
    <div class="footer-brand">
      <a class="logo logo-light" href="/"><span class="logo-name">Aynur Tümen</span><span class="logo-sub">Aile Dizimi &amp; Konstelasyon</span></a>
      <p>{$_(esc(t($s['roles'])))}. {$_(esc(t("2004'ten beri bireysel ve grup çalışmaları.")))}</p>
      <a class="btn btn-wa" href="$wa" target="_blank" rel="noopener">{$_(WA_ICON)} {$_(esc($s['phoneDisplay']))}</a>
    </div>
    <div><h3>Çalışmalar</h3><ul>
      <li><a href="/aile-dizimi">Aile dizimi nedir?</a></li>
      <li><a href="/hizmetler">Bireysel seanslar</a></li>
      <li><a href="/etkinlikler">Grup çalışmaları ve kamplar</a></li>
      <li><a href="/hizmetler#akademi">Akademi</a></li>
      <li><a href="/randevu">Online randevu</a></li></ul></div>
    <div><h3>Keşfet</h3><ul>
      <li><a href="/videolar">Videolar</a></li>
      <li><a href="/kitap">Kitapları</a></li>
      <li><a href="/yazilar">Yazılar</a></li>
      <li><a href="/hakkinda">Hakkında ve basında</a></li>
      <li><a href="/galeri">Fotoğraf galerisi</a></li>
      <li><a href="/iletisim">İletişim</a></li></ul></div>
    <div><h3>Takip edin</h3><ul class="social">
      {$_($socialLi('instagram', 'Instagram · 210 bin'))}
      {$_($socialLi('youtube', 'YouTube'))}
      {$_($socialLi('akademi', 'Instagram · Akademi'))}
      {$_($socialLi('tiktok', 'TikTok'))}
      {$_($socialLi('facebook', 'Facebook'))}
      {$_($socialLi('linkedin', 'LinkedIn'))}</ul></div>
  </div>
  <div class="wrap footer-bottom">
    <span>© $year Aynur Tümen</span>
    <span><a href="/yasal#kvkk">KVKK</a><a href="/yasal#cerez">Çerez politikası</a><a href="/yasal#iade">İptal ve erteleme</a></span>
  </div>
</footer>
{$_($chat)}
<div class="cookie" data-cookie hidden role="dialog" aria-label="Çerez tercihi">
  <p>Siteyi geliştirmek için çerezler kullanıyoruz. <a href="/yasal#cerez">Ayrıntılar</a></p>
  <div><button class="btn btn-gold btn-sm" data-cookie-ok="all">Kabul et</button><button class="btn btn-line-light btn-sm" data-cookie-ok="min">Yalnızca zorunlu</button></div>
</div>
$clientJs
<script src="/js/site.js?v=$V" defer></script>
{$_($chat ? '<script src="/js/sohbet.js?v=' . $V . '" defer></script>' : '')}
$scripts
</body>
</html>
HTML;
}
