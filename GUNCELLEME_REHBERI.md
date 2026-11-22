# Siteyi güncelleme rehberi

Bu klasördeki bir dosyayı değiştirip GitHub Desktop'ta **Commit** + **Push origin** yapınca, yaklaşık 30 saniye içinde www.aynurtumen.com.tr güncellenir. Elle dosya yüklemek gerekmez.

## Bir kerelik kurulum
1. **GitHub Desktop** → File → *Add local repository* → bu klasörü (`aynurtumen-php`) seçin.
   "Bu bir git deposu değil" derse **create a repository** bağlantısına tıklayın → *Create repository*.
2. Sağ üstte **Publish repository** → Name: `aynurtumen-site` → **"Keep this code private" işaretini KALDIRIN** (sunucu depoyu şifresiz indiriyor) → Publish.
3. Tarayıcıda github.com/cemyurtlak/aynurtumen-site → **Settings → Secrets and variables → Actions → New repository secret**
   - Name: `GUNCELLEME_ANAHTARI`
   - Secret: bu klasördeki **GUNCELLEME_ANAHTARI.txt** dosyasının içeriği (tek satır, başında/sonunda boşluk olmadan)
4. Depoda **Actions** sekmesi → "Siteyi guncelle" → *Run workflow*. Yeşil tik ve çıktının sonunda **BİTTİ ✓** görünmeli.

## Günlük kullanım
- Değiştir → GitHub Desktop'ta özet yaz → **Commit to main** → **Push origin**.
- Etkinlik/hizmet listesini (`app/data/events.json`, `services.json`) bilgisayardan değiştirdiyseniz commit özetine **[icerik]** ekleyin. Eklemezseniz paneldeki liste korunur.
- GitHub çalışmazsa: **SITEYI_GUNCELLE.html** dosyasını çift tıklayın → düğmeye basın (aynı işi elle yapar).

## Güvenlik
- `GUNCELLEME_ANAHTARI.txt`, `SITEYI_GUNCELLE.html`, `app/ayarlar.env` GitHub'a **gitmez** (.gitignore). Kimseyle paylaşmayın.
- Anahtar değiştirmek isterseniz bana (Claude'a) söyleyin; sunucudaki `app/guncelle_anahtar.php` de birlikte değişmeli.
- Güncelleme sunucudaki hiçbir dosyayı silmez; randevular, mesajlar ve şifre ayarları hiç değişmez.
