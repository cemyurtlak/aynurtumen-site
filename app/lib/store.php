<?php
// Basit JSON dosya deposu (data/ klasörü). Aynı anda iki yazma çakışmasın diye kilit kullanılır.
final class Store {
  private static array $cache = [];

  static function read(string $name, $fallback = []) {
    $file = DATA_DIR . "/$name.json";
    clearstatcache(true, $file);
    if (!is_file($file)) return $fallback;
    $m = filemtime($file) . ':' . filesize($file);
    if (isset(self::$cache[$name]) && self::$cache[$name][0] === $m) return self::$cache[$name][1];
    $v = json_decode((string)file_get_contents($file), true);
    if ($v === null && json_last_error()) return $fallback;
    self::$cache[$name] = [$m, $v];
    return $v;
  }

  static function write(string $name, $value) {
    $file = DATA_DIR . "/$name.json";
    $tmp = $file . '.' . getmypid() . '.tmp';
    file_put_contents($tmp, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    rename($tmp, $file);
    unset(self::$cache[$name]);
    return $value;
  }

  static function update(string $name, $fallback, callable $fn) {
    $lock = fopen(DATA_DIR . "/.$name.lock", 'c');
    flock($lock, LOCK_EX);
    try {
      unset(self::$cache[$name]);
      return self::write($name, $fn(self::read($name, $fallback)));
    } finally { flock($lock, LOCK_UN); fclose($lock); }
  }
}
