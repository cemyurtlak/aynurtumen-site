<?php
// İsteğe bağlı zamanlanmış görev (Hostinger > Gelişmiş > Cron İşleri, 15 dakikada bir):
//   /usr/bin/php /home/KULLANICI/domains/aynurtumen.com.tr/public_html/app/cron.php
// Kurulmasa da site çalışır: takvim ve çeviriler ziyaretçi geldikçe arka planda yenilenir.

require __DIR__ . '/bootstrap.php';
Calendar::refresh();
Translate::syncNow();
Translate::flush();
print_r(YaziCeviri::run(40)['progress'] ?? []);
echo "tamam\n";
