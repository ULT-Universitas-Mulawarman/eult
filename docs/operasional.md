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
| `--since=YYYY-MM-DD` | Batas awal (default `2026-10-26`) |
| `--batalkan` | Kurangi 8 jam (membatalkan koreksi) |

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
| PHP | `php.ini` FPM 8.5 / pool | `upload_max_filesize 2M`, `post_max_size 8M` | ❗ **harus dinaikkan** |
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

Pilih salah satu:

**A. Virtualmin (disarankan, tanpa edit file)**

> Virtualmin → domain `eult.unmul.ac.id` → **Server Configuration →
> PHP Options** → ubah:
> - `Maximum allowed file size for uploads` → `10M` (atau lebih)
> - `Maximum HTTP POST size` → `12M` (harus ≥ upload, beri ruang header)
>
> Simpan, lalu `systemctl reload php8.5-fpm`.

**B. Edit pool FPM**

```bash
sudo tee -a /etc/php/8.5/fpm/pool.d/1792665430372593.conf >/dev/null <<'EOF'
php_value[upload_max_filesize] = 10M
php_value[post_max_size] = 12M
EOF
sudo systemctl reload php8.5-fpm
```

**Verifikasi:**

```bash
php -i | grep -E 'upload_max_filesize|post_max_size'
# harus: upload_max_filesize => 10M, post_max_size => 12M
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
