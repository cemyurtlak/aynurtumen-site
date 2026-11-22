<?php
// Sitedeki etkinliklerin tek kaynağı: Google Takvim'den gelenler + yönetim panelinden elle eklenenler.
// Google Takvim bağlıysa data/events.json içindeki "ornek": true kayıtları gizlenir.
final class Events {
  static function all(): array {
    $conf = Calendar::configured();
    $manual = array_values(array_filter(Store::read('events', []), fn($e) => !(!empty($e['ornek']) && $conf)));
    $list = array_merge($manual, Calendar::events());
    usort($list, fn($a, $b) => strtotime($a['start']) <=> strtotime($b['start']));
    return $list;
  }
  static function find(string $id): ?array { foreach (self::all() as $e) if ($e['id'] === $id) return $e; return null; }
  static function upcoming(): array { $now = time(); return array_values(array_filter(self::all(), fn($e) => strtotime($e['start']) > $now)); }
}
