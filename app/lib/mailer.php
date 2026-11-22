<?php
// Onay e-postaları. Sunucunun kendi e-posta gönderimi (PHP mail) kullanılır.
// MAIL_FROM yoksa e-posta gönderilmez, yalnızca sunucu günlüğüne yazılır.
// Hostinger'da MAIL_FROM olarak alan adına ait bir adres (ör. info@aynurtumen.com.tr) kullanılmalıdır.
final class Mailer {
  static function send(string $to, string $subject, string $html): bool {
    $from = env('MAIL_FROM') ?: env('SMTP_USER');
    if ($from === '' || $to === '') { error_log("[e-posta] $to · $subject"); return false; }
    $headers = implode("\r\n", [
      'MIME-Version: 1.0',
      'Content-Type: text/html; charset=UTF-8',
      'From: =?UTF-8?B?' . base64_encode('Aynur Tümen') . "?= <$from>",
      "Reply-To: $from",
    ]);
    $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, $headers, "-f$from");
    if (!$ok) error_log("[e-posta gönderilemedi] $to · $subject");
    return $ok;
  }
}
