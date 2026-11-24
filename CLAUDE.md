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

## E-posta (şimdilik kapalı)
- Aynur Hanım'ın isteğiyle her şey WhatsApp'a yönleniyor: randevu ve iletişim formu bilgileri WhatsApp'ta hazır mesaj olarak açılır, mesajlar `app/data/messages.json`'a da kaydedilir.
- E-posta kodu yerinde duruyor. Açmak için sunucudaki `app/ayarlar.env`'de `MAIL_ENABLED=1` yapın ve `SMTP_PASS`'e info@aynurtumen.com.tr kutusunun **doğru** şifresini yazın (24.11.2026'da yazılan şifre SMTP girişinde reddedildi). Hostinger editöründe Ctrl+S yetmez, sağ üstteki **Dağıt** düğmesine de basılmalı.
- Açınca test: tek e-posta gönderip Gmail'de başlıklarda `dkim=pass` ve `dmarc=pass` görülmeli.

## Yazı çevirileri (Gemini)
- `app/lib/yaziceviri.php`: 75 yazı EN/RU/DE'ye bir kez çevrilir, `app/data/yazi-ceviri/<dil>.json` içinde saklanır (sunucuda, git'e girmez; guncelle.php dokunmaz).
- Anahtarlar yalnız sunucudaki `app/ayarlar.env` → `GEMINI_KEYS=a,b,c` (Cem kendisi yazar, Dağıt'a basar). Asla git'e/sohbete yazılmaz.
- Çeviri ziyaretçi geldikçe arka planda ilerler (`YaziCeviri::tick`, 2 dk'da bir ~35 sn); elle hızlandırmak için `/api/yazi-cevir?k=<GÜNCELLEME_ANAHTARI>` (veya cron.php). Biten yazı tam çevrilmiş görünür; eksik dilde Türkçe + "Bu yazı Türkçedir" notu ve noindex ile görünür.
- Türkçe yazı değişirse özeti değişir ve o yazı kendiliğinden yeniden çevrilir. Çeviri bitince sitemap/hreflang o dili ekler.

## WhatsApp asistanı
- `app/lib/whatsapp.php` (Meta Cloud API) + `app/lib/asistan.php` (Claude Haiku 5.5). Webhook: `GET/POST /api/whatsapp`. Bilgisayar/ngrok gerekmez.
- Bilgi kaynağı `bilgi_body()` (/bilgi sayfası) + örnek olmayan etkinlikler. Ücret değişince yalnız /bilgi güncellenir.
- Kayıt, dekont, kişisel konu, acil, bilinmeyen → ASISTAN_BILDIRIM_NUMARASI'na bildirim (aynı nedenle 6 saatte bir). Asistan susmaz, ekip dönecek der; ekip Business uygulamasından yazınca (smb_message_echoes) 2 saat araya girmez.
- Ertesi gün takip mesajı 20–23,5. saatte (24 saatlik ücretsiz pencere içinde). Konuşmalar `app/data/whatsapp/` içinde, 90 günde silinir; harcama `_kullanim.json`, olaylar `_gunluk.log`.
- Aylık tavan ASISTAN_AYLIK_TAVAN_USD (varsayılan 5$); Console tarafında da aylık 10$ sınır ve otomatik yükleme kapalı.
- Test: okulun k12 Meta uygulaması + test numarası. Canlıda Aynur Hanım'ın kendi Meta Business hesabı ve numarası (coexistence) bağlanacak.
