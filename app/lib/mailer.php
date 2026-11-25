<?php
// Onay ve bildirim e-postaları.
// SMTP_PASS ayarlıysa e-posta, info@ kutusuyla kimlik doğrulayarak Hostinger SMTP'den gider (önerilen:
// DKIM imzası atılır, SPF/DMARC alan adıyla uyumlu olur, spam'e düşme ihtimali azalır).
// SMTP_PASS yoksa sunucunun kendi gönderimi (PHP mail) kullanılır. MAIL_FROM yoksa hiç gönderilmez.
// Ayarlar app/ayarlar.env içinde: MAIL_FROM, MAIL_FROM_NAME, SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS
// E-posta sistemi şimdilik kapalı: her şey WhatsApp'a yönleniyor. Yeniden açmak için ayarlar.env'de MAIL_ENABLED=1 yapın
// (SMTP_PASS'e info@ kutusunun doğru şifresini yazmayı unutmayın).
function mail_on(): bool { return env('MAIL_ENABLED') === '1' && (env('MAIL_FROM') !== '' || env('SMTP_USER') !== ''); }

final class Mailer {
  // $force: e-posta sistemi kapalı olsa da gönder (yalnız ekibe giden yedek bildirimler için)
  static function send(string $to, string $subject, string $html, bool $force = false): bool {
    if (!mail_on() && !$force) { error_log("[e-posta kapalı] $to · $subject"); return false; }
    $from = env('MAIL_FROM') ?: env('SMTP_USER') ?: 'info@aynurtumen.com.tr';
    if ($from === '' || $to === '') { error_log("[e-posta] $to · $subject"); return false; }
    $name = env('MAIL_FROM_NAME') ?: 'Aynur Tümen';
    $encName = '=?UTF-8?B?' . base64_encode($name) . '?=';
    $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $body = chunk_split(base64_encode($html));
    if (env('SMTP_PASS') !== '') {
      $ok = self::smtp($from, $to, [
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (explode('@', $from)[1] ?? 'localhost') . '>',
        "From: $encName <$from>",
        "To: <$to>",
        "Reply-To: $from",
        "Subject: $encSubject",
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
      ], $body);
      if ($ok) return true;
      error_log("[e-posta] SMTP başarısız, PHP mail deneniyor: $to · $subject");
    }
    $headers = implode("\r\n", ['MIME-Version: 1.0', 'Content-Type: text/html; charset=UTF-8', 'Content-Transfer-Encoding: base64', "From: $encName <$from>", "Reply-To: $from"]);
    $ok = @mail($to, $encSubject, $body, $headers, "-f$from");
    if (!$ok) error_log("[e-posta gönderilemedi] $to · $subject");
    return $ok;
  }

  // Küçük SMTP istemcisi: 465 (SSL) ya da 587 (STARTTLS), AUTH LOGIN
  private static function smtp(string $from, string $to, array $headers, string $body): bool {
    $host = env('SMTP_HOST') ?: 'smtp.hostinger.com';
    $port = (int)(env('SMTP_PORT') ?: '465');
    $user = env('SMTP_USER') ?: $from;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $s = @stream_socket_client(($port === 465 ? 'ssl://' : 'tcp://') . "$host:$port", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) { error_log("[e-posta] SMTP bağlanamadı: $errstr"); return false; }
    stream_set_timeout($s, 20);
    $read = function () use ($s): string { $r = ''; while (($l = fgets($s, 1024)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $r; };
    $cmd = function (string $c, array $okCodes) use ($s, $read): bool {
      if ($c !== '') fwrite($s, $c . "\r\n");
      $r = $read(); $ok = in_array((int)substr($r, 0, 3), $okCodes, true);
      if (!$ok) error_log('[e-posta] SMTP yanıtı: ' . trim($r));
      return $ok;
    };
    $ehlo = 'EHLO ' . (parse_url(env('SITE_URL') ?: 'http://localhost', PHP_URL_HOST) ?: 'localhost');
    $ok = $cmd('', [220]) && $cmd($ehlo, [250]);
    if ($ok && $port !== 465) $ok = $cmd('STARTTLS', [220]) && stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) && $cmd($ehlo, [250]);
    $ok = $ok && $cmd('AUTH LOGIN', [334]) && $cmd(base64_encode($user), [334]) && $cmd(base64_encode(env('SMTP_PASS')), [235])
      && $cmd("MAIL FROM:<$from>", [250]) && $cmd("RCPT TO:<$to>", [250, 251]) && $cmd('DATA', [354]);
    if ($ok) {
      $data = implode("\r\n", $headers) . "\r\n\r\n" . preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $body));
      $ok = $cmd(rtrim($data, "\r\n") . "\r\n.", [250]);
    }
    @fwrite($s, "QUIT\r\n"); fclose($s);
    return $ok;
  }
}
