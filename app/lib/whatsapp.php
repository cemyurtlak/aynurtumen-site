<?php
// WhatsApp Cloud API: gelen mesajları alır, yapay zekâ asistanına verir, cevabı gönderir.
// Bilgisayar ya da ngrok gerekmez: Meta mesajı doğrudan https://www.aynurtumen.com.tr/api/whatsapp adresine yollar.
// Ayarlar (yalnız sunucudaki app/ayarlar.env içinde, GitHub'a gitmez):
//   WHATSAPP_TOKEN, WHATSAPP_PHONE_NUMBER_ID, WHATSAPP_VERIFY_TOKEN, WHATSAPP_APP_SECRET, WHATSAPP_API_VERSION
final class WhatsApp {
  static function on(): bool { return env('WHATSAPP_TOKEN') !== '' && env('WHATSAPP_PHONE_NUMBER_ID') !== ''; }

  // Meta'nın ilk kurulumdaki doğrulama isteği (GET ?hub.mode=subscribe&hub.verify_token=..&hub.challenge=..)
  static function verify(array $q): ?string {
    $tok = env('WHATSAPP_VERIFY_TOKEN');
    if ($tok === '' || ($q['hub_mode'] ?? '') !== 'subscribe' || !hash_equals($tok, (string)($q['hub_verify_token'] ?? ''))) return null;
    return (string)($q['hub_challenge'] ?? '');
  }

  // İsteğin gerçekten Meta'dan geldiğini App Secret ile doğrular (App Secret girilmemişse kontrol atlanır)
  static function signatureOk(string $raw): bool {
    $secret = env('WHATSAPP_APP_SECRET');
    if ($secret === '') return true;
    $sig = (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
    return $sig !== '' && hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $sig);
  }

  // Webhook gövdesinden mesajları çıkarır: [ ['id','from','name','type','text'] ... ]
  static function parse(array $body): array {
    $out = [];
    foreach ($body['entry'] ?? [] as $e) foreach ($e['changes'] ?? [] as $c) {
      $v = $c['value'] ?? [];
      $names = [];
      foreach ($v['contacts'] ?? [] as $ct) $names[(string)($ct['wa_id'] ?? '')] = (string)($ct['profile']['name'] ?? '');
      foreach ($v['messages'] ?? [] as $m) {
        $type = (string)($m['type'] ?? '');
        $text = match ($type) {
          'text' => (string)($m['text']['body'] ?? ''),
          'button' => (string)($m['button']['text'] ?? ''),
          'interactive' => (string)($m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? ''),
          'image', 'document' => (string)($m[$type]['caption'] ?? ''),
          default => '',
        };
        $out[] = ['id' => (string)($m['id'] ?? ''), 'from' => (string)($m['from'] ?? ''), 'name' => $names[(string)($m['from'] ?? '')] ?? '',
          'type' => $type, 'text' => trim($text), 'ts' => (int)($m['timestamp'] ?? time())];
      }
    }
    return $out;
  }

  private static function call(array $payload): array {
    $url = (env('WHATSAPP_API_BASE') ?: 'https://graph.facebook.com') . '/' . (env('WHATSAPP_API_VERSION') ?: 'v21.0') . '/' . env('WHATSAPP_PHONE_NUMBER_ID') . '/messages';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . env('WHATSAPP_TOKEN')],
      CURLOPT_POSTFIELDS => json_encode(['messaging_product' => 'whatsapp'] + $payload, JSON_UNESCAPED_UNICODE)]);
    $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    $j = json_decode((string)$res, true) ?: [];
    if ($code !== 200) {
      $err = 'WhatsApp HTTP ' . $code . ': ' . mb_substr((string)($j['error']['message'] ?? $res), 0, 200);
      error_log('[whatsapp] ' . $err);
      return ['ok' => false, 'error' => $err];
    }
    return ['ok' => true, 'id' => (string)($j['messages'][0]['id'] ?? '')];
  }

  static function sendText(string $to, string $text): array {
    return self::call(['recipient_type' => 'individual', 'to' => $to, 'type' => 'text', 'text' => ['preview_url' => true, 'body' => mb_substr($text, 0, 4000)]]);
  }

  static function markRead(string $msgId): void {
    if ($msgId !== '') self::call(['status' => 'read', 'message_id' => $msgId]);
  }
}
