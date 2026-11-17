INSTAGRAM SIPARIŞ SİSTEMİ
=========================

Instagram biyografisindeki linkten açılan bir menü ve sipariş sayfası.
Müşteri ürünleri seçer, adresini yazar, kapıda nakit veya kartla ödemeyi seçer.
Siparişler sizin yönetim panelinize düşer. Minimum sipariş tutarı 200 TL'dir
ve sunucu tarafında da kontrol edilir (müşteri sayfayı kurcalasa bile geçmez).


GEREKSİNİMLER
-------------
- PHP 7.4 veya üstü (WordPress çalışan hemen her hosting yeterlidir)
- PDO SQLite eklentisi (çoğu hostingde açıktır)
- HTTPS (SSL). Hostingin panelinden ücretsiz SSL'i açın.
Veritabanı kurulumu gerekmez; sistem kendi dosyasını ilk açılışta oluşturur.


KURULUM (cPanel / Dosya Yöneticisi)
-----------------------------------
1. siparis.zip dosyasını hostinge yükleyin. Örnek yer: public_html/siparis
   (Adres: https://alanadiniz.com/siparis/). Ayrı bir alt alan adı da olur.
2. Zip'i bulunduğu klasörde "Extract / Çıkar" ile açın.
3. Tarayıcıdan şu adresi açın: https://alanadiniz.com/siparis/yonetim/
   İlk açışta şifre belirlemenizi ister. Bunu yüklemeden hemen sonra yapın;
   sayfayı ilk açan kişi şifreyi belirler.
4. Panelde "Ayarlar" sekmesine gidin: işletme adı, telefon, hazırlık süresi,
   teslimat bölgesi notunu girin. Minimum sipariş 200 TL olarak gelir.
5. "Menü" sekmesinde örnek ürünleri silin ("Örnekleri sil") ve kendi
   kategori ve ürünlerinizi ekleyin. Fotoğraf eklemek isteğe bağlıdır.
6. Müşteri linkini Ayarlar sekmesinden kopyalayın ve Instagram profilinizde
   "Web sitesi / Bağlantı" alanına yapıştırın.


YÜKLEMEDEN SONRA GÜVENLİK KONTROLÜ (1 dakika)
---------------------------------------------
Tarayıcıda şu adreslerin açılmaması (403 veya 404 vermesi) gerekir:
  https://alanadiniz.com/siparis/data/
  https://alanadiniz.com/siparis/inc/boot.php
Açılıyorsa hostingiz .htaccess dosyalarını okumuyor demektir (Nginx olabilir).
Hosting desteğine "data ve inc klasörlerine web erişimini engelleyin" deyin.
Veritabanı dosyasının adına rastgele ek konduğu için ayrıca korunur.


SİPARİŞLER NASIL GELİR
----------------------
- Yönetim panelini işletmedeki telefon veya tabletten açık tutun.
  Sağ üstteki "Sesli uyarı" düğmesine bir kez dokunun; yeni siparişte zil çalar,
  cevaplanmayan sipariş varsa dakikada bir hatırlatır.
- Tarayıcılar arka plandaki sekmeleri yavaşlatır. Telefon kilitliyken uyarı
  gelmeyebilir; ekranı açık ve panel sekmesini ön planda bırakın.
- Her siparişte ad, telefon (ara / WhatsApp), adres (haritada aç), not, ürünler,
  ödeme şekli ve para üstü hesabı görünür. "Fiş yazdır" ile mutfak fişi alınır.
- Durumu ilerlettikçe müşteri kendi takip sayfasında (Hazırlanıyor, Yolda,
  Teslim edildi) bunu görür.
- Ürün tükenince Menü sekmesinde "Satışta" düğmesine dokunun, müşteriye
  "Tükendi" görünür. Kapanırken üstteki "Sipariş alıyor" düğmesini kapatın.


LOGO
----
Logo assets/ klasöründedir: logo.webp (sayfalarda görünen yuvarlak logo),
favicon.png (sekme simgesi), apple-touch-icon.png (telefon ana ekranı),
og.jpg (linki WhatsApp/Instagram'da paylaşınca çıkan görsel).
Logoyu değiştirmek için aynı adlı dosyaların üzerine yazmanız yeterlidir.


YEDEK
-----
Tüm siparişler ve menü data/ klasöründeki siparis-....sqlite dosyasındadır.
Ayda bir bu dosyayı bilgisayarınıza indirin. Fotoğraflar uploads/ klasöründedir.


SIK SORULAN SORUNLAR
--------------------
- "500 Internal Server Error": .htaccess dosyalarındaki "Options" satırlarını
  silin (hostingiz bu ayara izin vermiyor olabilir).
- Fotoğraf yüklenmiyor: uploads klasörünün yazma izni 755 (gerekirse 775) olmalı.
- Sipariş gönderilemiyor: data klasörünün yazma izni 755 (gerekirse 775) olmalı.
- Şifreyi unuttum: data klasöründeki siparis-....sqlite dosyasını silmeden önce
  yedeğini alın. Şifre sıfırlamak için dosyadaki "settings" tablosundan
  admin_hash satırını silmek yeterlidir (DB Browser for SQLite ile açılır);
  sonra /yonetim/ adresi yeniden şifre sorar.


DOSYALAR
--------
index.php          müşteri menüsü ve sepet
siparis.php        müşterinin sipariş takip sayfası
api.php            sipariş alma ve durum sorgulama
yonetim/           satıcı paneli
inc/boot.php       ortak kod ve veritabanı
assets/            görünüm (CSS), sayfa kodları (JS) ve logo dosyaları
data/              veritabanı (tarayıcıdan kapalı)
uploads/           ürün fotoğrafları
