<?php
// Bağlantı ayarları: önce .env, yoksa yönetim panelinden kaydedilen data/baglantilar.json
final class Config {
  const KEYS = ['GOOGLE_CALENDAR_ICS_URL', 'GOOGLE_BUSY_ICS_URL', 'DEEPL_API_KEY'];
  static function get(string $k): string { return env($k) ?: (string)(Store::read('baglantilar', [])[$k] ?? ''); }
  static function fromEnv(string $k): bool { return env($k) !== ''; }
  static function save(array $values): void {
    Store::update('baglantilar', [], function ($cur) use ($values) {
      foreach (self::KEYS as $k) if (array_key_exists($k, $values)) $cur[$k] = trim((string)$values[$k]);
      return $cur;
    });
  }
}
