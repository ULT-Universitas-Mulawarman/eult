# Operasional E-ULT — zona waktu, unggah, sesi, identitas

Catatan untuk empat issue operasional (2026-10-30) dan cara memverifikasinya.

## 1. Zona waktu WITA

### Masalah

`Config\App::$appTimezone` bernilai `UTC`, padahal server sudah
`Asia/Makassar`. CI4 memanggil `date_default_timezone_set(appTimezone)` saat
boot, sehingga **semua** `date()` di controller menulis UTC ke database.

Akibatnya jam tiket tampil mundur 8 jam di seluruh halaman.

### Perbaikan

`app/Config/App.php`: `appTimezone = 'Asia/Makassar'`.

### Yang perlu diketahui: data lama bercampur

Database berisi **dua zona waktu** karena aplikasi pernah berjalan dengan dua
konfigurasi berbeda:

| Periode | Sumber | Zona tersimpan | Bukti |
|---|---|---|---|
| s/d 2026-10-22 | CI3 | **WITA** | 32.261 dari 38.857 tiket jatuh di jam 08–16 |
| 2026-10-23/24 | — | tidak ada data | masa deploy CI4 |
| 2026-10-25 | CI4 | ambigu (4 dari 8 di jam 00–07) | tidak disentuh default |
| ≥ 2026-10-26 | CI4 | **UTC** | 31 dari 33 tiket jatuh di jam 00–07 |

Mengubah `appTimezone` hanya memperbaiki baris **baru**. Baris lama era CI4
tetap mundur 8 jam sampai dikoreksi.

### Koreksi data

```bash
cd /home/eultunmul/apps/eult

# 1. Lihat dampak tanpa menulis
php spark eult:waktu --dry-run

# 2. Terapkan (default sejak 2026-10-26)
php spark eult:waktu

# 3. Pastikan sudah bersih
php spark eult:waktu --dry-run
```

Opsi:

| Opsi | Arti |
|---|---|
| `--dry-run` | Hanya menampilkan jumlah baris per kolom |
| `--since 2026-10-25` | Batas awal (default `2026-10-26`) |
| `--batalkan` | Kurangi 8 jam (membatalkan koreksi) |

> **Sintaks opsi:** CI4 hanya memisah opsi pada spasi. `--since 2026-10-25`
> adalah bentuk yang dijamin; perintah ini juga menerima `--since=2026-10-25`
> karena `parseCommandLine()` CI4 menyimpan `--since=...` sebagai satu key
> utuh (bukan `since`). Bila ragu, pakai bentuk spasi.

**Hasil dry-run di produksi (2026-10-30 14:58 WITA):**

```
d_ticketing.ticketCreated           131 baris
d_ticketing.ticketAssigned           16 baris
d_ticketing.ticketValidated           9 baris
d_ticketing.ticketSuratCreated       10 baris
d_ticketing.ticketVerified           51 baris
d_ticketing.ticketRejected            6 baris
d_disposisi.disposisiTanggal         37 baris
d_disposisi.disposisiTanggalAkhir    38 baris
d_replies.repliesDate                27 baris
d_history.tglHistory                297 baris
s_user.susrLastLogin                 15 baris

Total baris terdampak: 637
```

Perintah ini **idempoten**: kolom yang sudah dikoreksi dicatat di tabel
`eult_waktu_koreksi`, jadi dijalankan dua kali tidak menambah 8 jam lagi.

Kolom yang dikoreksi: `d_ticketing` (9 kolom waktu), `d_disposisi` (2),
`d_replies.repliesDate`, `d_history.tglHistory`, `s_user.susrLastLogin`.

> **Backup dulu.** Deploy otomatis sudah membuat arsip di
> `/home/eultunmul/apps/eult-backups/`, tetapi arsip itu hanya berisi kode,
> **bukan** database. Untuk perubahan data, buat dump lebih dulu:
> `mysqldump ... db_eultunmul > ~/db_eultunmul-sebelum-waktu.sql`

## 2. Batas unggah 10 MB

### Tiga lapis batas

Berkas harus melewati tiga lapis; yang paling kecil menentukan:

| Lapis | Lokasi | Nilai sebelum | Target |
|---|---|---|---|
| nginx | `/etc/nginx/sites-enabled/eult.unmul.ac.id.conf` | `client_max_body_size 20M` | ✅ cukup |
| PHP | `/etc/php/8.5/fpm/php.ini` (bukan CLI/CGI) | `upload_max_filesize 2M`, `post_max_size 8M` | ❗ **harus dinaikkan** |
| Aplikasi | `eult_upload_max_kb()` | 15–20 MB tidak konsisten | ✅ 10 MB |

### Perbaikan aplikasi (sudah)

- Satu sumber kebenaran `eult_upload_max_kb()` / `eult_upload_max_mb()`,
  default 10 MB, override lewat `.env`: `EULT_UPLOAD_MAX_MB = 10`.
- Enam tempat yang sebelumnya menulis `15 * 1024` / `20 * 1024` kini memakai
  helper tersebut.
- Pesan galat berbahasa Indonesia dan menyebut angka batas
  (`eult_upload_pesan_galat()`), termasuk kasus berkas melebihi batas PHP.

### Perbaikan PHP (perlu root — belum dilakukan)

`upload_max_filesize = 2M` membuat berkas > 2 MB **ditolak PHP sebelum
sampai ke kode aplikasi**, jadi pesan dari aplikasi tidak akan muncul.

#### File mana yang benar?

Situs `eult.unmul.ac.id` dilayani **php8.5-fpm**, bukan CLI dan bukan CGI:

```
nginx fastcgi_pass unix:/run/php/1792665430372593.sock
  -> /etc/php/8.5/fpm/pool.d/1792665430372593.conf  (user = eultunmul)
  -> /etc/php/8.5/fpm/php.ini                        <- INI yang dibaca
```

| File | Dipakai oleh | Perlu diubah? |
|---|---|---|
| `/etc/php/8.5/fpm/php.ini` | **php8.5-fpm** (web) | ✅ **ya, ini yang benar** |
| `/etc/php/8.5/cli/php.ini` | `php spark`, composer, cron | ❌ tidak |
| `/etc/php/8.5/cgi/php.ini` | CGI (tidak dipakai situs ini) | ❌ tidak |
| `/etc/php/8.4/*` | pool `antrianult` (situs antrian) | ❌ tidak |

> **Jebakan:** halaman *PHP Configuration Files* di Virtualmin hanya
> menampilkan varian **CLI** dan **CGI**. File **FPM** tidak muncul di sana,
> padahal itulah yang melayani situs. Mengedit CLI hanya berpengaruh pada
> `php spark migrate` dan sejenisnya — tidak pada unggahan lewat browser.
>
> Juga: `php -i` dari terminal menampilkan php.ini **CLI**, bukan FPM.
> Untuk memeriksa nilai FPM, baca filenya langsung atau pakai `phpinfo()`
> lewat web.

#### Pilih salah satu

**A. Virtualmin PHP Options (disarankan — per domain, tanpa edit file)**

> Virtualmin → domain `eult.unmul.ac.id` → **Server Configuration →
> PHP Options** → ubah:
> - `Maximum allowed file size for uploads` → `10M`
> - `Maximum HTTP POST size` → `12M` (harus ≥ upload, beri ruang header)
>
> Simpan. Virtualmin menulisnya ke pool sebagai `php_admin_value` dan
> me-reload FPM sendiri.

**B. Edit pool FPM (per domain, tanpa menyentuh situs lain)**

```bash
sudo tee -a /etc/php/8.5/fpm/pool.d/1792665430372593.conf >/dev/null <<'EOF'
php_admin_value[upload_max_filesize] = 10M
php_admin_value[post_max_size] = 12M
EOF
sudo systemctl reload php8.5-fpm
```

**C. Edit `/etc/php/8.5/fpm/php.ini` (GLOBAL — hati-hati)**

```bash
sudo sed -i 's/^upload_max_filesize = 2M/upload_max_filesize = 10M/' /etc/php/8.5/fpm/php.ini
sudo sed -i 's/^post_max_size = 8M/post_max_size = 12M/'       /etc/php/8.5/fpm/php.ini
sudo systemctl reload php8.5-fpm
```

> Perubahan ini berlaku untuk **semua** situs di php8.5-fpm: `eult`,
> `ppid-ult`, dan `ult`. Bila hanya E-ULT yang butuh 10 MB, pakai opsi A
> atau B.

#### Verifikasi

Jangan pakai `php -i` (itu CLI). Buat file cek sementara di docroot:

```bash
cat > /home/eultunmul/public_html/_cek.php <<'EOF'
<?php echo ini_get('upload_max_filesize'), ' / ', ini_get('post_max_size');
EOF
curl -s -H 'Host: eult.unmul.ac.id' http://192.168.55.105/_cek.php; echo
rm -f /home/eultunmul/public_html/_cek.php
# harus: 10M / 12M
```

> `post_max_size` sengaja lebih besar dari `upload_max_filesize` karena
> multipart menambah overhead; kalau sama, berkas tepat 10 MB bisa gagal.

## 3. Masa sesi

`Config\Session::$expiration`: `7200` (2 jam) → **`28800` (8 jam)**.

CI4 juga menyetel `session.gc_maxlifetime` = nilai ini, jadi sesi tidak
dihapus garbage collector lebih awal. Override lewat `.env`:

```
session.expiration = 28800
```

Batas atas yang wajar 24 jam — sesi lebih panjang memperbesar risiko bila
perangkat dipakai bersama.

## 4. Identitas: NIM lama 10 digit & NIK KTP 16 digit

### Masalah

Dua endpoint memakai aturan berbeda:

| Endpoint | Sebelum | Akibat |
|---|---|---|
| `Login::getIdentitas()` (portal publik) | `strlen === 10` | NIK 16 digit **ditolak** |
| `Ticketing::getIdentitas()` (petugas) | `strlen === 16` | NIM 10 digit **ditolak** |

Commit `7923bcb` mengubah `16` → `10` di `Login.php` saja, sehingga NIK KTP
16 digit tidak bisa mengajukan tiket dari portal publik.

### Perbaikan

Keduanya kini memakai aturan yang sama:

```php
if (in_array(strlen($id), [10, 16], true) && ctype_digit($id)) {
    // pemohon umum: nama diisi manual
}
```

Urutan tetap: **cek OSM dulu** (`mhsId2()` untuk NIM, `pegawaiId()` untuk
NIP). Blok pemohon umum hanya berlaku bila OSM tidak mengembalikan data.

| Panjang | Perlakuan |
|---|---|
| 10 | Cek OSM (NIM lama); bila tidak ada → pemohon umum |
| 16 | Cek OSM; bila tidak ada → pemohon umum (NIK KTP) |
| 18 | Cek OSM (NIP); bila tidak ada → ditolak |
| lainnya | Ditolak |

Sisi klien (`login.js`) sudah memicu pemeriksaan untuk panjang 10, 14, 16,
18 — konsisten dengan aturan server.

## Verifikasi

```bash
# Unit test (150 tes, termasuk 19 tes untuk keempat issue ini)
vendor/bin/phpunit --no-coverage --testsuite Unit

# Cek konfigurasi aktif
php spark config:check App appTimezone
php spark config:check Session expiration

# Cek zona waktu efektif
php -r 'require "vendor/autoload.php"; echo date_default_timezone_get(), PHP_EOL;'
```

Tes `tests/unit/PengaturanOperasionalTest.php` mengunci keempat perbaikan agar
tidak regresi, termasuk memastikan kedua controller memakai aturan panjang
identitas yang sama dan pengecekan OSM tetap **sebelum** fallback umum.
