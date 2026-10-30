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

Berkas harus melewati tiga lapis. **Batas efektif = lapis terkecil.**

| Lapis | Lokasi | Nilai sekarang | Peran |
|---|---|---|---|
| nginx | `/etc/nginx/sites-enabled/eult.unmul.ac.id.conf` | `client_max_body_size 20M` | pagar luar |
| PHP | pool `/etc/php/8.5/fpm/pool.d/1792665430372593.conf` | `upload_max_filesize 20M`, `post_max_size 64M` | pagar longgar |
| **Aplikasi** | `eult_upload_max_kb()` | **10 MB** | **penegak aturan** |

**Desain yang dipilih: batas ditegakkan di tingkat aplikasi.** PHP dan nginx
sengaja dibuat longgar (20M/64M) agar berkas sampai ke kode, lalu aplikasi
yang menolak di atas 10 MB dengan pesan berbahasa Indonesia. Keuntungannya:
angka batas cukup diubah di satu tempat (`.env` `EULT_UPLOAD_MAX_MB`) tanpa
menyentuh konfigurasi server.

`post_max_size` (64M) sengaja jauh di atas `upload_max_filesize` (20M) karena
multipart menambah overhead; bila sama, berkas tepat di batas bisa gagal.

**Konsekuensi yang perlu diketahui:**

| Ukuran berkas | Yang menolak | Pesan |
|---|---|---|
| ≤ 10 MB | — | lolos |
| 10–20 MB | aplikasi | Indonesia, menyebut batas 10 MB |
| > 20 MB | nginx (413) | halaman galat mentah nginx |

Baris terakhir adalah satu-satunya celah UX: berkas > 20 MB ditolak nginx
sebelum PHP berjalan, sehingga aplikasi tidak sempat memberi pesan ramah.
Bila ingin ditutup, tambahkan `error_page 413` di konfigurasi nginx (perlu
root). Untuk pemakaian normal (dokumen PDF), 20 MB sudah jauh di atas
kebutuhan.

### Perbaikan aplikasi (sudah)

- Satu sumber kebenaran `eult_upload_max_kb()` / `eult_upload_max_mb()`,
  default 10 MB, override lewat `.env`: `EULT_UPLOAD_MAX_MB = 10`.
- Enam tempat yang sebelumnya menulis `15 * 1024` / `20 * 1024` kini memakai
  helper tersebut.
- Pesan galat berbahasa Indonesia dan menyebut angka batas
  (`eult_upload_pesan_galat()`), termasuk kasus berkas melebihi batas PHP.

### Perbaikan PHP (sudah dilakukan)

PHP sebelumnya `upload_max_filesize = 2M`, sehingga berkas > 2 MB **ditolak
PHP sebelum sampai ke kode aplikasi** dan pesan dari aplikasi tidak muncul.

Kini disetel **20M / 64M** di tingkat pool (bukan php.ini global), supaya
PHP menjadi pagar longgar dan aplikasi yang menegakkan 10 MB.

#### File mana yang benar?

Situs `eult.unmul.ac.id` dilayani **php8.5-fpm**, bukan CLI dan bukan CGI:

```
nginx fastcgi_pass unix:/run/php/1792665430372593.sock
  -> /etc/php/8.5/fpm/pool.d/1792665430372593.conf  (user = eultunmul)
  -> /etc/php/8.5/fpm/php.ini                        <- nilai dasar
```

| File | Dipakai oleh | Diubah? |
|---|---|---|
| `/etc/php/8.5/fpm/pool.d/1792665430372593.conf` | **php8.5-fpm**, khusus E-ULT | ✅ **ya — di sini** |
| `/etc/php/8.5/fpm/php.ini` | dasar untuk semua pool 8.5 | ❌ tidak (global) |
| `/etc/php/8.5/cli/php.ini` | `php spark`, composer, cron | ❌ tidak |
| `/etc/php/8.5/cgi/php.ini` | CGI (tidak dipakai situs ini) | ❌ tidak |
| `/etc/php/8.4/*` | pool `antrianult` (situs antrian) | ❌ tidak |

Mengubah di **pool** (bukan php.ini global) berarti hanya E-ULT yang
terpengaruh — `ppid-ult` dan `ult` tetap memakai nilai dasar.

> **Jebakan:** halaman *PHP Configuration Files* di Virtualmin hanya
> menampilkan varian **CLI** dan **CGI**. File **FPM** tidak muncul di sana,
> padahal itulah yang melayani situs. Mengedit CLI hanya berpengaruh pada
> `php spark migrate` dan sejenisnya — tidak pada unggahan lewat browser.
>
> Juga: `php -i` dari terminal menampilkan php.ini **CLI**, bukan FPM.
> Untuk memeriksa nilai FPM, baca filenya langsung atau cek lewat web.

#### Nilai yang terpasang

```
# /etc/php/8.5/fpm/pool.d/1792665430372593.conf
php_admin_value[upload_max_filesize] = 20M
php_admin_value[post_max_size] = 64M
```

`php_admin_value` (bukan `php_value`) dipakai agar nilai **tidak bisa
ditimpa** `ini_set()` dari kode aplikasi — batas ini jaminan, bukan saran.

#### Verifikasi (jangan pakai `php -i`)

```bash
F=/home/eultunmul/public_html/_cek.php
printf '%s\n' '<?php' \
  'echo ini_get("upload_max_filesize"), " / ", ini_get("post_max_size"), " / ", PHP_SAPI;' > "$F"
curl -s -H 'Host: eult.unmul.ac.id' http://192.168.55.105/_cek.php; echo
rm -f "$F"
# harus: 20M / 64M / fpm-fcgi
```

`PHP_SAPI` harus `fpm-fcgi` — bila `cli-server` atau `cgi-fcgi`, yang dicek
bukan FPM-nya.

**Hasil verifikasi 2026-10-30 15:53 WITA:**

```
upload_max_filesize=20M
post_max_size=64M
memory_limit=128M
sapi=fpm-fcgi
```

Uji unggah nyata ke aplikasi: berkas 5 MB → `error=0` (lolos), berkas 11 MB
→ ditolak aplikasi dengan pesan Indonesia (batas 10 MB).

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
