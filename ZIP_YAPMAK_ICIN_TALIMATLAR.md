# 📦 WordPress Plugin ZIP Oluşturma Talimatları

## 🚀 Hızlı ZIP Oluşturma

### Windows'ta:
1. `auto-license-delivery` klasörüne sağ tıklayın
2. "Send to" > "Compressed (zipped) folder" seçin
3. `auto-license-delivery.zip` dosyası oluşturulacak

### macOS'ta:
1. `auto-license-delivery` klasörüne sağ tıklayın
2. "Compress" seçin
3. `auto-license-delivery.zip` dosyası oluşturulacak

### Linux'ta:
```bash
zip -r auto-license-delivery.zip auto-license-delivery/
```

## 📁 Plugin Dosya Yapısı

```
auto-license-delivery/
├── auto-license-delivery.php    (Ana plugin dosyası)
├── readme.txt                   (WordPress repo bilgileri)
├── uninstall.php               (Kaldırma scripti)
├── includes/
│   ├── index.php               (Güvenlik)
│   ├── functions.php           (Ana fonksiyonlar)
│   └── woocommerce.php         (WooCommerce entegrasyonu)
└── languages/
    └── index.php               (Güvenlik)
```

## ✅ ZIP Kontrolü

ZIP dosyasının içinde şunlar olmalı:
- ✅ auto-license-delivery.php (Ana dosya)
- ✅ readme.txt (WordPress standardı)
- ✅ uninstall.php (Temizlik scripti)
- ✅ includes/ klasörü (Fonksiyonlar)
- ✅ languages/ klasörü (Çeviriler)

## 🔧 WordPress'e Yükleme

1. WordPress Admin → Plugins → Add New
2. "Upload Plugin" butonuna tıklayın
3. ZIP dosyasını seçin
4. "Install Now" tıklayın
5. Plugin'i etkinleştirin
6. License Keys menüsüne gidin
7. Lisans anahtarını girin: `WİO-4142-1544-1151-4441`

## 🎯 Özellikler

✨ **Modern Admin Dashboard**
- Gerçek zamanlı istatistikler
- Animasyonlu sayaçlar
- Gradient tasarım
- Responsive layout

🔑 **Lisans Yönetimi**
- Toplu lisans ekleme
- Otomatik teslimat
- Kullanım takibi
- Progress bar'lar

👤 **Müşteri Paneli**
- Şık kart tasarımı
- Kopyalama özelliği
- Mobil uyumlu
- Sipariş geçmişi

📧 **E-posta Sistemi**
- HTML şablonları
- Responsive tasarım
- Otomatik gönderim
- Profesyonel görünüm

🛡️ **Güvenlik**
- CSRF koruması
- SQL injection önleme
- XSS filtering
- Güvenli IP takibi

⚡ **Performans**
- HPOS uyumlu
- Optimize edilmiş sorgular
- Cache desteği
- Hızlı yükleme

## 💡 İpuçları

- Lisans anahtarları her satıra bir tane olacak şekilde eklenir
- Duplicate anahtarlar otomatik olarak kaldırılır
- İstatistikler gerçek zamanlı güncellenir
- Müşteriler "My Account" bölümünden lisanslarını görebilir

## 🔒 Lisans Anahtarı

Plugin'i etkinleştirdikten sonra şu lisans anahtarını girin:
```
WİO-4142-1544-1151-4441
```

Bu anahtar ile tüm özellikler aktif hale gelir.

## 📞 Destek

Herhangi bir sorun yaşarsanız:
- Plugin ayarlarını kontrol edin
- WooCommerce'in aktif olduğundan emin olun
- PHP 7.4+ kullandığınızdan emin olun
- WordPress 5.6+ kullandığınızdan emin olun

---

🎉 **Artık WordPress'e yüklenmeye hazır, profesyonel bir plugin'iniz var!**