# Aynur Tümen web sitesi (PHP) – yapay zekâ için çalışma notları

Bu klasör **canlı sitenin kaynağıdır** (www.aynurtumen.com.tr, Hostinger). Buradaki değişiklik GitHub'a gönderilince site kendiliğinden güncellenir.

## Kurallar
- Her değişiklik **4 dilde** yapılır: Türkçe, İngilizce, Rusça, Almanca (`app/locales/` ve sayfa metinleri). Biri unutulmaz.
- Her değişiklikten sonra **mobil görünüm** kontrol edilir (dar ekran, ~380 px).
- Ödeme / satın alma yok. Randevular WhatsApp'a, bilgiler önceden doldurulmuş mesajla gider.
- Gizli dosyalar GitHub'a gitmez: `app/ayarlar.env`, `app/guncelle_anahtar.php`, `GUNCELLEME_ANAHTARI.txt`, `SITEYI_GUNCELLE.html` (`.gitignore` içinde). Depo herkese açıktır; içine şifre/anahtar yazılmaz.

## Yapı
- Kök = sunucudaki `public_html`. `index.php` her isteği `app/router.php`'ye verir.
- `app/lib/` iş mantığı, `app/views/` HTML şablonları, `app/locales/` çeviriler, `app/data/` içerik JSON'ları.
- Bilgisayarda deneme: `php -S localhost:8080 dev-router.php` → http://localhost:8080

## Güncelleme nasıl işler
0. Cem'in isteği: değişiklikten sonra commit ve push'u **Claude kendisi yapar** (bilgisayar kullanımıyla GitHub Desktop: özet yaz → Commit to main → Push origin), sonra Actions'ta "BİTTİ ✓"yi kontrol edip Cem'e bildirir.
1. GitHub Desktop → Commit → Push (depo: cemyurtlak/aynurtumen-site, dal: main).
2. GitHub Actions (`.github/workflows/guncelle.yml`) sunucudaki `guncelle.php`'yi gizli anahtarla çağırır.
3. `guncelle.php` depoyu zip olarak indirir, PHP dosyalarını sözdizimi için dener, değişen dosyaları yazar. **Hiçbir dosyayı silmez.**
4. Hiç dokunulmayanlar: ayarlar, randevular (`bookings.json`), mesajlar, otomatik çeviriler, önbellekler.
5. `events.json` ve `services.json` panelden de değişebildiği için yalnız commit mesajında `[icerik]` varsa yazılır (eskisi `app/data/yedek/`e kopyalanır).
