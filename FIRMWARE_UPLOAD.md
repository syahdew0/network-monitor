# Firmware Upload Guide

## Cara Upload Firmware Manual

### 1. Compile Firmware di Arduino IDE

1. Buka `heartbeatv1.ino`
2. **Pastikan versi sudah diupdate** di line 12:
   ```cpp
   const char *VERSION = "1.2";  // <-- Ubah ini sebelum compile
   ```
3. Pilih board: `ESP32-C3 Dev Module`
4. Compile: `Sketch` → `Export Compiled Binary`
5. File `.bin` akan disimpan di folder sketch

### 2. Upload ke Server

Gunakan FTP (FileZilla):

1. Connect ke server `phisoft.co.id`
2. Navigate ke `/var/www/monitorv2/firmware/`
3. Upload 2 files:
   - `firmware.ino.bin` (overwrite yang lama)
   - `version.txt` (edit isi dengan versi baru, contoh: `1.2`)

**SELESAI!** Admin panel akan **otomatis detect** versi dari `version.txt`.

> ✅ **Tidak perlu edit `index.php` lagi!** Version detection sekarang otomatis dari `version.txt`.


## Cara Cek Versi Firmware

### Cek Versi di Server
```bash
# Via SSH
cat /var/www/monitorv2/firmware/version.txt

# Via Browser
# Buka: https://monitorv2.phisoft.co.id/firmware/version.txt
```

### Cek Versi di Source Code
```bash
# Sebelum compile, cek di heartbeatv1.ino line 12
grep "VERSION =" heartbeatv1.ino
```

### Cek Versi dari Device
- Login ke admin panel
- Lihat kolom "Firmware Version" di device card
- Device akan report versi saat heartbeat

## Checklist Upload Firmware Baru

- [ ] Update `VERSION` di `heartbeatv1.ino` (line 12)
- [ ] Compile firmware jadi `.bin`
- [ ] Upload `firmware.ino.bin` ke `/var/www/monitorv2/firmware/`
- [ ] Update `version.txt` dengan versi baru
- [ ] Test: Refresh admin panel, lihat versi terbaru
- [ ] Device dengan versi lama akan muncul tombol "Update to vX.X"


## File Locations

```
Server:
/var/www/monitorv2/
├── index.php                    (auto-load version from version.txt)
└── firmware/
    ├── firmware.ino.bin        (binary file, overwrite ini)
    └── version.txt             (version source, edit ini saja)

Local:
heartbeatv1.ino                  (source code, update VERSION di line 12)
```


## Version History

| Version | Date       | Changes                          |
|---------|------------|----------------------------------|
| 1.1     | 2025-12-27 | Current version                  |
| 1.2     | TBD        | Next version (example)           |

## Notes

- ⚠️ **Always update VERSION in source code before compile**
- ⚠️ **Keep version.txt in sync with actual binary**
- ⚠️ **Update index.php after uploading new firmware**
- ✅ Devices will auto-detect update and show "Update" button
