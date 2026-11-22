<?php
// Küçük iCalendar (.ics) okuyucu: Google Takvim'in gizli iCal adresindeki etkinlikleri,
// tekrarlayanları (RRULE), hariç tutulan günleri (EXDATE) ve tek tek değiştirilmiş tekrarları dahil açar.
final class ICal {
  // Metni etkinlik listesine çevirir
  static function parse(string $text): array {
    $text = preg_replace("/\r\n|\r/", "\n", $text);
    $text = preg_replace("/\n[ \t]/", '', $text); // katlanmış satırları birleştir
    $events = []; $cur = null; $depth = 0;
    foreach (explode("\n", $text) as $line) {
      if ($line === '') continue;
      if ($line === 'BEGIN:VEVENT') { $cur = []; $depth = 0; continue; }
      if ($cur === null) continue;
      if (str_starts_with($line, 'BEGIN:')) { $depth++; continue; } // VALARM vb.
      if (str_starts_with($line, 'END:')) {
        if ($depth > 0) { $depth--; continue; }
        if ($line === 'END:VEVENT') { $events[] = $cur; $cur = null; }
        continue;
      }
      if ($depth > 0) continue;
      if (!preg_match('/^([A-Za-z0-9-]+)((?:;[^:]*)?):(.*)$/s', $line, $m)) continue;
      $name = strtoupper($m[1]); $params = [];
      if ($m[2] !== '') foreach (explode(';', substr($m[2], 1)) as $p) { [$k, $v] = array_pad(explode('=', $p, 2), 2, ''); $params[strtoupper($k)] = trim($v, '"'); }
      $cur[$name][] = ['params' => $params, 'value' => $m[3]];
    }
    return $events;
  }

  static function text(string $v): string {
    return preg_replace_callback('/\\\\([nN,;\\\\])/', fn($m) => ['n' => "\n", 'N' => "\n", ',' => ',', ';' => ';', '\\' => '\\'][$m[1]], $v);
  }
  private static function prop(array $ev, string $k): ?array { return $ev[$k][0] ?? null; }
  private static function str(array $ev, string $k): string { $p = self::prop($ev, $k); return $p ? self::text($p['value']) : ''; }

  // Tarih değeri → [zaman damgası, tüm gün mü, saat dilimi]
  static function date(array $p): array {
    $v = trim($p['value']);
    $isDate = ($p['params']['VALUE'] ?? '') === 'DATE' || preg_match('/^\d{8}$/', $v);
    if ($isDate) { $d = DateTime::createFromFormat('!Ymd', substr($v, 0, 8), new DateTimeZone('UTC')); return [$d->getTimestamp(), true, 'UTC']; }
    if (str_ends_with($v, 'Z')) { $d = DateTime::createFromFormat('Ymd\THis', substr($v, 0, 15), new DateTimeZone('UTC')); return [$d->getTimestamp(), false, 'UTC']; }
    $tzName = $p['params']['TZID'] ?? 'Europe/Istanbul';
    try { $tz = new DateTimeZone($tzName); } catch (Throwable) { $tz = new DateTimeZone('Europe/Istanbul'); $tzName = 'Europe/Istanbul'; }
    $d = DateTime::createFromFormat('Ymd\THis', substr($v, 0, 15), $tz);
    return [$d ? $d->getTimestamp() : 0, false, $tzName];
  }

  private static function duration(string $v): int {
    if (!preg_match('/^([+-])?P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', $v, $m)) return 0;
    $s = ((int)($m[2] ?? 0)) * 604800 + ((int)($m[3] ?? 0)) * 86400 + ((int)($m[4] ?? 0)) * 3600 + ((int)($m[5] ?? 0)) * 60 + (int)($m[6] ?? 0);
    return ($m[1] ?? '') === '-' ? -$s : $s;
  }

  // Tüm örnekleri [from, to] aralığında açar
  static function instances(string $text, int $from, int $to): array {
    $all = self::parse($text);
    $overrides = []; // uid => [başlangıç => etkinlik]
    foreach ($all as $ev) if (self::prop($ev, 'RECURRENCE-ID')) { [$t] = self::date(self::prop($ev, 'RECURRENCE-ID')); $overrides[self::str($ev, 'UID')][$t] = $ev; }
    $out = [];
    foreach ($all as $ev) {
      if (self::prop($ev, 'RECURRENCE-ID') || !self::prop($ev, 'DTSTART')) continue;
      if (strtoupper(self::str($ev, 'STATUS')) === 'CANCELLED') continue;
      [$start, $allDay, $tz] = self::date(self::prop($ev, 'DTSTART'));
      if (self::prop($ev, 'DTEND')) [$end] = self::date(self::prop($ev, 'DTEND'));
      elseif (self::prop($ev, 'DURATION')) $end = $start + self::duration(self::prop($ev, 'DURATION')['value']);
      else $end = $start + ($allDay ? 86400 : 0);
      $len = max(0, $end - $start);
      $uid = self::str($ev, 'UID');
      $ex = [];
      foreach ($ev['EXDATE'] ?? [] as $p) foreach (explode(',', $p['value']) as $v) { [$t] = self::date(['params' => $p['params'], 'value' => $v]); $ex[$t] = true; }
      $starts = self::prop($ev, 'RRULE') ? self::expand($start, $allDay, $tz, self::prop($ev, 'RRULE')['value'], $to) : [$start];
      foreach ($starts as $s) {
        if (isset($ex[$s])) continue;
        $src = $ev; $st = $s; $en = $s + $len;
        if (isset($overrides[$uid][$s])) {
          $src = $overrides[$uid][$s];
          if (strtoupper(self::str($src, 'STATUS')) === 'CANCELLED') continue;
          [$st] = self::date(self::prop($src, 'DTSTART') ?? self::prop($ev, 'DTSTART'));
          if (self::prop($src, 'DTEND')) [$en] = self::date(self::prop($src, 'DTEND')); else $en = $st + $len;
        }
        if ($en < $from || $st > $to) continue;
        $out[] = [
          'uid' => $uid,
          'summary' => trim(self::str($src, 'SUMMARY') ?: self::str($ev, 'SUMMARY')),
          'description' => self::str($src, 'DESCRIPTION') ?: self::str($ev, 'DESCRIPTION'),
          'location' => trim(self::str($src, 'LOCATION') ?: self::str($ev, 'LOCATION')),
          'start' => $st, 'end' => $en, 'allDay' => $allDay,
          'transparent' => strtoupper(self::str($src, 'TRANSP') ?: self::str($ev, 'TRANSP')) === 'TRANSPARENT',
        ];
      }
    }
    return $out;
  }

  const DAYS = ['SU' => 0, 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6];

  // RRULE'a göre başlangıç zamanlarını üretir (DAILY, WEEKLY, MONTHLY, YEARLY; INTERVAL, COUNT, UNTIL, BYDAY, BYMONTHDAY, BYMONTH)
  static function expand(int $start, bool $allDay, string $tz, string $rule, int $to): array {
    $r = [];
    foreach (explode(';', $rule) as $p) { [$k, $v] = array_pad(explode('=', $p, 2), 2, ''); $r[strtoupper($k)] = $v; }
    $freq = $r['FREQ'] ?? ''; $interval = max(1, (int)($r['INTERVAL'] ?? 1));
    $count = isset($r['COUNT']) ? (int)$r['COUNT'] : null;
    $until = isset($r['UNTIL']) ? self::date(['params' => [], 'value' => $r['UNTIL']])[0] + (preg_match('/^\d{8}$/', $r['UNTIL']) ? 86399 : 0) : null;
    $zone = new DateTimeZone($tz);
    $base = (new DateTimeImmutable('@' . $start))->setTimezone($zone);
    $time = [(int)$base->format('H'), (int)$base->format('i'), (int)$base->format('s')];
    $byday = [];
    if (!empty($r['BYDAY'])) foreach (explode(',', $r['BYDAY']) as $d) if (preg_match('/^([+-]?\d+)?(MO|TU|WE|TH|FR|SA|SU)$/', $d, $m)) $byday[] = [$m[1] === '' ? 0 : (int)$m[1], self::DAYS[$m[2]]];
    $bymd = !empty($r['BYMONTHDAY']) ? array_map('intval', explode(',', $r['BYMONTHDAY'])) : [];
    $bymonth = !empty($r['BYMONTH']) ? array_map('intval', explode(',', $r['BYMONTH'])) : [];
    $mk = fn(int $y, int $mo, int $d) => $base->setDate($y, $mo, $d)->setTime(...$time);
    $out = []; $n = 0; $guard = 0;
    $emit = function (DateTimeImmutable $dt) use (&$out, &$n, $start, $count, $until, $to): bool {
      $t = $dt->getTimestamp();
      if ($t < $start) return true;
      if (($until !== null && $t > $until) || ($count !== null && $n >= $count) || $t > $to) return false;
      $out[] = $t; $n++;
      return true;
    };
    $y = (int)$base->format('Y'); $mo = (int)$base->format('n'); $d = (int)$base->format('j');
    if ($freq === 'DAILY') {
      for ($i = 0; $guard++ < 20000; $i += $interval) if (!$emit($base->modify("+$i day"))) break;
    } elseif ($freq === 'WEEKLY') {
      $days = $byday ? array_map(fn($x) => $x[1], $byday) : [(int)$base->format('w')];
      $weekStart = $base->modify('-' . (((int)$base->format('w') + 6) % 7) . ' day'); // Pazartesi
      for ($w = 0; $guard++ < 5000; $w += $interval) {
        $list = [];
        foreach ($days as $dw) $list[] = $weekStart->modify('+' . ($w * 7 + ($dw + 6) % 7) . ' day');
        usort($list, fn($a, $b) => $a <=> $b);
        foreach ($list as $dt) if (!$emit($dt)) break 2;
      }
    } elseif ($freq === 'MONTHLY' || $freq === 'YEARLY') {
      $step = $freq === 'MONTHLY' ? $interval : 12 * $interval;
      for ($k = 0; $guard++ < 3000; $k += $step) {
        $mm = $mo + $k; $yy = $y + intdiv($mm - 1, 12); $mm = ($mm - 1) % 12 + 1;
        $months = $freq === 'YEARLY' && $bymonth ? $bymonth : [$mm];
        $list = [];
        foreach ($months as $m1) {
          $dim = cal_days_in_month(CAL_GREGORIAN, $m1, $yy);
          if ($bymd) foreach ($bymd as $x) { $dd = $x < 0 ? $dim + $x + 1 : $x; if ($dd >= 1 && $dd <= $dim) $list[] = $mk($yy, $m1, $dd); }
          elseif ($byday) foreach ($byday as [$ord, $dw]) {
            $hits = [];
            for ($dd = 1; $dd <= $dim; $dd++) if ((int)date('w', gmmktime(12, 0, 0, $m1, $dd, $yy)) === $dw) $hits[] = $dd;
            if ($ord === 0) foreach ($hits as $dd) $list[] = $mk($yy, $m1, $dd);
            else { $idx = $ord > 0 ? $ord - 1 : count($hits) + $ord; if (isset($hits[$idx])) $list[] = $mk($yy, $m1, $hits[$idx]); }
          } elseif ($d <= $dim) $list[] = $mk($yy, $m1, $d);
        }
        usort($list, fn($a, $b) => $a <=> $b);
        foreach ($list as $dt) if (!$emit($dt)) break 2;
        if ($yy > (int)gmdate('Y', $to) + 1) break;
      }
    } else $out[] = $start;
    return $out;
  }
}
