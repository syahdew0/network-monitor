# PhiNet - Network Monitor for ESP32-C3

<p align="center">
  <img src="https://img.shields.io/badge/Platform-ESP32--C3-blue?style=for-the-badge" alt="Platform ESP32-C3"/>
  <img src="https://img.shields.io/badge/Framework-Arduino-teal?style=for-the-badge" alt="Framework Arduino"/>
  <img src="https://img.shields.io/badge/Version-1.0-orange?style=for-the-badge" alt="Version 1.0"/>
  <img src="https://img.shields.io/badge/License-MIT-green?style=for-the-badge" alt="License MIT"/>
</p>

## 📖 Deskripsi

**PhiNet** adalah perangkat monitoring jaringan berbasis ESP32-C3 yang secara berkala mengirimkan *heartbeat* (sinyal kehidupan) ke endpoint server. Perangkat ini dapat digunakan untuk:

- 📡 Monitoring konektivitas WiFi di lokasi tertentu
- 📊 Pengumpulan data kekuatan sinyal (RSSI)
- 🔔 Alerting ketika perangkat offline
- 🏢 Monitoring jaringan di banyak lokasi secara terpusat
- 🔄 **OTA Update** - Update firmware dari jarak jauh
- ⚙️ **Remote Config** - Ubah konfigurasi perangkat secara remote

## ✨ Fitur Utama

| Fitur | Deskripsi |
|-------|-----------|
| 🌐 **WiFi Manager** | Konfigurasi WiFi via web portal tanpa perlu coding ulang |
| 🔄 **Auto Reconnect** | Otomatis reconnect dengan exponential backoff saat koneksi putus |
| ⏰ **NTP Sync** | Sinkronisasi waktu otomatis dengan server NTP Indonesia |
| 💾 **Persistent Config** | Konfigurasi tersimpan di flash memory (NVS) |
| 🔒 **HTTPS Support** | Mendukung endpoint HTTP dan HTTPS |
| 🔑 **Bearer Token Auth** | Autentikasi via Bearer Token (opsional) |
| 💡 **LED Indicator** | Indikator visual untuk status perangkat |
| 🔘 **Config Button** | Tombol reset untuk masuk ke mode konfigurasi |
| 📡 **OTA Update** | Update firmware via remote server |
| ⚙️ **Remote Actions** | Ubah nama, endpoint, interval dari jarak jauh |

## 📋 Persyaratan Hardware

- **Board**: ESP32-C3 (DevKitM-1 atau sejenisnya)
- **LED**: LED indikator pada GPIO 8 (atau built-in LED)
- **Button**: Tombol BOOT pada GPIO 9 (biasanya sudah ada di board)

### Pinout

| Komponen | GPIO Pin | Keterangan |
|----------|----------|------------|
| LED Indikator | GPIO 8 | LED untuk status operasi |
| Tombol Config | GPIO 9 | Tombol BOOT untuk masuk mode setup |

## 📦 Library yang Dibutuhkan

Pastikan library berikut sudah terinstall di Arduino IDE:

| Library | Versi | Instalasi |
|---------|-------|-----------|
| WiFi | Built-in | Sudah termasuk di ESP32 core |
| WiFiClientSecure | Built-in | Sudah termasuk di ESP32 core |
| HTTPClient | Built-in | Sudah termasuk di ESP32 core |
| Preferences | Built-in | Sudah termasuk di ESP32 core |
| Update | Built-in | Sudah termasuk di ESP32 core |
| **WiFiManager** | Latest | Library Manager → "WiFiManager by tzapu" |
| **ArduinoJson** | Latest | Library Manager → "ArduinoJson by Benoit Blanchon" |

### Instalasi Library

```
Arduino IDE → Tools → Manage Libraries → Cari dan install:
1. "WiFiManager" by tzapu
2. "ArduinoJson" by Benoit Blanchon
```

## ⚙️ Instalasi

### 1. Persiapan Arduino IDE

1. Buka **Arduino IDE** (versi 2.x direkomendasikan)
2. Tambahkan ESP32 board manager:
   - File → Preferences → Additional Board Manager URLs
   - Tambahkan: `https://raw.githubusercontent.com/espressif/arduino-esp32/gh-pages/package_esp32_index.json`
3. Tools → Board → Boards Manager → Install "esp32 by Espressif Systems"

### 2. Pilih Board

```
Tools → Board → ESP32 Arduino → ESP32C3 Dev Module
```

### 3. Konfigurasi Board

| Setting | Value |
|---------|-------|
| Board | ESP32C3 Dev Module |
| USB CDC On Boot | Enabled |
| Upload Speed | 921600 |
| Flash Size | 4MB |
| Partition Scheme | Default 4MB |

### 4. Upload Sketch

1. Hubungkan ESP32-C3 via USB
2. Pilih port yang sesuai di Tools → Port
3. Klik Upload (→)

## 🚀 Cara Penggunaan

### Konfigurasi Awal (First Setup)

1. **Nyalakan perangkat** - LED akan berkedip 3x saat boot
2. **Mode AP aktif** - Jika belum dikonfigurasi, perangkat akan membuat Access Point:
   - **SSID**: `PhiNet-Setup`
   - **Password**: `Admin123`
3. **Hubungkan** ke AP tersebut dari HP/laptop
4. **Buka browser** dan akses `192.168.4.1`
5. **Isi konfigurasi**:
   - Pilih WiFi dan masukkan password
   - Masukkan Heartbeat URL endpoint
   - Masukkan Bearer Token (opsional)
   - Device ID (otomatis terisi)
   - Interval pengiriman (default: 60 detik)
6. **Klik Save** - Perangkat akan restart dan mulai mengirim heartbeat

### Masuk Mode Konfigurasi Ulang

Untuk mengubah konfigurasi yang sudah tersimpan:

1. **Tekan dan tahan** tombol BOOT selama **3 detik**
2. LED akan berkedip cepat 10x menandakan mode AP aktif
3. Ikuti langkah konfigurasi seperti di atas

## 📡 Format Data Heartbeat

Perangkat mengirimkan POST request ke endpoint dengan payload JSON:

```json
{
  "id": "phinet-a4cf12345678",
  "device_id": "Router-Lantai-1",
  "ssid": "Nama-WiFi-Terhubung",
  "rssi": -45,
  "v": "1.0"
}
```

| Field | Deskripsi |
|-------|-----------|
| `id` | Hardware ID permanen (MAC Address), tidak bisa diubah |
| `device_id` | Nama perangkat (dikonfigurasi via WiFiManager) - backward compatible |
| `ssid` | Nama WiFi yang terhubung |
| `rssi` | Kekuatan sinyal dalam dBm |
| `v` | Versi firmware |

### Headers

```
Content-Type: application/json
Authorization: Bearer <token>  (jika token dikonfigurasi)
```

### Contoh Endpoint

```
https://api.example.com/devices/heartbeat
http://192.168.1.100:3000/api/heartbeat
```

## 🔄 Remote Actions (OTA & Remote Config)

Perangkat secara berkala (setiap 30 detik) mengecek remote server untuk pending actions.

### Remote URL

```
https://ota-network.phisoft.co.id/
```

### Supported Actions

| Action | Deskripsi | Value |
|--------|-----------|-------|
| `update` | Update firmware OTA | URL file .bin |
| `changename` | Ubah nama device | Nama baru |
| `changeendpoint` | Ubah heartbeat endpoint | URL endpoint baru |
| `changeinterval` | Ubah interval heartbeat | Interval dalam detik |

### Action Response Format

```json
{
  "actionid": "1234",
  "action": "update",
  "value": "https://ota-network.phisoft.co.id/firmware/v1.1.bin",
  "additional": {}
}
```

### Action Flow

1. Device GET actions dari `REMOTE_URL/remote.php?action=get_actions`
2. Server mengembalikan pending action (jika ada)
3. Device mengeksekusi action
4. Device mengirim log ke `REMOTE_URL/remote.php?action=log`

## 🖥️ Backend PHP

File `remote.php` menyediakan API endpoint untuk:

| Endpoint | Method | Deskripsi |
|----------|--------|-----------|
| `?action=get_actions` | POST | Ambil pending actions untuk device |
| `?action=log` | POST | Simpan log hasil eksekusi action |
| `?action=heartbeat` | POST | Terima heartbeat (alternatif) |
| `?action=create_action` | POST | Buat action baru (admin) |
| `?action=list_devices` | GET | List semua device (admin) |
| `?action=get_device_actions` | GET | History action device (admin) |

### Setup Backend

1. Upload `remote.php` ke server
2. Konfigurasi database di `remote.php`:
   ```php
   $DB_HOST = 'localhost';
   $DB_NAME = 'your_database_name';
   $DB_USER = 'your_username';
   $DB_PASS = 'your_password';
   ```
3. Jalankan `schema.sql` untuk membuat tabel
4. JANGAN meletakkan file network_password.json di lokasi yang bisa diakses secara publik di Production.

## 🗄️ Database Schema

Lihat file `schema.sql` untuk struktur tabel lengkap:

- `device` - Data device
- `device_actions` - Pending & history actions
- `action_logs` - Log eksekusi action
- `devicelogs` - Log status device
- `heartbeats` - History heartbeat

## 💡 Indikator LED

| Pola LED | Status |
|----------|--------|
| Kedip 3x (100ms) | Booting |
| Kedip 10x (50ms) | Masuk mode AP/Konfigurasi |
| Kedip 3x (200ms) | Konfigurasi tersimpan |
| Kedip 5x (150ms) | OTA Update dimulai |
| Menyala solid | Mode normal / Terhubung |
| Mati | Tidak ada daya |

## 🔧 Konfigurasi Default

| Parameter | Nilai Default | Keterangan |
|-----------|---------------|------------|
| VERSION | `1.0` | Versi firmware |
| REMOTE_URL | `https://ota-network.phisoft.co.id/` | Server remote management |
| AP Name | `PhiNet-Setup` | SSID Access Point |
| AP Password | `Admin123` | Password Access Point |
| HTTP Timeout | 8000 ms | Timeout HTTP request |
| Reconnect Base | 1000 ms | Backoff awal reconnect |
| Reconnect Max | 60000 ms | Backoff maksimal |
| Timezone | `WIB-7` | Zona waktu Indonesia Barat |
| NTP Server 1 | `id.pool.ntp.org` | Server NTP utama |
| NTP Server 2 | `pool.ntp.org` | Server NTP backup |
| Default Interval | 60 detik | Interval heartbeat default |
| Action Check Interval | 30 detik | Interval cek remote actions |

## 🔄 Mekanisme Auto-Reconnect

Ketika koneksi WiFi terputus, perangkat akan:

1. Mencoba reconnect segera
2. Jika gagal, menunggu dengan **exponential backoff**:
   - 1 detik → 2 detik → 4 detik → 8 detik → ... → maksimal 60 detik
3. Reset backoff ke 1 detik setelah berhasil connect

## 🛠️ Troubleshooting

### Perangkat tidak bisa connect ke WiFi

1. Pastikan SSID dan password benar
2. Cek jarak perangkat ke router
3. Tekan tombol BOOT 3 detik untuk rekonfigurasi

### Heartbeat tidak terkirim

1. Periksa format endpoint URL (harus lengkap dengan http:// atau https://)
2. Pastikan endpoint server aktif dan accessible
3. Cek token autentikasi jika diperlukan
4. Lihat Serial Monitor (115200 baud) untuk debug

### OTA Update gagal

1. Pastikan URL file .bin valid dan accessible
2. Cek ukuran file tidak melebihi flash yang tersedia
3. Pastikan koneksi internet stabil
4. Lihat log di server untuk detail error

### LED tidak menyala

1. Periksa koneksi LED ke GPIO 8
2. Pastikan LED terpasang dengan polaritas benar

### Tidak bisa masuk mode konfigurasi

1. Pastikan menekan tombol BOOT (bukan EN/Reset)
2. Tahan minimal 3 detik hingga LED berkedip cepat

## 📝 Serial Monitor Output

Buka Serial Monitor pada 115200 baud untuk melihat log:

```
Booting...
PhiNet Version: 1.0
[WiFi] Connect dengan cred tersimpan...
......
[WiFi] Dapat IP: 192.168.1.100
[HB] Kirim heartbeat...
POST ke: https://api.example.com/heartbeat
Payload: {"id":"phinet-a4cf12345678","device_id":"Router-Lantai-1","ssid":"MyWiFi","rssi":-42,"v":"1.0"}
HTTP 200
Resp: {"status":"ok"}
Heartbeat OK
[ACT] Check actions: https://ota-network.phisoft.co.id/remote.php?action=get_actions
[ACT] Response: {}
[ACT] Tidak ada action
```

## 📁 Struktur File

```
phinet/
├── phinet.ino      # Main Arduino sketch
├── remote.php      # PHP backend API
├── schema.sql      # Database schema
└── README.md       # Dokumentasi ini
```

## 📄 Lisensi

MIT License - Bebas digunakan dan dimodifikasi.

## 👥 Kontributor

- **PT Pasifik Hoki Indonesia** - Development & Maintenance

---

<p align="center">
  Made with ❤️ for IoT Network Monitoring
</p>
