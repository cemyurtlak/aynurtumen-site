<?php
/* Aynur Tümen sitesi – sunucu güncelleme sayfası (Balkar Otomotiv'deki sistemin aynısı).
   Kullanım: https://www.aynurtumen.com.tr/guncelle.php?k=ANAHTAR          → site dosyaları güncellenir
             https://www.aynurtumen.com.tr/guncelle.php?k=ANAHTAR&veri=icerik → ek olarak etkinlik ve hizmet listesi de
                                                                              (sunucudakiler önce app/data/yedek/ içine kopyalanır)
   Kaynak: GitHub deposu GUNCELLE_DEPO'nun main dalı (bilgisayardaki aynurtumen-php klasörü).
   Anahtar: düz hâli yalnız Cem'in bilgisayarında (GUNCELLEME_ANAHTARI.txt) ve GitHub Secrets'ta; sunucuda yalnız özeti
   app/guncelle_anahtar.php içinde durur. Anahtarsız / yanlış anahtarla 404 döner.
   Hiçbir zaman yazılmayanlar: app/ayarlar.env, app/guncelle_anahtar.php, randevular, mesajlar, önbellekler,
   otomatik çeviriler, panelden değişen listeler (yukarıdaki istisna dışında). Dosya silmez. */
declare(strict_types=1);
const GUNCELLE_DEPO = 'cemyurtlak/aynurtumen-site';
const GUNCELLE_DAL  = 'main';
@set_time_limit(110);
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function bulunamadi(): never { http_response_code(404); header('Content-Type: text/html; charset=utf-8'); echo '<!doctype html><title>404</title>404'; exit; }
$ozet_dosya = __DIR__ . '/app/guncelle_anahtar.php';
$ozet = is_file($ozet_dosya) ? (string)(include $ozet_dosya) : '';
$k = (string)($_GET['k'] ?? '');
if ($ozet === '' || $k === '' || !hash_equals($ozet, hash('sha256', $k))) { bulunamadi(); }

header('Content-Type: text/plain; charset=utf-8');
$kilit = fopen(sys_get_temp_dir() . '/aynurtumen_guncelle.lock', 'c');
if (!$kilit || !flock($kilit, LOCK_EX | LOCK_NB)) { echo "Başka bir güncelleme sürüyor, biraz sonra tekrar deneyin.\n"; exit; }

function indir(string $url, int $sure = 60): string {
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => $sure,
    CURLOPT_USERAGENT => 'aynurtumen-guncelle', CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json']]);
  $v = curl_exec($ch); $kod = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
  if ($v === false || $kod !== 200) { throw new RuntimeException("indirilemedi ($kod): $url"); }
  return (string)$v;
}
function temizle(string $d): void {
  if (!is_dir($d)) { return; }
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
  @rmdir($d);
}

// app/data içinde bilgisayardan yönetilen içerik (her güncellemede yazılır)
const ICERIK = ['app/data/posts.json', 'app/data/books.json', 'app/data/settings.json', 'app/data/youtube-fallback.json', 'app/data/instagram-fallback.json'];
// panelden de değişebilen listeler (yalnız &veri=icerik ile yazılır, eskisi yedeklenir)
const PANEL = ['app/data/events.json', 'app/data/services.json'];
// asla yazılmayanlar
const GIZLI = ['app/ayarlar.env', 'app/.env', 'app/guncelle_anahtar.php'];

$panel_de = ($_GET['veri'] ?? '') === 'icerik';
$gecici = sys_get_temp_dir() . '/aynurtumen_guncelle_' . bin2hex(random_bytes(4));
try {
  $commit = '?';
  try { $c = json_decode(indir('https://api.github.com/repos/' . GUNCELLE_DEPO . '/commits/' . GUNCELLE_DAL, 20), true);
        $commit = substr((string)($c['sha'] ?? '?'), 0, 7) . ' – ' . (string)($c['commit']['message'] ?? ''); } catch (Throwable $e) { }
  echo "Depo: " . GUNCELLE_DEPO . " (" . GUNCELLE_DAL . "), commit $commit\n";
  $yerel = PHP_SAPI === 'cli-server' ? getenv('AYNUR_GUNCELLE_ZIP') : false;   // yalnız bilgisayarda deneme için
  $zip = $yerel ? (string)file_get_contents($yerel) : indir('https://codeload.github.com/' . GUNCELLE_DEPO . '/zip/refs/heads/' . GUNCELLE_DAL, 90);
  @mkdir($gecici, 0700, true);
  file_put_contents("$gecici/paket.zip", $zip);
  if (class_exists('ZipArchive')) {
    $z = new ZipArchive(); if ($z->open("$gecici/paket.zip") !== true) { throw new RuntimeException('zip açılamadı'); }
    $z->extractTo("$gecici/x"); $z->close();
  } else { (new PharData("$gecici/paket.zip"))->extractTo("$gecici/x"); }
  $kok = glob("$gecici/x/*", GLOB_ONLYDIR)[0] ?? '';
  if ($kok === '' || !is_file("$kok/index.php") || !is_file("$kok/app/router.php")) { throw new RuntimeException('paket beklenen yapıda değil (index.php / app/router.php yok)'); }
  // Önce paketin PHP dosyalarında yazım hatası var mı bak: hatalı kod yayına çıkmasın
  if (function_exists('exec') && is_executable(PHP_BINARY)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($kok, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
      if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
        $out = []; $rc = 0; @exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($f->getPathname()) . ' 2>&1', $out, $rc);
        if ($rc !== 0 && $out) { throw new RuntimeException('kod hatası, hiçbir dosya değiştirilmedi: ' . implode(' ', $out)); }
      }
    }
  }

  $yazilan = 0; $ayni = 0; $atlanan = 0;
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($kok, FilesystemIterator::SKIP_DOTS));
  foreach ($it as $f) {
    if (!$f->isFile()) { continue; }
    $yol = str_replace('\\', '/', substr($f->getPathname(), strlen($kok) + 1));
    if (str_contains($yol, '..') || str_starts_with($yol, '.git/') || str_starts_with($yol, '.github/') || in_array($yol, GIZLI, true)) { $atlanan++; continue; }
    if (str_starts_with($yol, 'app/locales/otomatik/') || preg_match('#^[^/]+\.md$#', $yol) || in_array($yol, ['dev-router.php', '.gitignore', '.gitattributes'], true)) { $atlanan++; continue; }
    if (str_starts_with($yol, 'app/data/') && !in_array($yol, ICERIK, true) && !($panel_de && in_array($yol, PANEL, true))) { $atlanan++; continue; }
    $hedef = __DIR__ . '/' . $yol;
    if (is_file($hedef) && hash_file('sha256', $hedef) === hash_file('sha256', $f->getPathname())) { $ayni++; continue; }
    if (in_array($yol, PANEL, true) && is_file($hedef)) {   // panelde değişmiş olabilecek listenin eski hâli yedeğe
      @mkdir(__DIR__ . '/app/data/yedek', 0755, true);
      copy($hedef, __DIR__ . '/app/data/yedek/' . date('Ymd-His') . '-' . basename($yol));
    }
    if (!is_dir(dirname($hedef))) { mkdir(dirname($hedef), 0755, true); }
    if (!copy($f->getPathname(), $hedef . '.yeni') || !rename($hedef . '.yeni', $hedef)) { throw new RuntimeException("yazılamadı: $yol"); }
    echo "  yazıldı: $yol\n"; $yazilan++;
  }
  if (function_exists('opcache_reset')) { @opcache_reset(); }
  echo "\n$yazilan dosya güncellendi, $ayni dosya zaten aynıydı, $atlanan dosya atlandı (ayarlar, randevular, mesajlar ve panel kayıtları).\n";
  echo $panel_de ? "Etkinlik ve hizmet listesi de güncellendi (eski hâli app/data/yedek/ içinde).\n" : "Randevular, mesajlar ve panelden girilenler değiştirilmedi.\n";
  echo "BİTTİ ✓\n";
} catch (Throwable $e) {
  http_response_code(500);
  echo "HATA: " . $e->getMessage() . "\nSite değişmedi ya da kısmen güncellendi; hata düzeltilip tekrar çalıştırılabilir.\n";
} finally {
  temizle($gecici);
  flock($kilit, LOCK_UN);
}
