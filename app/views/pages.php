<?php
// Sitenin sayfaları (Node sürümündeki views/pages.js ile aynı çıktı)

/* ---------- Ortak parçalar ---------- */
function books(): array { return Store::read('books', []); }

function cover(array $b, string $cls = ''): string {
  $img = image('kitaplar/' . $b['id']) ?? (!empty($b['featured']) ? image('kitap-kapak') : null);
  if ($img) return '<img class="cover-img ' . $cls . '" src="' . $img . '" alt="' . esc($b['title']) . ' kitap kapağı" loading="lazy">';
  return OFF . '<div class="cover-wrap ' . $cls . '"><div class="book-face" style="--c1:' . esc($b['colors'][0]) . ';--c2:' . esc($b['colors'][1]) . '"><span>' . (!empty($b['featured']) ? 'S. ' : '') . 'Aynur Tümen</span><b>' . esc($b['title']) . '</b>' . (!empty($b['subtitle']) ? '<em>' . esc($b['subtitle']) . '</em>' : '<em></em>') . '</div></div>' . ON;
}

function yt(array $v, array $opts = []): string {
  $_ = fn($x) => $x;
  $short = !empty($opts['short']) ? 'yt-short' : '';
  $views = '';
  if (!empty($v['views'])) $views = '<span>' . esc(is_int($v['views']) || is_float($v['views']) ? num_loc($v['views'], I18n::LOCALE[I18n::lang()]) : $v['views']) . ' izlenme</span>';
  $cap = ($opts['caption'] ?? true) === false ? '' : '<figcaption>' . OFF . '<span lang="tr">' . esc($v['title']) . '</span>' . ON . $views . '</figcaption>';
  return <<<HTML

  <figure class="yt $short reveal">
    {$_(OFF)}<div class="yt-frame"><iframe loading="lazy" src="https://www.youtube-nocookie.com/embed/{$_(esc($v['id']))}?rel=0&modestbranding=1" title="{$_(esc($v['title']))}" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe></div>{$_(ON)}
    $cap
  </figure>
HTML;
}

function igEmbed(string $url): string {
  return '<div class="ig reveal"><blockquote class="instagram-media" data-instgrm-permalink="' . esc($url) . '?utm_source=ig_embed" data-instgrm-version="14"><a href="' . esc($url) . '" target="_blank" rel="noopener">Instagram\'da görüntüle</a></blockquote></div>';
}

function eventCard(array $ev, array $o = []): string {
  $_ = fn($x) => $x;
  $d = ist($ev['start']);
  $left = Booking::seatsLeft($ev);
  $typeLabel = ['online' => 'Online', 'yuzyuze' => 'Yüz yüze', 'kamp' => 'Kamp'][$ev['type']] ?? $ev['type'];
  $reg = ($ev['registration'] ?? true) !== false;
  $desc = !empty($o['compact']) ? '' : '<p>' . esc($ev['description']) . '</p>';
  $seats = $reg && $left <= 0 ? '<div class="seats">Kontenjan doldu</div>' : '';
  $cta = (!$reg
    ? '<a class="btn btn-line btn-sm" href="' . wa('Merhaba, ' . $ev['title'] . ' hakkında bilgi almak istiyorum.') . '" target="_blank" rel="noopener">Bilgi al</a>'
    : ($left > 0 ? '<a class="btn btn-violet btn-sm" href="/randevu?etkinlik=' . esc($ev['id']) . '">' . 'Kayıt ol' . '</a>'
      : '<a class="btn btn-line btn-sm" href="' . wa('Merhaba, ' . $ev['title'] . ' için bekleme listesine yazılmak istiyorum.') . '" target="_blank" rel="noopener">Bekleme listesi</a>'));
  return <<<HTML

  <article class="event reveal" data-type="{$_(esc($ev['type']))}">
    <div class="event-date"><b>{$d['day']}</b><span>{$d['month']}</span><small>{$d['dow']}</small></div>
    <div class="event-body">
      <div class="event-tags"><span class="pill pill-{$_(esc($ev['type']))}">$typeLabel</span><span class="event-meta">{$d['hm']} · {$_(durLabel($ev['duration']))} · {$_(esc(t($ev['location'])))}</span></div>
      <h3>{$_(esc($ev['title']))}</h3>
      $desc
      $seats
    </div>
    <div class="event-cta">$cta</div>
  </article>
HTML;
}
function js_round(float $x): int { return (int)floor($x + 0.5); } // Math.round

function upcomingEvents(): array { return Events::upcoming(); }

function postCard(array $p, int $i = 0): string {
  $_ = fn($x) => $x;
  // Bu dilde çevirisi varsa çevrilmiş başlık/özet; yoksa Türkçe asıl
  $t = YaziCeviri::get($p, I18n::lang());
  $lang = $t ? I18n::lang() : 'tr';
  $cat = $t ? esc($p['category'] ?? '') : OFF . esc($p['category'] ?? '') . ON;
  $title = esc($t['title'] ?? $p['title']); $ex = esc($t['excerpt'] ?? ($p['excerpt'] ?? ''));
  return <<<HTML

  <article class="post reveal" style="--d:{$_($i * 60)}ms">
    <a href="/yazilar/{$_(esc($p['slug']))}" lang="$lang">
      <span class="post-cat">$cat</span>
      {$_(OFF)}<h3>$title</h3>
      <p>$ex</p>{$_(ON)}
      <span class="more">Yazıyı oku</span>
    </a>
  </article>
HTML;
}

function pageHead(string $title, string $lead = '', string $crumbs = ''): string {
  $c = $crumbs ? '<nav class="crumbs" aria-label="Konum"><a href="/">Ana sayfa</a><span>/</span>' . $crumbs . '</nav>' : '';
  $l = $lead ? '<p class="lead">' . $lead . '</p>' : '';
  return <<<HTML

  <section class="page-hero">
    <div class="aurora" aria-hidden="true"><i></i><i></i><i></i></div>
    <div class="wrap">
      $c
      <h1>$title</h1>
      $l
    </div>
  </section>
HTML;
}

function ctaBand(string $title = 'İlk adımı atmak için konunuzu bilmeniz gerekmiyor.', string $lead = 'Görüşme türünü seç, WhatsApp’tan yaz; nereden başlayacağımıza birlikte karar verelim.'): string {
  $_ = fn($x) => $x;
  return <<<HTML

  <section class="cta">
    <div class="aurora" aria-hidden="true"><i></i><i></i><i></i></div>
    <div class="wrap cta-in reveal">
      <h2>$title</h2>
      <p class="lead">$lead</p>
      <div class="btn-row"><a class="btn btn-gold" href="/randevu">Online randevu al</a><a class="btn btn-wa" href="{$_(wa('Merhaba, web sitenizden yazıyorum. Bilgi almak istiyorum.'))}" target="_blank" rel="noopener">{$_(WA_ICON)} WhatsApp'tan yazın</a></div>
    </div>
  </section>
HTML;
}

const TOPICS = [
  ['id' => 'para', 'label' => 'Para', 'title' => 'Para elinde durmuyorsa', 'text' => 'Ne kadar çalışsan da birikmiyor, borç döngüsü tekrar ediyor ya da “babamdan fazla kazanamam” diye bir ses duyuyorsan, mesele çoğu zaman yalnızca para değil, ailede paraya dair taşınan sadakatlerdir.'],
  ['id' => 'baba', 'label' => 'Baba', 'title' => 'Babayla ilişkin, hayatla ilişkin', 'text' => 'Aile dizimi yaklaşımında baba; dış dünya, çalışma, başarı ve otoriteyle ilişkimizi simgeler. Babayla kurulamayan bağ, kariyerde ve parada da kendini gösterebilir.'],
  ['id' => 'aile', 'label' => 'Aile sistemi', 'title' => 'Ailenin yükünü sen mi taşıyorsun?', 'text' => 'Ailenin ebeveyni olan çocuk, anne-baba arasında taraf tutmak, miras kavgaları, hayatta kalanın suçluluğu… Sistemde dengesi bozulan her şey bir sonraki kuşağa geçebilir.'],
  ['id' => 'iliski', 'label' => 'İlişkiler', 'title' => 'Aynı ilişkiyi farklı insanlarla yaşıyorsan', 'text' => 'Tekrar eden ilişki döngüleri bir tesadüf değildir. Çoğu zaman çocuklukta öğrendiğimiz rolleri ve ailemizden devraldığımız bağlanma biçimlerini tekrar ederiz.'],
];

$GLOBALS['MODE'] = fn($m) => $m === 'online' ? 'Online' : 'Yüz yüze';
function modes(array $x): string { return implode(' / ', array_map($GLOBALS['MODE'], $x['modes'])); }
function sp(): string { return I18n::lang() === 'en' ? '' : ' '; } // "210 bin" / "210k"

/* ---------- Ana sayfa ---------- */
function page_home(array $videos, array $ig, array $posts): string {
  $_ = fn($x) => $x;
  $s = settings();
  $portrait = image('portre');
  $hv = $s['heroVideo'] ?? ($videos['popular'][0] ?? ($videos['videos'][0] ?? null));
  $events = array_slice(upcomingEvents(), 0, 3);
  $services = array_values(array_filter(Store::read('services', []), fn($x) => !empty($x['bookable']) && $x['id'] !== 'on-gorusme'));
  $shortsBy = fn($id) => array_slice(array_values(array_filter($videos['shorts'], fn($x) => ($x['topic'] ?? '') === $id)), 0, 3);
  $media = ['Bugün · Saba Tümer', 'KRT TV · Gerçeği Duymaya Hazır Mısınız?', 'Migros TV', 'Uçankuş TV', 'Bunu Konuşmamız Lazım', '“Yılın Yaşam Koçu”', 'Gazete ve dergi röportajları'];
  $sp = sp();

  $arch = $portrait ? '<div class="arch"><img src="' . $portrait . '" alt="Aynur Tümen" width="520" height="680" fetchpriority="high"></div>' : '';
  $hero = '';
  if ($hv) {
    $hero = '<div class="hero-video ' . ($portrait ? 'hero-video-float' : '') . "\">\n          <span class=\"views-tag\">" . esc($hv['label'] ?? 'En çok izlenen') . "</span>\n          " . OFF . '<div class="yt-frame"><iframe src="https://www.youtube-nocookie.com/embed/' . esc($hv['id']) . '?rel=0&modestbranding=1" title="' . esc($hv['title']) . "\" allow=\"encrypted-media; picture-in-picture; web-share\" allowfullscreen></iframe></div>\n          <p lang=\"tr\">" . esc($hv['title']) . '</p>' . ON . "\n        </div>";
  }
  $marquee = join_map(array_merge($media, $media), fn($m) => '<span>' . esc($m) . '</span>');
  $tabs = join_map(TOPICS, fn($t, $i) => '<button role="tab" id="tab-' . $t['id'] . '" aria-controls="panel-' . $t['id'] . '" aria-selected="' . ($i === 0 ? 'true' : 'false') . '" tabindex="' . ($i === 0 ? 0 : -1) . '">' . $t['label'] . '</button>');
  $panels = join_map(TOPICS, fn($t, $i) => "\n      <div class=\"topic-panel\" role=\"tabpanel\" id=\"panel-{$t['id']}\" aria-labelledby=\"tab-{$t['id']}\" " . ($i ? 'hidden' : '') . ">
        <div class=\"topic-text\">
          <h3>{$t['title']}</h3>
          <p>{$t['text']}</p>
          <div class=\"btn-row\"><a class=\"btn btn-violet\" href=\"/randevu?hizmet=bireysel-dizim\">Bu konuyla çalışalım</a><a class=\"btn btn-line\" href=\"/videolar#kisa\">Tüm kısa videolar</a></div>
        </div>
        <div class=\"topic-shorts\">" . join_map($shortsBy($t['id']), fn($v) => yt($v, ['short' => true])) . "</div>
      </div>");
  $svc = join_map($services, fn($x, $i) => "\n        <article class=\"service reveal " . (!empty($x['featured']) ? 'service-featured' : '') . "\" style=\"--d:" . ($i * 80) . "ms\">
          " . (!empty($x['featured']) ? '<span class="badge">En çok tercih edilen</span>' : '') . "
          <h3>" . esc($x['name']) . "</h3>
          <p>" . esc($x['short']) . "</p>
          <div class=\"service-meta\"><span>" . modes($x) . "</span></div>
          <div class=\"service-foot\"><a class=\"btn " . (!empty($x['featured']) ? 'btn-gold' : 'btn-violet') . " btn-sm\" href=\"/randevu?hizmet=" . esc($x['id']) . "\">Randevu al</a></div>
        </article>");
  $eventsSec = $events ? "\n  <section class=\"events-sec\">
    <div class=\"wrap\">
      <div class=\"section-head section-head-row reveal\"><div><h2>Yaklaşan etkinlikler</h2><p class=\"lead\">Bilgilerini bırak, yerini WhatsApp üzerinden ayırt.</p></div><a class=\"link-arrow\" href=\"/etkinlikler\">Tüm takvim</a></div>
      <div class=\"event-list\">" . join_map($events, fn($e) => eventCard($e, ['compact' => true])) . "</div>
    </div>
  </section>" : '';
  $bk = books();
  $shelf = join_map(array_slice($bk, 1), fn($b) => '<span class="mini" title="' . esc($b['title']) . '">' . cover($b) . '</span>');
  $popular = join_map(array_slice($videos['popular'], 0, 3), fn($v) => yt($v));
  $igSec = $ig ? "\n  <section class=\"ig-sec\">
    <div class=\"wrap\">
      <div class=\"section-head section-head-row reveal\"><div><h2>Instagram'da</h2><p class=\"lead\">@aynurtumen · 210 bin kişilik topluluk</p></div><a class=\"btn btn-line btn-sm\" href=\"" . esc($s['social']['instagram']) . "\" target=\"_blank\" rel=\"noopener\">Takip et</a></div>
      <div class=\"ig-grid\">" . join_map(array_slice($ig, 0, 3), fn($u) => igEmbed($u)) . "</div>
    </div>
  </section>" : '';
  $postsHtml = join_map(array_slice($posts, 0, 3), fn($p, $i) => postCard($p, $i));

  $body = <<<HTML

  <section class="hero">
    <div class="aurora" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
    <canvas class="stars" aria-hidden="true"></canvas>
    <div class="wrap hero-in">
      <div class="hero-copy">
        <p class="hero-roles load-in" style="--d:0ms">{$_(esc(t($s['roles'])))}</p>
        <h1 class="load-in" style="--d:120ms">Taşıdığın his belki de sana ait değil.</h1>
        <p class="lead load-in" style="--d:240ms">Tekrar eden ilişkiler, elinde durmayan para, adını koyamadığın bir ağırlık… Aile dizimiyle bunların ailenden gelen görünmez bağlarla ilişkisine birlikte bakıyoruz.</p>
        <div class="btn-row load-in" style="--d:360ms">
          <a class="btn btn-gold" href="/randevu">Online randevu al</a>
          <a class="btn btn-wa" href="{$_(wa('Merhaba, web sitenizden yazıyorum. Aile dizimi hakkında bilgi almak istiyorum.'))}" target="_blank" rel="noopener">{$_(WA_ICON)} WhatsApp'tan yazın</a>
        </div>
        <div class="btn-row hero-social load-in" style="--d:420ms">
          <a class="btn btn-ig" href="{$_(esc($s['social']['instagram']))}" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="4.2" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="17.4" cy="6.6" r="1.3" fill="currentColor"/></svg> Instagram</a>
          <a class="btn btn-yt" href="{$_(esc($s['social']['youtube']))}" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M23 7.2a3 3 0 0 0-2.1-2.1C19 4.6 12 4.6 12 4.6s-7 0-8.9.5A3 3 0 0 0 1 7.2 31 31 0 0 0 .5 12 31 31 0 0 0 1 16.8a3 3 0 0 0 2.1 2.1c1.9.5 8.9.5 8.9.5s7 0 8.9-.5a3 3 0 0 0 2.1-2.1 31 31 0 0 0 .5-4.8 31 31 0 0 0-.5-4.8ZM9.8 15.1V8.9l5.4 3.1-5.4 3.1Z"/></svg> YouTube</a>
        </div>
        <ul class="hero-proof load-in" style="--d:480ms">
          <li><b><span data-count="210">210</span>{$sp}bin</b><span>Instagram'da takip eden</span></li>
          <li><b>2004</b><span>yılından beri</span></li>
          <li><b><span data-count="40">40</span>{$sp}bin+ saat</b><span>bireysel ve kurumsal çalışma</span></li>
        </ul>
      </div>
      <div class="hero-visual load-in" style="--d:200ms">
        $arch
        $hero
      </div>
    </div>
    <div class="marquee" aria-label="Ekranda ve basında">
      <div class="marquee-track">$marquee</div>
    </div>
  </section>

  <section class="topics" id="konular">
    <div class="wrap">
      <div class="section-head reveal">
        <h2>Hangi konuyu taşıyorsun?</h2>
        <p class="lead">Bir konu seç; o konudaki kısa videolarımı izle, sana tanıdık geliyorsa birlikte çalışalım.</p>
      </div>
      <div class="topic-tabs reveal" role="tablist" aria-label="Konular">
        $tabs
      </div>
      $panels
    </div>
  </section>

  <section class="what">
    <div class="wrap what-in">
      <div class="reveal">
        <h2>Aile dizimi nedir?</h2>
        <p class="lead">Bert Hellinger'in geliştirdiği bu yaklaşımda, aile sistemindeki yerimize ve kuşaklar boyunca aktarılan duygulara bakarız.</p>
        <p>Büyükannenin hiç konuşulmamış bir kaybı, torununun ilişkilerinde açıklanamayan bir terk edilme korkusu olarak yaşayabilir. Amaç suçlu aramak değil; taşıdığımızı fark edip sahibine saygıyla bırakabilmektir.</p>
        <a class="link-arrow" href="/aile-dizimi">Bir seans nasıl geçer?</a>
      </div>
      <ol class="path reveal">
        <li><b>Konunu getir</b><span>Görüşmenin başında niyetini netleştiririz.</span></li>
        <li><b>Sistemi gör</b><span>Temsilciler, semboller ya da imgelemeyle ailen görünür olur.</span></li>
        <li><b>Taşıdığını bırak</b><span>Çözüm cümleleri ve hareketlerle yük sahibine döner.</span></li>
        <li><b>Hayatında izle</b><span>Sonraki haftalarda değişimi gözlemler, istersen devam ederiz.</span></li>
      </ol>
    </div>
  </section>

  <section class="services">
    <div class="wrap">
      <div class="section-head reveal"><h2>Birlikte nasıl çalışabiliriz?</h2><p class="lead">Online ya da Sarıyer'de yüz yüze; bire bir, grupta veya eğitimde.</p></div>
      <div class="service-grid">
        $svc
        <article class="service service-group reveal" style="--d:240ms">
          <h3>Grup çalışmaları ve kamplar</h3>
          <p>Dolunay çalışmaları, grup dizimleri ve Çıralı'da üç günlük kamp.</p>
          <div class="service-foot"><a class="btn btn-line btn-sm" href="/etkinlikler">Takvimi gör</a></div>
        </article>
      </div>
    </div>
  </section>

  $eventsSec

  <section class="book-sec">
    <div class="wrap book-in">
      <div class="book3d reveal" aria-hidden="true">{$_(cover($bk[0]))}</div>
      <div class="reveal">
        <p class="kicker">Yeni kitabı</p>
        <h2>Görünmeyen Bağlar: Bu His Benim Değil</h2>
        <p class="lead">Hayatında tekrar eden ne varsa — ilişkilerde, parada, sağlıkta — belki sorun sen değilsin. Belki taşıyorsun.</p>
        <div class="btn-row"><a class="btn btn-violet" href="/kitap">Kitabı incele</a><a class="btn btn-line" href="{$_(esc($bk[0]['links'][0]['url']))}" target="_blank" rel="noopener">D&amp;R'dan satın al</a></div>
        <div class="shelf-mini">
          <span>Aynur Tümen'in diğer kitapları</span>
          <a href="/kitap#kitaplar" class="shelf-row">$shelf</a>
        </div>
      </div>
    </div>
  </section>

  <section class="videos-sec">
    <div class="wrap">
      <div class="section-head section-head-row reveal"><div><h2>YouTube'dan</h2><p class="lead">Aile dizimi, para, ilişkiler ve niyet çalışmaları üzerine.</p></div><a class="link-arrow" href="/videolar">Tüm videolar</a></div>
      <div class="video-grid">$popular</div>
    </div>
  </section>

  $igSec

  <section class="posts-sec">
    <div class="wrap">
      <div class="section-head section-head-row reveal"><div><h2>Yazılar</h2></div><a class="link-arrow" href="/yazilar">Tüm yazılar</a></div>
      <div class="post-grid">$postsHtml</div>
    </div>
  </section>

  {$_(ctaBand())}
HTML;

  $ld = ['@context' => 'https://schema.org', '@type' => 'ProfessionalService', 'name' => 'Aynur Tümen — Aile Dizimi ve Konstelasyon', 'telephone' => '+' . $s['phoneIntl'],
    'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Sarıyer', 'addressRegion' => 'İstanbul', 'addressCountry' => 'TR'],
    'sameAs' => array_values(array_filter(array_values($s['social'] ?? []))) ];
  return layout(['path' => '/', 'body' => $body,
    'head' => '<script type="application/ld+json">' . js_json($ld) . '</script>',
    'scripts' => $ig ? '<script async src="https://www.instagram.com/embed.js"></script>' : '']);
}

/* ---------- Aile dizimi ---------- */
function page_aileDizimi(): string {
  $_ = fn($x) => $x;
  $faq = [
    ['Aile dizimi yaptıran kişi etkilerini ne zaman görmeye başlar?', 'Kişiden kişiye değişir. Bazı danışanlar fark etmeyi seans sırasında yaşar; bazıları için çalışmanın yerleşmesi haftalar sürer. Bu yüzden seanstan sonra kısa bir süre büyük kararlar almamanızı öneririm.'],
    ['Ailemin geçmişini bilmiyorsam çalışabilir miyim?', 'Evet. Bildiğiniz kadarı yeterlidir. Çalışma sırasında bilmediğiniz bir şey görünür olursa bunu kesin bir bilgi olarak değil, araştırmaya değer bir soru olarak ele alırız.'],
    ['Aile dizimi sırasında bilinmeyen bir gerçek ortaya çıkarsa?', 'Dizimde görünenler sistemin bir resmidir. Bu resmi saygıyla karşılayıp sizin için ne anlam taşıdığına birlikte bakarız.'],
    ['Online aile dizimi yapılabilir mi?', 'Evet. Bireysel online seanslarda temsilciler yerine semboller, kâğıt figürler ve imgeleme kullanırız. Görüşme sitedeki görüşme odasından yapılır; uygulama indirmeniz gerekmez.'],
    ['Kaç seans gerekir?', 'Çoğu konu için tek bir dizim başlangıç için yeterlidir. Farklı konularla çalışmak isterseniz aralara birkaç hafta koymanızı öneririm.'],
    ['Randevumu değiştirebilir miyim?', 'Evet. Randevunuzu değiştirmek için en geç 24 saat önce WhatsApp’tan yazmanız yeterli.'],
  ];
  $faqHtml = join_map($faq, fn($x) => '<details class="reveal"><summary>' . $x[0] . '</summary><p>' . $x[1] . '</p></details>');
  $body = <<<HTML

  {$_(pageHead('Aile dizimi nedir, nasıl çalışır?', 'Aile dizimi (konstelasyon), aile sistemindeki görünmez bağları ve kuşaklar boyunca aktarılan duyguları görünür kılmaya yönelik bir çalışmadır.', 'Aile dizimi'))}
  <section class="prose-sec">
    <div class="wrap prose-grid">
      <div class="prose reveal">
        <h2>Kökleri</h2>
        <p>Yaklaşım, Alman psikoterapist Bert Hellinger'in aile sistemleri üzerine çalışmalarından doğdu. Temel fikir şudur: Her birimiz bir aile sisteminin parçasıyız ve bu sistemde dışlanan, unutulan ya da yası tutulamayan her şey sonraki kuşaklarda bir biçimde yeniden yaşanmak ister.</p>
        <p>Kişi bu duyguları kendine ait sanır. Dizim çalışmasında duygunun kimden, hangi yaşanmışlıktan geldiğini görmeye çalışırız.</p>
        <h2>Hangi konularda çalışılır?</h2>
        <ul class="ticks"><li>Tekrar eden ilişki döngüleri, sadakatsizlik, yalnızlık</li><li>Anne ve babayla ilişki, ebeveynlik, kardeş ilişkileri</li><li>Para, kariyer, başarıya izin verememe</li><li>Kayıplar, yas, ailede konuşulmayan konular</li><li>Miras, aile içi kırgınlıklar, dışlanan aile üyeleri</li></ul>
      </div>
      <aside class="side-card reveal">
        <h3>Bireysel aile dizimi</h3>
        <p>Online ya da yüz yüze</p>
        <a class="btn btn-gold" href="/randevu?hizmet=bireysel-dizim">Randevu al</a>
        <a class="btn btn-wa" href="{$_(wa('Merhaba, aile dizimi hakkında sorum var.'))}" target="_blank" rel="noopener">{$_(WA_ICON)} Soru sor</a>
      </aside>
    </div>
  </section>
  <section class="what">
    <div class="wrap what-in">
      <div class="reveal"><h2>Bir seans nasıl geçer?</h2><p class="lead">Her seans kendi akışında ilerler; adımlar genellikle şöyledir.</p></div>
      <ol class="path reveal">
        <li><b>Tanışma</b><span>Konunuzu ve ailenizdeki önemli olayları (erken kayıplar, ayrılıklar, göçler) kısaca konuşuruz.</span></li>
        <li><b>Niyet</b><span>Bu çalışmadan ne istediğinizi tek bir cümleyle netleştiririz.</span></li>
        <li><b>Dizim</b><span>Aile üyelerini temsil eden kişiler, semboller ya da imgeler yerleştirilir; ilişkiler görünür olur.</span></li>
        <li><b>Çözüm</b><span>Görülen bağa saygı gösteren cümleler ve hareketlerle taşınan yük sahibine bırakılır.</span></li>
        <li><b>Sonrası</b><span>Sonraki haftalarda yaşadıklarınızı gözlemlemeniz önerilir; istersen takip görüşmesi planlanır.</span></li>
      </ol>
    </div>
  </section>
  <section class="faq-sec">
    <div class="wrap narrow">
      <h2 class="reveal">Sık sorulan sorular</h2>
      <div class="faq">$faqHtml</div>
      <p class="note">Aile dizimi bir farkındalık ve kişisel gelişim çalışmasıdır; tıbbi ya da psikiyatrik tedavinin yerine geçmez. Süren bir tedaviniz varsa doktorunuzla devam etmeniz önemlidir.</p>
    </div>
  </section>
  {$_(ctaBand('Konunuzu birlikte netleştirelim.'))}
HTML;
  $ld = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($x) => ['@type' => 'Question', 'name' => $x[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $x[1]]], $faq)];
  return layout(['title' => 'Aile Dizimi (Konstelasyon) Nedir, Nasıl Yapılır?', 'path' => '/aile-dizimi', 'body' => $body,
    'description' => 'Aile dizimi nedir, bir seans nasıl geçer, kimler için uygundur? Bireysel ve grup aile dizimi, online çalışma ve sık sorulan sorular.',
    'head' => '<script type="application/ld+json">' . js_json($ld) . '</script>']);
}

/* ---------- Hizmetler ---------- */
function page_hizmetler(): string {
  $_ = fn($x) => $x;
  $services = Store::read('services', []);
  $row = fn($x) => "\n    <article class=\"offer reveal\">
      <div><h3>" . esc($x['name']) . "</h3><div class=\"offer-meta\">" . modes($x) . "</div></div>
      <p>" . esc($x['short']) . "</p>
      <div class=\"offer-cta\">" . (!empty($x['bookable']) ? '<a class="btn btn-violet btn-sm" href="/randevu?hizmet=' . esc($x['id']) . '">Randevu al</a>' : '<a class="btn btn-line btn-sm" href="' . wa('Merhaba, ' . $x['name'] . ' hakkında teklif almak istiyoruz.') . '" target="_blank" rel="noopener">Teklif iste</a>') . "</div>
    </article>";
  $g = fn(array $groups) => join_map(array_filter($services, fn($x) => in_array($x['group'] ?? '', $groups, true)), $row);
  $chips = join_map(['İlişki ve evlilik', 'Aile ve ebeveyn', 'Ergen ve çocuk', 'Kariyer', 'Öğrenci ve sınav kaygısı', 'Sağlık', 'Sporcu', 'Spiritüel'], fn($c) => "<span>$c</span>");
  $akademi = esc(settings()['social']['akademi']);
  $body = <<<HTML

  {$_(pageHead('Birlikte nasıl çalışabiliriz?', "Bireysel seanslar online ya da Sarıyer'de yüz yüze yapılır.", 'Çalışmalar'))}
  <section><div class="wrap"><h2 class="reveal">Aile dizimi</h2><div class="offers">{$_($g(['dizim', 'kurumsal']))}</div></div></section>
  <section class="tint"><div class="wrap"><h2 class="reveal">Koçluk</h2><p class="lead reveal">2004'ten beri sürdürdüğüm koçluk çalışmaları; gerektiğinde aile dizimiyle birlikte.</p>
    <div class="offers">{$_($g(['kocluk']))}</div>
    <div class="chips reveal">$chips</div>
  </div></section>
  <section id="akademi"><div class="wrap split">
    <div class="reveal"><h2>S. Aynur Tümen Akademi</h2><p class="lead">Aile dizimini kendi pratiğine katmak isteyen danışmanlar, koçlar ve meraklılar için eğitimler.</p><a class="btn btn-line btn-sm" href="$akademi" target="_blank" rel="noopener">Akademi Instagram hesabı</a></div>
    <div class="offers">
      <article class="offer offer-2 reveal"><div><h3>Konstelasyon eğitimi</h3><div class="offer-meta">Kademeli program · sertifikalı</div></div><div class="offer-cta"><a class="btn btn-gold btn-sm" href="{$_(wa('Merhaba, konstelasyon eğitimi için ön kayıt yaptırmak istiyorum.'))}" target="_blank" rel="noopener">Ön kayıt</a></div></article>
      <article class="offer offer-2 reveal"><div><h3>Bilinçaltı eğitimleri</h3><div class="offer-meta">Online modüller</div></div><div class="offer-cta"><a class="btn btn-line btn-sm" href="{$_(wa('Merhaba, bilinçaltı eğitimleri hakkında bilgi almak istiyorum.'))}" target="_blank" rel="noopener">Bilgi al</a></div></article>
      <article class="offer offer-2 reveal"><div><h3>Hayatın Anlamı Projesi · Yeni Bir Ben</h3><div class="offer-meta">Kişisel dönüşüm programları</div></div><div class="offer-cta"><a class="btn btn-line btn-sm" href="{$_(wa('Merhaba, kişisel dönüşüm programları hakkında bilgi almak istiyorum.'))}" target="_blank" rel="noopener">Bilgi al</a></div></article>
    </div>
  </div></section>
  <section class="tint"><div class="wrap split">
    <div class="reveal"><h2>Kurumlar için</h2><p class="lead">Üniversiteler, belediyeler, yaşam evleri ve şirketler için motivasyon seminerleri ve atölyeler.</p></div>
    <div class="reveal"><p>Konu ve katılımcı sayısına göre program hazırlanır.</p><a class="btn btn-wa" href="{$_(wa('Merhaba, kurumumuz için seminer/atölye talebimiz var.'))}" target="_blank" rel="noopener">{$_(WA_ICON)} Kurumsal talep gönder</a></div>
  </div></section>
  {$_(ctaBand())}
HTML;
  return layout(['title' => 'Çalışmalar', 'path' => '/hizmetler', 'body' => $body, 'description' => 'Bireysel aile dizimi, çift dizimi, koçluk, organizasyon dizimi ve Akademi eğitimleri. Online randevu.']);
}

/* ---------- Etkinlikler ---------- */
function page_etkinlikler(): string {
  $_ = fn($x) => $x;
  $events = upcomingEvents();
  $list = $events ? join_map($events, fn($e) => eventCard($e)) : '<div class="empty"><h3>Yeni etkinlikler yakında</h3><p>Duyurulardan ilk sen haberdar olmak için WhatsApp\'tan yaz.</p><a class="btn btn-wa" href="' . wa('Merhaba, etkinlik duyurularından haberdar olmak istiyorum.') . '" target="_blank" rel="noopener">' . WA_ICON . ' Haber ver</a></div>';
  $body = <<<HTML

  {$_(pageHead('Etkinlik takvimi', 'Grup aile dizimleri, dolunay ve niyet çalışmaları, kamplar. Kayıt bilgilerini doldur, WhatsApp üzerinden gönder.', 'Etkinlikler'))}
  <section><div class="wrap">
    <div class="filters reveal" role="group" aria-label="Etkinlik türü">
      <button data-filter="all" aria-pressed="true">Tümü</button><button data-filter="online" aria-pressed="false">Online</button><button data-filter="yuzyuze" aria-pressed="false">Yüz yüze</button><button data-filter="kamp" aria-pressed="false">Kamplar</button>
    </div>
    <div class="event-list">$list</div>
  </div></section>
  <section class="tint"><div class="wrap split">
    <div class="reveal"><h2>Kaçırdığın yayınlar</h2><p class="lead">Geçmiş canlı yayınların kayıtları YouTube kanalında.</p></div>
    <div class="reveal"><a class="btn btn-line" href="/videolar">Videolara git</a></div>
  </div></section>
HTML;
  return layout(['title' => 'Etkinlik Takvimi: Grup Aile Dizimi, Dolunay Çalışmaları, Kamplar', 'path' => '/etkinlikler', 'body' => $body, 'description' => 'Online ve yüz yüze grup aile dizimi atölyeleri, dolunay ve niyet çalışmaları, Çıralı kampı. Kayıt WhatsApp üzerinden.']);
}

/* ---------- Videolar ---------- */
function page_videolar(array $v): string {
  $_ = fn($x) => $x;
  $latest = array_slice($v['videos'], 0, 9);
  usort($latest, fn($a, $b) => ((float)($b['views'] ?? 0)) <=> ((float)($a['views'] ?? 0)));
  $yt = esc(settings()['social']['youtube']);
  $body = <<<HTML

  {$_(pageHead('Videolar', 'Aile dizimi, para, ilişkiler ve niyet çalışmaları üzerine videolar ve kısa videolar.', 'Videolar'))}
  <section><div class="wrap">
    <div class="section-head section-head-row reveal"><div><h2>Son yüklenenler</h2></div><a class="btn btn-line btn-sm" href="$yt?sub_confirmation=1" target="_blank" rel="noopener">Kanala abone ol</a></div>
    <div class="video-grid">{$_(join_map($latest, fn($x) => yt($x)))}</div>
  </div></section>
  <section class="tint" id="kisa"><div class="wrap">
    <div class="section-head reveal"><h2>Kısa videolar</h2><p class="lead">Para, baba, aile sistemi ve ilişkiler üzerine bir dakikalık videolar.</p></div>
    <div class="shorts-row">{$_(join_map(array_slice($v['shorts'], 0, 12), fn($x) => yt($x, ['short' => true])))}</div>
  </div></section>
  <section><div class="wrap">
    <div class="section-head reveal"><h2>En çok izlenenler</h2></div>
    <div class="video-grid">{$_(join_map($v['popular'], fn($x) => yt($x)))}</div>
  </div></section>
  {$_(ctaBand('İzlediklerin sana tanıdık geldiyse…', 'Kendi konunla çalışmak için görüşme türünü seçip randevu alabilirsin.'))}
HTML;
  return layout(['title' => 'Videolar', 'path' => '/videolar', 'body' => $body, 'description' => 'Aynur Tümen YouTube kanalından aile dizimi, para, ilişkiler ve niyet çalışmaları üzerine videolar ve kısa videolar.']);
}

/* ---------- Kitap ---------- */
function page_kitap(): string {
  $_ = fn($x) => $x;
  $list = books(); $b = $list[0]; $rest = array_slice($list, 1);
  $links = join_map($b['links'], fn($l, $i) => '<a class="btn ' . ($i ? 'btn-line-light' : 'btn-gold') . '" href="' . esc($l['url']) . '" target="_blank" rel="noopener">' . esc($l['label']) . '</a>');
  $shelf = join_map($rest, fn($x, $i) => "\n      <article class=\"shelf-book reveal\" style=\"--d:" . ($i * 70) . "ms\">
        <div class=\"shelf-cover\">" . cover($x) . "</div>
        <div class=\"shelf-info\">
          <span class=\"post-cat\">" . esc(t($x['genre'])) . ' · ' . $x['year'] . "</span>
          <h3>" . esc($x['title']) . "</h3>
          " . (!empty($x['subtitle']) ? '<p class="shelf-sub">' . esc($x['subtitle']) . '</p>' : '') . "
          <p>" . esc($x['text']) . "</p>
          <p class=\"shelf-meta\">" . esc($x['publisher']) . ' · ' . esc(t($x['pages'] . ' sayfa')) . "</p>
          " . join_map($x['links'], fn($l) => '<a class="link-arrow" href="' . esc($l['url']) . '" target="_blank" rel="noopener">' . esc($l['label']) . '</a>') . "
        </div>
      </article>");
  $body = <<<HTML

  <section class="page-hero page-hero-book">
    <div class="aurora" aria-hidden="true"><i></i><i></i><i></i></div>
    <div class="wrap book-in">
      <div class="book3d load-in" aria-hidden="true">{$_(cover($b))}</div>
      <div class="load-in" style="--d:150ms">
        <nav class="crumbs" aria-label="Konum"><a href="/">Ana sayfa</a><span>/</span>Kitapları</nav>
        <p class="kicker kicker-light">{$_(t('Yeni kitabı'))} · {$b['year']}</p>
        <h1>{$_(esc($b['title']))}: {$_(esc($b['subtitle']))}</h1>
        <p class="lead">Hayatında tekrar eden ne varsa — ilişkilerde, parada, sağlıkta — belki sorun sen değilsin. Belki taşıyorsun.</p>
        <p class="book-meta">{$_(esc($b['publisher']))} · {$_(esc(t($b['pages'] . ' sayfa')))} · {$b['year']}</p>
        <div class="btn-row">$links</div>
      </div>
    </div>
  </section>
  <section><div class="wrap split">
    <div class="reveal"><h2>Kitap sana ne anlatıyor?</h2><p class="lead">{$_(esc($b['text']))}</p></div>
    <ul class="ticks reveal"><li>Bilinçaltının yalnızca sana ait olmadığını,</li><li>ailende yaşanmış travmaların ve kayıpların kuşaklar boyunca nasıl taşındığını,</li><li>görünmez sadakatlerin ilişkilerini ve paranla bağını nasıl şekillendirdiğini,</li><li>taşıdığın duyguyu fark edip sahibine saygıyla bırakmanın yollarını.</li></ul>
  </div></section>
  <section class="tint" id="kitaplar"><div class="wrap">
    <div class="section-head reveal"><h2>Diğer kitapları</h2><p class="lead">2011'den bu yana aşk, para, cinsellik ve kişisel dönüşüm üzerine yazdıkları ve bir romanı.</p></div>
    <div class="shelf">$shelf
    </div>
  </div></section>
  <section><div class="wrap">
    <div class="section-head reveal"><h2>Yeni kitabı ondan dinle</h2></div>
    <div class="video-grid video-grid-2">{$_(yt(['id' => '0TJQlqpPr48', 'title' => 'Kitap tanıtımı']))}{$_(yt(['id' => 'wsmzIrYdnCA', 'title' => 'Yeni kitabım ve kalbimden geçenler']))}</div>
  </div></section>
  {$_(ctaBand('Kitapta okuduklarını kendi ailende görmek istersen', 'Bireysel bir dizim ya da grup çalışmasıyla devam edebilirsin.'))}
HTML;
  $graph = array_map(function ($x) {
    $o = ['@type' => 'Book', 'name' => !empty($x['subtitle']) ? $x['title'] . ' - ' . $x['subtitle'] : $x['title'], 'author' => ['@type' => 'Person', 'name' => 'Aynur Tümen'], 'publisher' => $x['publisher'], 'numberOfPages' => $x['pages'], 'datePublished' => (string)$x['year']];
    if (!empty($x['isbn'])) $o['isbn'] = $x['isbn'];
    $o['inLanguage'] = 'tr';
    return $o;
  }, $list);
  return layout(['title' => 'Kitapları: Görünmeyen Bağlar, Para Kodları, Aşk Kodları ve diğerleri', 'path' => '/kitap', 'body' => $body,
    'description' => "Aynur Tümen'in kitapları: Görünmeyen Bağlar – Bu His Benim Değil (2026), Aşkın Gizli Bahçesi, Para Kodları, Aşk Kodları, Mina & Lejyonerin Kızı ve Aynur Tümen.",
    'head' => '<script type="application/ld+json">' . js_json(['@context' => 'https://schema.org', '@graph' => $graph]) . '</script>']);
}

/* ---------- Yazılar ---------- */
function page_yazilar(array $posts): string {
  $_ = fn($x) => $x;
  $cats = array_values(array_unique(array_filter(array_map(fn($p) => $p['category'] ?? '', $posts))));
  // Not yalnız bu dilde çevirisi olmayan yazı varsa gösterilir
  $eksik = I18n::lang() !== 'tr' && array_filter($posts, fn($p) => !YaziCeviri::get($p, I18n::lang()));
  $note = $eksik ? '<p class="lang-note reveal">Yazılar Türkçe olarak yayımlanmaktadır.</p>' : '';
  $catBtns = join_map($cats, fn($c) => '<button data-filter="' . esc($c) . '" aria-pressed="false">' . esc($c) . '</button>');
  $q = trim((string)($_GET['q'] ?? ''));
  $found = $q !== '' ? array_flip(search_posts($posts, $q, I18n::lang())) : null;
  $total = count($posts); $shown = $found === null ? $total : count($found);
  $grid = join_map($posts, fn($p, $i) => preg_replace('/<article class="post/', '<article data-slug="' . esc($p['slug']) . '" data-type="' . str_replace(['\\', '$'], ['\\\\', '\\$'], esc($p['category'] ?? '')) . '"' . ($found !== null && !isset($found[$p['slug']]) ? ' hidden' : '') . ' class="post', postCard($p, $i % 3), 1));
  $body = <<<HTML

  {$_(pageHead('Yazılar', 'Aile dizimi, ilişkiler, anne-baba ilişkisi, para ve zihin üzerine.', 'Yazılar'))}
  <section><div class="wrap">
    $note
    <form class="post-search reveal" role="search" action="/yazilar" method="get" data-post-search-form>
      <label class="sr-only" for="yazi-ara">Yazılarda ara</label>
      <svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
      <input id="yazi-ara" type="search" name="q" value="{$_(esc($q))}" placeholder="Yazılarda ara: baba, para, ilişki…" autocomplete="off" enterkeyhint="search" data-post-search>
    </form>
    <div class="filters reveal" role="group" aria-label="Kategori"><button data-filter="all" aria-pressed="true">Tümü</button>$catBtns</div>
    <p class="post-count" aria-live="polite"><span data-post-count data-total="$total">$shown yazı</span></p>
    <p class="post-empty" data-post-empty{$_($shown ? ' hidden' : '')}>Bu kelimeyle ilgili yazı bulunamadı.</p>
    <div class="post-grid">$grid</div>
  </div></section>
HTML;
  return layout(['title' => 'Yazılar', 'path' => '/yazilar', 'body' => $body, 'client' => true, 'description' => 'Aile dizimi, ilişkiler, anne-baba ilişkisi, para ve zihin üzerine yazılar.']);
}

// Eski sitedeki "08 May" gibi kısa Türkçe tarihi seçili dilde yazar
function postDate(string $str): string {
  $mons = ['oca', 'şub', 'mar', 'nis', 'may', 'haz', 'tem', 'ağu', 'eyl', 'eki', 'kas', 'ara'];
  if (!preg_match('/^(\d{1,2})\s+(\S+)/u', trim($str), $m)) return $str;
  $i = array_search(mb_substr(tr_lower($m[2]), 0, 3), $mons, true);
  if ($i === false) return $str;
  return intl_fmt((int)gmmktime(12, 0, 0, $i + 1, (int)$m[1], 2024), I18n::LOCALE[I18n::lang()], 'dMMMM');
}

function page_yazi(array $post, array $posts): string {
  $_ = fn($x) => $x;
  $related = array_slice(array_values(array_filter($posts, fn($p) => $p['slug'] !== $post['slug'] && ($p['category'] ?? '') === ($post['category'] ?? ''))), 0, 3);
  $date = !empty($post['date']) ? ' · ' . OFF . esc(postDate($post['date'])) . ON : '';
  $t = YaziCeviri::get($post, I18n::lang());
  $lang = $t ? I18n::lang() : 'tr';
  $title = $t['title'] ?? $post['title']; $html = $t['html'] ?? $post['html'];
  $note = I18n::lang() !== 'tr' && !$t ? '<p class="lang-note">Bu yazı Türkçedir.</p>' : '';
  $rel = $related ? '<section class="tint"><div class="wrap"><h2>İlgili yazılar</h2><div class="post-grid">' . join_map($related, fn($p, $i) => postCard($p, $i)) . '</div></div></section>' : '';
  $body = <<<HTML

  {$_(pageHead(OFF . esc($title) . ON, '', '<a href="/yazilar">Yazılar</a><span>/</span>' . ($t ? esc($post['category'] ?? '') : OFF . esc($post['category'] ?? '') . ON)))}
  <article class="article"><div class="wrap narrow">
    <p class="byline">Aynur Tümen$date</p>
    $note
    <div class="prose" lang="$lang">{$_(OFF)}$html{$_(ON)}</div>
    <aside class="article-cta">
      <h3>Bu konu sana tanıdık geldiyse</h3>
      <p>Kendi ailende bunun nereden geldiğine birlikte bakabiliriz.</p>
      <div class="btn-row"><a class="btn btn-violet" href="/randevu?hizmet=bireysel-dizim">Bireysel dizim randevusu</a><a class="btn btn-wa" href="{$_(wa('Merhaba, yazınızı okudum, bilgi almak istiyorum: ' . $post['title']))}" target="_blank" rel="noopener">{$_(WA_ICON)} WhatsApp'tan yaz</a></div>
    </aside>
  </div></article>
  $rel
HTML;
  return layout(['title' => $title, 'path' => '/yazilar/' . $post['slug'], 'body' => $body, 'langs' => YaziCeviri::langsFor($post), 'description' => $t['excerpt'] ?? ($post['excerpt'] ?? null),
    'head' => '<script type="application/ld+json">' . js_json(['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => $title, 'author' => ['@type' => 'Person', 'name' => 'Aynur Tümen'], 'inLanguage' => $lang]) . '</script>']);
}

/* ---------- Hakkında ---------- */
function page_hakkinda(array $v): string {
  $_ = fn($x) => $x;
  $portrait = image('portre');
  $press = gallery('basin');
  $tv = [['Bugün', 'Saba Tümer ile, aşk köşesi'], ['Gerçeği Duymaya Hazır Mısınız?', 'KRT TV, Bihin Edige ile · 2018–2019'], ['Migros TV', 'Konuk uzman'], ['Uçankuş TV', 'İlişkiler ve sadakat'], ['Bunu Konuşmamız Lazım', 'Konuk yaşam koçu'], ['Yılın Yaşam Koçu', 'Yılın en iyileri oylaması']];
  $sp = sp();
  $visual = $portrait ? '<div class="arch arch-sm load-in" style="--d:150ms"><img src="' . $portrait . '" alt="Aynur Tümen"></div>' : '<div class="video-hero load-in" style="--d:150ms">' . yt(['id' => '72kTtzLVJqE', 'title' => 'Neden aile dizimi yaptırmalısın?']) . '</div>';
  $tvHtml = join_map($tv, fn($x, $i) => '<div class="tv reveal" style="--d:' . ($i * 60) . 'ms"><b>' . $x[0] . '</b><span>' . $x[1] . '</span></div>');
  $pressSec = $press ? '<section><div class="wrap"><div class="section-head reveal"><h2>Basında</h2></div><div class="press-grid">' . join_map($press, fn($src) => '<a class="press reveal" href="' . $src . '" data-lightbox><img loading="lazy" src="' . $src . '" alt="Basında çıkan haber"></a>') . '</div></div></section>' : '';
  $body = <<<HTML

  <section class="page-hero page-hero-about">
    <div class="aurora" aria-hidden="true"><i></i><i></i><i></i></div>
    <div class="wrap about-in">
      <div class="load-in">
        <nav class="crumbs" aria-label="Konum"><a href="/">Ana sayfa</a><span>/</span>Hakkında</nav>
        <h1>Merhaba, ben Aynur Tümen.</h1>
        <p class="lead">2004'ten beri insanların hayatlarındaki tekrarları fark etmelerine ve kendi yollarını bulmalarına eşlik ediyorum.</p>
      </div>
      $visual
    </div>
  </section>
  <section><div class="wrap prose-grid">
    <div class="prose reveal">
      <p>1972'de İstanbul'da doğdum; evliyim ve bir oğul annesiyim. Yaşam koçluğu ve motivasyon danışmanlığıyla başlayan yolculuğum, zamanla aile sistemlerine ve aile dizimine (konstelasyon) uzandı.</p>
      <p>Bugüne kadar ilişki ve evlilik, aile içi iletişim, ebeveynlik, ergenlik, kariyer, sağlık ve sporcu koçluğu gibi pek çok alanda çalıştım. Kanser hastaları ve yaşlılarla kabullenme ve hayata bağlanma üzerine çalışmayı sürdürüyorum.</p>
      <p>Üniversitelerde, liselerde, belediyelerde, yaşam evlerinde ve özel kurumlarda seminerler verdim. Kurumsal ve bireysel çalışmalarım 40.000 saati aştı. Altı kitap yazdım; sonuncusu <a href="/kitap">Görünmeyen Bağlar: Bu His Benim Değil</a>, kuşaktan kuşağa taşınan duygular üzerine.</p>
    </div>
    <aside class="stats reveal">
      <div><b data-count="2004">2004</b><span>yılından beri</span></div>
      <div><b><span data-count="40">40</span>{$sp}bin+</b><span>saat çalışma</span></div>
      <div><b><span data-count="210">210</span>{$sp}bin</b><span>Instagram topluluğu</span></div>
      <div><b><span data-count="1740">1740</span>+</b><span>YouTube videosu</span></div>
    </aside>
  </div></section>
  <section class="tint"><div class="wrap">
    <div class="section-head section-head-row reveal"><div><h2>Ekranda ve sahnede</h2></div><a class="link-arrow" href="/galeri">Fotoğraf galerisi</a></div>
    <div class="tv-grid">$tvHtml</div>
  </div></section>
  $pressSec
  <section><div class="wrap">
    <div class="section-head reveal"><h2>Konuk olduğum yayınlardan</h2></div>
    <div class="video-grid">{$_(join_map(array_slice($v['popular'], 0, 3), fn($x) => yt($x)))}</div>
  </div></section>
  {$_(ctaBand('Tanışalım.', 'Görüşme türünü seç, WhatsApp’tan yaz.'))}
  <dialog class="lightbox" data-lightbox-dialog><img alt=""><button class="lightbox-close" aria-label="Kapat">×</button></dialog>
HTML;
  return layout(['title' => 'Aynur Tümen Kimdir? Hakkında ve Basında', 'path' => '/hakkinda', 'body' => $body, 'description' => "2004'ten beri yaşam koçluğu ve aile dizimi çalışmaları yapan Aynur Tümen'in hikâyesi, televizyon programları ve basında çıkan haberler."]);
}

/* ---------- Galeri ---------- */
function page_galeri(array $ig): string {
  $_ = fn($x) => $x;
  $photos = galleryTree('galeri');
  $cats = array_values(array_unique(array_column($photos, 'cat')));
  $gal = '';
  if ($photos) {
    $filters = count($cats) > 1 ? '<div class="filters reveal" role="group" aria-label="Kategori"><button data-filter="all" aria-pressed="true">Tümü</button>' . join_map($cats, fn($c) => '<button data-filter="' . esc($c) . '" aria-pressed="false">' . esc($c) . '</button>') . '</div>' : '';
    $items = join_map($photos, fn($p, $i) => '<a class="gal-item reveal" data-type="' . esc($p['cat']) . '" href="' . $p['src'] . '" data-lightbox style="--d:' . (($i % 8) * 50) . 'ms"><img loading="lazy" src="' . $p['src'] . '" alt="' . esc($p['cat']) . '"></a>');
    $gal = "<section><div class=\"wrap\">\n    $filters\n    <div class=\"gallery\">$items</div>\n  </div></section>";
  }
  $igSec = $ig ? '<section class="' . ($photos ? 'tint' : '') . "\"><div class=\"wrap\">
    <div class=\"section-head section-head-row reveal\"><div><h2>Instagram'dan</h2><p class=\"lead\">@aynurtumen</p></div><a class=\"btn btn-line btn-sm\" href=\"" . esc(settings()['social']['instagram']) . "\" target=\"_blank\" rel=\"noopener\">Takip et</a></div>
    <div class=\"ig-grid\">" . join_map(array_slice($ig, 0, 6), fn($u) => igEmbed($u)) . "</div>
  </div></section>" : '';
  $body = <<<HTML

  {$_(pageHead('Fotoğraf galerisi', 'Atölyeler, kamplar, seminerler ve ekrandan kareler.', '<a href="/hakkinda">Hakkında</a><span>/</span>Galeri'))}
  $gal
  $igSec
  <dialog class="lightbox" data-lightbox-dialog><img alt=""><button class="lightbox-close" aria-label="Kapat">×</button><button class="lb-nav lb-prev" aria-label="Önceki">‹</button><button class="lb-nav lb-next" aria-label="Sonraki">›</button></dialog>
HTML;
  return layout(['title' => 'Fotoğraf Galerisi', 'path' => '/galeri', 'body' => $body, 'description' => 'Aynur Tümen atölye, kamp, seminer ve televizyon programlarından fotoğraflar.', 'scripts' => $ig ? '<script async src="https://www.instagram.com/embed.js"></script>' : '']);
}

/* ---------- İletişim ---------- */
function page_iletisim(bool $sent = false, ?string $error = null): string {
  $_ = fn($x) => $x;
  $s = settings();
  $lead = str_replace('{ad}', esc($s['assistant']), esc(t('En hızlı yol WhatsApp. Aile dizimi çalışmaları ve WhatsApp grupları için asistanım {ad} size dönüş yapar.')));
  $err = $error ? '<p class="form-error" role="alert">' . esc($error) . '</p>' : '';
  $form = $sent ? '<div class="success-box"><h3>Mesajınız alındı</h3><p>En geç bir iş günü içinde dönüş yapılacak.</p></div>' : <<<HTML

      <form class="form" method="post" action="/iletisim">
        $err
        <div class="row"><label>Ad soyad<input name="name" required autocomplete="name"></label><label>Telefon<input name="phone" type="tel" autocomplete="tel"{$_(!mail_on() && form_wa_on() ? ' required placeholder="05xx xxx xx xx"' : '')}></label></div>
        {$_(mail_on() ? '<label>E-posta<input name="email" type="email" required autocomplete="email"></label>' : '')}
        <label>Konu<select name="topic"><option>Bireysel aile dizimi</option><option>Grup çalışmaları ve kamplar</option><option>Akademi ve eğitimler</option><option>Kurumsal seminer</option><option>Basın ve iş birliği</option><option>Diğer</option></select></label>
        <label>Mesajınız<textarea name="message" rows="5" required></textarea></label>
        <label class="check"><input type="checkbox" name="kvkk" required> <span>Kişisel verilerimin işlenmesini onaylıyorum. <a href="/yasal#kvkk" target="_blank">KVKK aydınlatma metni</a></span></label>
        <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
        {$_(mail_on() || form_wa_on() ? '<button class="btn btn-violet" type="submit">Mesajı gönder</button>' . (!mail_on() ? '<p class="small muted">Mesajınız ekibimize iletilir; size WhatsApp ya da telefonla dönüş yapılır.</p>' : '') : '<button class="btn btn-wa" type="submit">' . WA_ICON . ' WhatsApp’tan gönder</button><p class="small muted">Gönder’e bastığında WhatsApp açılır ve mesajın hazır yazılmış olur; göndermen yeterli.</p>')}
      </form>
HTML;
  $body = <<<HTML

  {$_(pageHead('İletişim', $lead, 'İletişim'))}
  <section><div class="wrap split">
    <div class="reveal contact-card">
      <a class="btn btn-wa btn-lg" href="{$_(wa('Merhaba, web sitenizden yazıyorum.'))}" target="_blank" rel="noopener">{$_(WA_ICON)} WhatsApp: {$_(esc($s['phoneDisplay']))}</a>
      <dl>
        <dt>Telefon</dt><dd><a href="tel:+{$s['phoneIntl']}">{$_(esc($s['phoneDisplay']))}</a></dd>
        <dt>Ofis</dt><dd>{$_(esc(t($s['city'])))} <small>(tam adres randevu onayında paylaşılır)</small></dd>
        <dt>Çalışma saatleri</dt><dd>Pazartesi–Cuma 10:00–20:00<br>Hafta sonu 12:00–16:00</dd>
      </dl>
    </div>
    <div class="reveal">
      $form
    </div>
  </div></section>
HTML;
  return layout(['title' => 'İletişim', 'path' => '/iletisim', 'body' => $body, 'description' => "Aynur Tümen'e WhatsApp, telefon veya form ile ulaşın. Çalışma saatleri ve ofis bilgisi."]);
}

/* ---------- Yasal ---------- */
function page_yasal(): string {
  $_ = fn($x) => $x;
  $body = <<<HTML

  {$_(pageHead('Yasal metinler', '', 'Yasal metinler'))}
  <section><div class="wrap narrow prose legal">
    <nav class="chips"><a href="#kvkk">KVKK</a><a href="#cerez">Çerezler</a><a href="#iade">İptal ve erteleme</a></nav>
    <h2 id="kvkk">KVKK aydınlatma metni</h2>
    <p>Kişisel verileriniz (ad, soyad, telefon, e-posta ve randevu bilgileri) randevunuzun ve etkinlik kaydınızın yürütülmesi, sizinle iletişim kurulması ve yasal yükümlülüklerin yerine getirilmesi amacıyla, 6698 sayılı Kanun'a uygun olarak işlenir. Randevu talebiniz WhatsApp üzerinden iletildiğinde, mesajlaşma WhatsApp altyapısında gerçekleşir. Online görüşmeler için görüntülü görüşme altyapısı kullanılır. Kanun'un 11. maddesindeki haklarınız için iletişim sayfasındaki kanallardan başvurabilirsiniz.</p>
    <h2 id="cerez">Çerez politikası</h2>
    <p>Sitenin çalışması için zorunlu çerezler ve izninizle ziyaret istatistiği çerezleri kullanılır. Tercihinizi tarayıcınızdan çerezleri silerek değiştirebilirsiniz.</p>

    <h2 id="iade">İptal ve erteleme</h2>
    <ul><li>Randevunuzu ertelemek ya da iptal etmek için en geç 24 saat önce WhatsApp’tan yazmanız yeterlidir.</li><li>Etkinlik ve kamp kaydınızda bir değişiklik olursa yine WhatsApp’tan haber vermeniz yeterlidir.</li></ul>
  </div></section>
HTML;
  return layout(['title' => 'Yasal Metinler', 'path' => '/yasal', 'body' => $body, 'noindex' => true]);
}

/* ---------- Bilgilendirme (yalnız linkle açılır: menüde yok, Google'da yok) ---------- */
// WhatsApp karşılama mesajındaki link buraya gelir. Ücretler ve kayıt adımları burada; değişince bu fonksiyon güncellenir.
function feeCard(string $title, string $meta, array $fees, array $notes): string {
  $f = join_map($fees, fn($x) => '<div class="fee"><span>' . $x[0] . '</span><b>' . $x[1] . '</b></div>');
  $n = join_map($notes, fn($x) => "<li>$x</li>");
  return "\n    <article class=\"fee-card reveal\"><div class=\"fee-head\"><h3>$title</h3><div class=\"offer-meta\">$meta</div></div><div class=\"fees\">$f</div><ul>$n</ul></article>";
}
function bilgi_body(): string {
  $_ = fn($x) => $x;
  $T = fn($x) => OFF . $x . ON; // tutarlar her dilde aynı yazılır
  $online = feeCard('Bireysel seans', 'Online · WhatsApp görüntülü görüşme', [['Seans süresi', '1 saat'], ['Seans ücreti', $T('6.500 TL')]], [
      'İlk seansta sizi zorlayan dinamikler belirlenir ve bir yol haritası çizilir.',
      'Dinamiklerin içeriğine göre 1-2 seans daha gerekebilir. Seanslar arasında belli bir süre beklemek gerekmez.',
      'Belirlenen saatte Aynur Tümen’i WhatsApp’tan görüntülü arayarak seansınızı başlatırsınız.'])
    . feeCard('Online grup aile dizimi', 'Online · Zoom', [['Açılımcı', $T('6.500 TL')], ['Temsilci / izleyici', $T('3.500 TL')]], [
      'Gündüz grubu: 10.00-14.00, akşam grubu: 20.00-24.00.',
      'Açılımcı sayısı sınırlıdır.',
      'Açılımcı olarak katılanlar bir sonraki çalışmaya temsilci olarak ücretsiz katılabilir.']);
  $yuz = feeCard('Grup aile dizimi', 'Yüz yüze · İstanbul · 11.00-18.00', [['Açılımcı', $T('15.000 TL')], ['Temsilci / katılımcı', $T('7.500 TL')]], [
      'Bir tam gün boyunca 8 açılımcıya çalışma yapılır. Açılımcılar seçtikleri konuda kendilerine özel dizim yapar; temsilciler çalışmalarda rol alarak ortak alandan faydalanır.',
      'Gün içindeki açık büfe yiyecek ve içecek ikramı ücrete dahildir. Ücretler bir günlük katılım içindir.',
      'Tarihler etkinlikler sayfasında duyurulur. Tam adres kayıttan sonra iletilir.',
      'Kayıttan sonraki 24 saat içinde ödeme yapılmazsa kayıt talebi iptal olur.'])
    . feeCard('Bireysel seans', 'Yüz yüze · İstanbul', [['Seans süresi', '1 saat'], ['Seans ücreti', $T('15.000 TL')]], [
      'İlk seansta dinamikler belirlenir ve yol haritası çizilir; gerekirse 1-2 seans daha yapılır.',
      'Konum bilgisi randevunuz kesinleştikten sonra paylaşılır.']);
  $body = <<<HTML

  {$_(pageHead('Çalışmalar ve ücretler', 'Online ve yüz yüze çalışmalarımızın ücretleri ve kayıt adımları. Sormak istediğiniz her şeyi WhatsApp’tan yazabilirsiniz.', 'Bilgilendirme'))}
  <section><div class="wrap">
    <h2 class="reveal">Online çalışmalar</h2>
    <div class="fee-grid">$online</div>
  </div></section>
  <section class="tint"><div class="wrap">
    <h2 class="reveal">Yüz yüze çalışmalar</h2>
    <div class="fee-grid">$yuz</div>
  </div></section>
  <section><div class="wrap narrow">
    <h2 class="reveal">Nasıl kayıt olunur?</h2>
    <ol class="how reveal">
      <li><b>WhatsApp’tan yazın.</b> Hangi çalışmaya katılmak istediğinizi belirtin.</li>
      <li><b>Ödeme bilgisini alın.</b> IBAN bilgisi size WhatsApp’tan iletilir.</li>
      <li><b>Dekontu gönderin.</b> Ödeme dekontunu aynı WhatsApp hattına gönderdiğinizde kaydınız kesinleşir.</li>
      <li><b>Gün ve saat belirlenir.</b> Seansınız ya da çalışmanız için gün ve saat birlikte belirlenir.</li>
    </ol>
    <h2 class="reveal about-h">Aile dizimi nedir?</h2>
    <p class="reveal">Aile dizimi, ailemizden yalnızca kalıtımsal özellikleri değil, ruhsal ve psikolojik bir miras olarak tanımlanabilecek özellikleri de devraldığımızı kabul eder. Amaç bu mirası gün ışığına çıkarmak; böylece kişinin hayatında yaşadığı ve sebebini bilmediği sıkıntıların kaynağını bulmasını ve sorunlarını bu kaynak çerçevesinde çözmesini sağlamaktır.</p>
    <p class="reveal">Yaşam koçu ve kolaylaştırıcı Aynur Tümen eşliğinde bu yolda ilk adımı atabilir; karanlık odanın aydınlıkla dolmasını, sorunlarınızın gün ışığına çıkmasını sağlayabilirsiniz.</p>
    <div class="btn-row reveal"><a class="btn btn-wa" href="{$_(wa('Merhaba, bilgilendirme sayfasını okudum. Kayıt olmak istiyorum.'))}" target="_blank" rel="noopener">{$_(WA_ICON)} WhatsApp’tan yazın</a><a class="btn btn-line" href="/etkinlikler">Yaklaşan etkinlikler</a></div>
    <p class="more-links reveal">Aynur Tümen’i ve aile dizimini daha yakından tanımak için: <a href="/aile-dizimi">Aile dizimi nedir?</a> · <a href="/hakkinda">Hakkında</a> · <a href="/yazilar">Yazılar</a> · <a href="/videolar">Videolar</a></p>
  </div></section>
HTML;
  return $body;
}
function page_bilgi(): string {
  $body = bilgi_body();
  return layout(['title' => 'Çalışmalar ve ücretler', 'path' => '/bilgi', 'body' => $body, 'noindex' => true]);
}

/* ---------- Randevu ---------- */
function page_randevu(array $query): string {
  $_ = fn($x) => $x;
  $services = array_values(array_filter(Store::read('services', []), fn($x) => !empty($x['bookable'])));
  $events = array_values(array_filter(upcomingEvents(), fn($e) => ($e['registration'] ?? true) !== false && Booking::seatsLeft($e) > 0));
  $svcJson = js_json(array_map(function ($x) { $x['name'] = t($x['name']); $x['short'] = t($x['short']); if (array_key_exists('priceLabel', $x)) $x['priceLabel'] = $x['priceLabel'] ? t($x['priceLabel']) : $x['priceLabel']; return $x; }, $services));
  $evJson = js_json(array_map(function ($e) { $e['title'] = t($e['title']); $e['location'] = t($e['location']); $e['description'] = t($e['description']); $e['when'] = ist($e['start']); $e['seats'] = Booking::seatsLeft($e); return $e; }, $events));
  $V = asset_version();
  $body = <<<HTML

  {$_(pageHead('Randevu al', form_wa_on() ? 'Çalışmayı seç, uygun saati belirle, bilgilerini yaz. Talebin ekibimize iletilir, onay için sana WhatsApp’tan döneriz.' : 'Çalışmayı seç, uygun saati belirle, bilgilerini yaz. Talebin hazır bir mesaj olarak WhatsApp’ta açılır.', 'Randevu'))}
  <section class="booking-sec"><div class="wrap">
    <div class="wizard" id="wizard" data-i18n-live
      data-services='{$_(esc($svcJson))}'
      data-events='{$_(esc($evJson))}'
      data-pre-service="{$_(esc($query['hizmet'] ?? ''))}" data-pre-event="{$_(esc($query['etkinlik'] ?? ''))}" data-pay="wa" data-wa="{$_(form_wa_on() ? '1' : '0')}" data-mail="{$_(mail_on() ? '1' : '0')}" data-wa-icon="{$_(esc(WA_ICON))}">
      <ol class="steps-bar" aria-label="Adımlar"><li>Çalışma</li><li>Görüşme şekli</li><li>Tarih ve saat</li><li>Bilgilerin</li><li>Özet</li></ol>
      <div class="wizard-body" aria-live="polite"></div>
      <div class="wizard-nav"><button class="btn btn-line" type="button" data-back>Geri</button><button class="btn btn-violet" type="button" data-next>Devam et</button></div>
    </div>
  </div></section>
HTML;
  return layout(['title' => 'Online Randevu ve Kayıt', 'path' => '/randevu', 'body' => $body, 'client' => true, 'scripts' => '<script src="/js/booking.js?v=' . $V . '" defer></script>']);
}

function okHero(string $h1, string $lead, string $rest): string {
  return <<<HTML

  <section class="page-hero page-hero-ok">
    <div class="aurora" aria-hidden="true"><i></i><i></i><i></i></div>
    <div class="wrap narrow ok-in">
      <div class="ok-mark load-in" aria-hidden="true"><svg viewBox="0 0 52 52"><circle cx="26" cy="26" r="24"/><path d="M15 27l7 7 15-16"/></svg></div>
      <h1 class="load-in" style="--d:150ms">$h1</h1>
      <p class="lead load-in" style="--d:250ms">$lead</p>$rest
    </div>
  </section>
HTML;
}

function page_talep(array $b): string {
  $d = ist($b['start']);
  $first = explode(' ', $b['name'])[0];
  $sent = !empty($_GET['g']);
  $rest = $sent ? "\n      <p class=\"load-in\" style=\"--d:300ms\">Kaydınız alındı ve bilgileriniz ekibimize iletildi. Saatiniz sizin için ayrıldı; onay için size WhatsApp’tan döneceğiz.</p>
      <div class=\"btn-row load-in\" style=\"--d:350ms\"><a class=\"btn btn-line-light\" href=\"/\">Ana sayfaya dön</a></div>
" : "\n      <p class=\"load-in\" style=\"--d:300ms\">Saatin senin için ayrıldı. WhatsApp açılmadıysa aşağıdaki düğmeyle bilgilerini mesaj olarak gönder; onay için sana döneceğiz.</p>
      <div class=\"btn-row load-in\" style=\"--d:350ms\">
        <a class=\"btn btn-wa\" href=\"" . esc(Booking::waUrl($b)) . "\" target=\"_blank\" rel=\"noopener\">" . WA_ICON . " WhatsApp'tan gönder</a>
        <a class=\"btn btn-line-light\" href=\"/\">Ana sayfaya dön</a>
      </div>
" . (mail_on() && $b['email'] !== '' ? "
      <p class=\"small load-in\" style=\"--d:450ms\">Talebinin özeti e-postana da gönderildi.</p>" : '');
  $body = okHero(OFF . esc(str_replace('{ad}', $first, (string)t('Talebin alındı, {ad}.'))) . ON, esc(t($b['itemName'])) . " · {$d['long']}, {$d['hm']}", $rest);
  return layout(['title' => 'Talebin alındı', 'path' => '/randevu', 'body' => $body]);
}

function page_onay(array $b): string {
  $d = ist($b['start']);
  $online = $b['mode'] === 'online';
  $first = explode(' ', $b['name'])[0];
  $rest = "\n      <div class=\"btn-row load-in\" style=\"--d:350ms\">
        " . ($online ? '<a class="btn btn-gold" href="/gorusme/' . esc($b['id']) . '?k=' . esc($b['key']) . '">Görüşme odasına git</a>' : '') . "
        <a class=\"btn btn-line-light\" href=\"/randevu/" . esc($b['id']) . '/takvim.ics?k=' . esc($b['key']) . "\">Takvimime ekle</a>
        <a class=\"btn btn-wa\" href=\"" . wa('Merhaba, ' . $b['itemName'] . ' için kaydımı tamamladım. (' . $b['id'] . ')') . "\" target=\"_blank\" rel=\"noopener\">" . WA_ICON . " WhatsApp'tan yaz</a>
      </div>
      <p class=\"small load-in\" style=\"--d:450ms\">" . ($online ? (mail_on() ? 'Görüşme odasının bağlantısı e-postana da gönderildi. Bu sayfayı yer imlerine ekleyebilirsin.' : 'Görüşme odasının bağlantısı WhatsApp ile de iletilecek. Bu sayfayı yer imlerine ekleyebilirsin.') : 'Adres ve yol tarifi WhatsApp ile iletilecek.') . '</p>';
  $body = okHero(OFF . esc(str_replace('{ad}', $first, (string)t('Kaydın tamamlandı, {ad}.'))) . ON, esc(t($b['itemName'])) . " · {$d['long']}, {$d['hm']}", $rest);
  return layout(['title' => 'Kaydın tamamlandı', 'path' => '/randevu', 'body' => $body]);
}

function page_gorusme(array $b): string {
  $_ = fn($x) => $x;
  $d = ist($b['start']);
  $m = $b['meeting'] ?? [];
  $V = asset_version();
  $L = I18n::lang();
  $body = <<<HTML

  <section class="room-sec">
    <div class="wrap room" id="room" data-i18n-live
      data-start="{$_(esc($b['start']))}" data-provider="{$_(esc($m['provider'] ?? ''))}" data-domain="{$_(esc($m['domain'] ?? ''))}" data-room="{$_(esc($m['room'] ?? ''))}" data-join="{$_(esc($m['joinUrl'] ?? ''))}" data-name="{$_(esc($b['name']))}" data-email="{$_(esc($b['email']))}" data-lang="$L">
      <div class="room-main">
        <div class="stage" id="stage">
          <video id="selfview" autoplay playsinline muted></video>
          <div class="stage-msg" id="stage-msg"><p>Görüşmeden önce kameranı ve mikrofonunu deneyelim. Görüntün kimseye gönderilmez.</p><button class="btn btn-gold" id="test-btn">Kamerayı ve mikrofonu dene</button></div>
          <div class="meter" aria-hidden="true"><i id="meter"></i></div>
        </div>
      </div>
      <aside class="room-side">
        <p class="eyebrow-plain">Görüşmen</p>
        <h1>{$_(esc(t($b['itemName'])))}</h1>
        <p class="when">{$d['long']} · {$d['hm']}</p>
        <p class="countdown" id="countdown" aria-live="polite"></p>
        <ul class="checks"><li id="c-cam">Kamera<span>Denenmedi</span></li><li id="c-mic">Mikrofon<span>Denenmedi</span></li><li id="c-net">Bağlantı<span>…</span></li></ul>
        <button class="btn btn-violet btn-lg" id="join">Görüşmeye katıl</button>
        <p class="small">Odaya seans saatinden 15 dakika önce girebilirsin. Sorun yaşarsan <a href="{$_(wa('Merhaba, görüşme odasına giremiyorum. (' . $b['id'] . ')'))}" target="_blank" rel="noopener">WhatsApp'tan yaz</a>.</p>
        <details class="tips"><summary>Seans için öneriler</summary><ul><li>Sessiz, kimsenin girmeyeceği bir oda seç.</li><li>Kulaklık kullan.</li><li>Yanında kâğıt ve kalem olsun.</li></ul></details>
      </aside>
    </div>
    <div class="call" id="call" hidden><div id="jitsi"></div><button class="btn btn-line-light btn-sm" id="leave">Görüşmeden çık</button></div>
  </section>
HTML;
  $scripts = (($m['provider'] ?? '') === 'jitsi' ? '<script src="https://' . esc($m['domain'] ?? 'meet.jit.si') . '/external_api.js" defer></script>' : '') . '<script src="/js/room.js?v=' . $V . '" defer></script>';
  return layout(['title' => 'Görüşme odası', 'path' => '/gorusme', 'body' => $body, 'bodyClass' => 'is-room', 'client' => true, 'scripts' => $scripts]);
}

function page_notFound(): string {
  return layout(['title' => 'Sayfa bulunamadı', 'body' => pageHead('Aradığın sayfayı bulamadık.', 'Adres değişmiş olabilir. Ana sayfadan devam edebilirsin.') . '<section><div class="wrap"><a class="btn btn-violet" href="/">Ana sayfaya dön</a></div></section>']);
}
function page_message(string $title, string $text): string {
  return layout(['title' => $title, 'body' => pageHead(esc($title), esc($text)) . '<section><div class="wrap"><a class="btn btn-violet" href="/">Ana sayfaya dön</a></div></section>']);
}
