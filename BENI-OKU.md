# Aynur Tümen web sitesi (PHP sürümü)

Bu klasör, sitenin her sıradan PHP hostingde (Hostinger, Niobe, cPanel) çalışan sürümüdür. Görünüm, dört dil, randevu ve yönetim paneli Node.js sürümüyle birebir aynıdır. Kurulum için veritabanı ya da ek program gerekmez.

## Klasörler

```
app/            Sitenin beyni: kod, ayarlar, veriler (dışarıdan erişilemez)
  ayarlar.env   Şifre, e-posta, takvim adresleri (AYARLAR-ORNEK.txt'den kopyalanır)
  data/         Randevular, mesajlar, etkinlikler, yazılar, hizmetler (JSON)
  locales/      Çeviriler (en, ru, de)
  cron.php      İsteğe bağlı zamanlanmış görev
public_html/    Ziyaretçinin gördüğü dosyalar: index.php, stil, yazı tipleri, fotoğraflar
```

## Hostinger'a kurulum

Hosting paketinin içinde aynurtumen.com.tr ayrı bir site olarak eklenir. Aynı paketteki diğer sitelere dokunulmaz, çünkü her sitenin kendi klasörü vardır: `domains/aynurtumen.com.tr/`.

1. hPanel > Web siteleri > **Web sitesi ekle** > "Boş PHP/HTML sitesi" > alan adı: `aynurtumen.com.tr`.
   - Alan adı başka bir firmada (Niobe) kayıtlı olduğu için Hostinger "alan adını yönlendirin" uyarısı verir. Bu adım en sona bırakılır.
2. hPanel > Dosya Yöneticisi > `domains/aynurtumen.com.tr/` klasörüne girin.
   - `aynurtumen-php.zip` dosyasını yükleyip **Çıkart** deyin.
   - Sonuçta aynı klasörde `app` ve `public_html` yan yana olmalı. Varsayılan `public_html` içindeki `default.php` silinebilir.
3. `app/AYARLAR-ORNEK.txt` dosyasının bir kopyasını `app/ayarlar.env` adıyla oluşturun ve doldurun:
   - **ADMIN_PASSWORD:** en az 10 karakter.
   - **MAIL_FROM:** info@aynurtumen.com.tr
   - **ADMIN_EMAIL:** talep bildirimlerinin gideceği adres.
4. hPanel > Gelişmiş > **PHP yapılandırması**: PHP 8.2 ya da 8.3 seçili olsun. `intl` ve `curl` eklentileri açık olmalı; Hostinger'da varsayılan olarak açıktır.
5. Site önizleme adresinden açılıp denenir. Her şey yolundaysa alan adı yönlendirilir:
   - Niobe paneli > aynurtumen.com.tr > Nameserver: Hostinger'ın verdiği adresler (genellikle `ns1.dns-parking.com`, `ns2.dns-parking.com`).
   - Bu adımla eski site kapanır. Birkaç saat içinde yeni site görünür.
6. hPanel > Güvenlik > **SSL**: ücretsiz sertifika kurulur. Ardından "HTTPS'i zorla" açılır.
7. hPanel > E-postalar: info@aynurtumen.com.tr açılır.
8. (İsteğe bağlı) hPanel > Gelişmiş > **Cron İşleri**, 15 dakikada bir:
   `/usr/bin/php /home/KULLANICI/domains/aynurtumen.com.tr/app/cron.php`
   Kurulmasa da takvim ve çeviriler ziyaretçi geldikçe kendiliğinden yenilenir.

## Randevu nasıl işler

- Ziyaretçi çalışmayı, şekli, günü ve saati seçer, bilgilerini yazar, ardından **"WhatsApp'tan gönder"**e basar.
- WhatsApp, Aynur Hanım'ın hattına giden hazır bir mesajla açılır. Sitede fiyat ve ödeme yoktur.
- Talep yönetim panelinde (`/admin`) görünür. Saat 72 saat boyunca başkasına kapalı kalır.
- Görüşme kesinleşince **Onayla**ya basılır. Danışana onay e-postası gider; online görüşmelerde görüşme odası bağlantısı da gönderilir.

## Güncelleme

- **Etkinlikler:** Google Takvim'e eklenir (yönetim paneli > Etkinlikler > Bağlantı ayarları) ya da panelden elle eklenir.
- **Hizmet açıklamaları ve süreler:** Yönetim paneli > Hizmetler.
- **Fotoğraf:** `public_html/images/galeri/<Kategori adı>/` klasörüne konur; sitede kendiliğinden görünür.
- **Yeni çeviriler:** DeepL anahtarı girildiyse yeni metinler kendiliğinden İngilizce, Rusça ve Almancaya çevrilir.

## Bilgisayarda deneme

```
php -S 127.0.0.1:4100 -t public_html dev-router.php
```
