<?php
// Şablon yardımcıları
function esc($s): string { return strtr((string)($s ?? ''), ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#39;']); }

// Şablon metninde ifade yazmak için: "{$_(ifade)}"
$GLOBALS['_'] = fn($v) => $v;

// public/images klasörüne konan görseli bulur (portre.jpg, portre.png, portre.webp ...)
function image(string $name): ?string {
  foreach (['webp', 'jpg', 'jpeg', 'png'] as $ext) if (is_file(PUBLIC_DIR . "/images/$name.$ext")) return "/images/$name.$ext";
  return null;
}

function list_dir(string $dir): array {
  if (!is_dir($dir)) return [];
  $f = array_values(array_diff(scandir($dir), ['.', '..']));
  sort($f, SORT_STRING);
  return $f;
}
const IMG_RE = '/\.(jpe?g|png|webp)$/i';

function gallery(string $dir): array {
  return array_map(fn($f) => "/images/$dir/" . js_uri($f), array_values(array_filter(list_dir(PUBLIC_DIR . "/images/$dir"), fn($f) => preg_match(IMG_RE, $f))));
}

// galeri/ klasörü: alt klasörler kategori olur
function galleryTree(string $dir): array {
  $root = PUBLIC_DIR . "/images/$dir";
  $out = [];
  $pretty = fn($s) => upper_first(preg_replace('/^\d+\s*/', '', preg_replace('/[-_]+/', ' ', $s)));
  foreach (list_dir($root) as $f) {
    if (is_dir("$root/$f")) {
      foreach (list_dir("$root/$f") as $g) if (preg_match(IMG_RE, $g)) $out[] = ['src' => "/images/$dir/" . js_uri($f) . '/' . js_uri($g), 'cat' => $pretty($f)];
    } elseif (preg_match(IMG_RE, $f)) $out[] = ['src' => "/images/$dir/" . js_uri($f), 'cat' => 'Genel'];
  }
  return $out;
}

function tl($n): string { return $n === null ? '' : '₺' . num_loc($n, 'tr-TR'); }
function waLink($phone, string $text): string { return "https://wa.me/$phone?text=" . js_uri((string)t($text)); }
const WA_ICON = '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.1l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2-.1-.1-.3-.2-.5-.3Z"/></svg>';

function ist($d): array { return I18n::dateParts($d); }
function durLabel($min): string { return I18n::duration((int)$min); }
function settings(): array { return Store::read('settings', []); }
function wa(string $text): string { return waLink(settings()['phoneIntl'], $text); }
function join_map(array $list, callable $fn): string { $o = ''; foreach (array_values($list) as $i => $x) $o .= $fn($x, $i); return $o; }
const OFF = '<!--i18n-off-->';
const ON = '<!--i18n-on-->';
// İletişim formu doğrudan ekibin WhatsApp'ına gidebilir mi (asistan ayarlı ve bildirim numarası var)
function form_wa_on(): bool { return WhatsApp::on() && preg_replace('/\D/', '', env('ASISTAN_BILDIRIM_NUMARASI')) !== ''; }
function chat_on(): bool { return env('ANTHROPIC_API_KEY') !== '' && env('ASISTAN_KAPALI') !== '1' && env('SOHBET_KAPALI') !== '1'; }
