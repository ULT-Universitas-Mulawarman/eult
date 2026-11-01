# Arsitektur E-ULT v2 — Catatan Penting yang Tidak Tersimpan di PRODUCT.md

Dokumen ini melengkapi PRODUCT.md/DESIGN.md dengan keputusan implementasi
yang sebelumnya hanya tersirat di kode (hasil review komprehensif
2026-09-17).

## Dua Database

Aplikasi memakai dua skema MySQL sekaligus:

| Skema | Isi | Akses |
|---|---|---|
| `db_newtiket` | Data transaksional EULT (`d_ticketing`, `d_disposisi`, `r_surat`, `s_user`, `d_archive`, referensi `r_*`) | Koneksi `default` (Config/Database.php) |
| `db_ultunmul` | Master layanan kampus (`ref_layanan`, `ref_jenis_layanan`, `ref_unit`) | Koneksi `dbult` **dan** identifier lintas-DB dinamis `<nama DB>.` (ModelMaster::refUlt()) pada koneksi `default` |

Dua jalur akses ke `db_ultunmul` yang ada saat ini:

1. `ModelMaster::dbUlt()` → `Database::connect('dbult')` — dipakai
   `ModelMaster::getLayanan()`.
2. Identifier `<nama DB>.ref_*` yang dibangun dinamis via
   `ModelMaster::refUlt()`/`dbUltName()` (nama dari `EULT_DBULT_NAME`)
   pada join/select `ModelTicketing::byId()/dataById()/disposisiById()/disposisiAll()`.

Jalur (2) hanya bekerja karena kedua skema berada di server yang sama
dengan kredensial yang sama. Bila suatu saat `db_ultunmul` dipindahkan ke
server lain, seluruh identifier lintas-DB harus dimigrasi ke koneksi
`dbult` (atau diganti GRANT lintas-skema) — tidak ada fallback otomatis.

## Enum Grup Pengguna (susrSgroupNama)

| Grup | Pencocokan di kode | Peran |
|---|---|---|
| `ADMIN` | eksak `=== 'ADMIN'` | Akses penuh; lihat semua tiket |
| `OPERATOR` | `strpos($grup, 'OPERATOR') !== false` (prefiks, mis. `OPERATOR AKADEMIK`) | Portal operator; lihat semua tiket pada unitnya |
| `PRODUKSI` | prefiks via strpos → flag `isProduksi` | Produksi surat |
| `VERIFIKATOR` | prefiks via strpos → flag `isVerifikator` | Verifikasi berkas |
| `KASUBBAG` / `DISPOSISI` | string literal pada view/aksi | Alur disposisi BOTTOMUP |
| grup unit lainnya | pemetaan `s_user_group_unit` | Unit kerja: hanya tiket yang didisposisikan ke unitnya |

Pencocokan prefiks berarti grup baru seperti `OPERATOR KEUANGAN`
otomatis mengikuti perlakuan OPERATOR tanpa perubahan kode.

## Data Bisnis yang Bisa Dioverride .env

| Kunci .env | Default di kode | Dipakai |
|---|---|---|
| `EULT_EKTMI_PEJABAT_NAMA` / `_NIP` / `_JABATAN` | Pejabat ULT saat ini | `Ticketing::validasiEktm()` (surat E-KTM) |
| `EULT_LAYANAN_KHUSUS` | `9,12,32,45,78,88,110,27,28,29,146,150,168` | `ModelMaster::getLayanan('khusus')` |

## Alokasi Nomor Tiket

Nomor tiket = 8 huruf acak + `-` + urutan 3 digit (`ModelTicketing::
nomorTiketBerikutnya()`). Pasangan alokasi-nomor + insert dibungkus named
lock MySQL `GET_LOCK('eult_nomor_<kode>')` agar dua permintaan bersamaan
dengan kode acak sama tidak menghasilkan nomor ganda. Perlindungan
tertinggi tetap butuh UNIQUE KEY pada `d_ticketing.ticketTrackingId`
(belum ada di skema).

## Catatan Test

- CI menjalankan suite **Unit** saja (`phpunit.dist.xml`); suite
  **Database**/**Live** butuh MySQL `db_newtiket`/`db_ultunmul` aktif.
- `phpunit.xml` lokal (tidak di-track) menimpa dist — pastikan suite
  `Unit` tetap didefinisikan di sana (lihat komentar dalam file).

## CSRF: Tanpa Rotasi, Umur Cookie = Umur Sesi

`Config\Security::$regenerate = false` dan `$expires = 28800` — **sengaja
berbeda dari default CI4** (`true` / `7200`).

Alasannya operasional, bukan sekadar preferensi. Dengan `$regenerate =
true`, setiap POST yang lolos verifikasi mengganti hash di cookie
`csrf_cookie_name`, sedangkan cookie itu dipakai bersama **seluruh tab**
pada domain yang sama. Token yang tertanam di sebuah halaman hanya
diperbarui pada tab yang melakukan request (`CsrfTokenHeader` +
`public/assets/js/pages/eult-csrf.js`). Akibatnya alur kerja operator yang
wajar — buka daftar tiket, buka detail di tab baru, verifikasi berkas di
sana, kembali ke tab daftar, klik centang hijau — berakhir HTTP 403 dan
dialog "Sesi Berakhir" padahal sesi masih hidup (laporan operator
2026-11-01).

Muat ulang halaman "menyembuhkan" gejala itu karena token dibaca ulang
dari cookie — itulah sebabnya workaround "refresh lalu tampilkan data
lagi" dilaporkan berhasil.

Yang perlu diketahui saat mengubah konfigurasi ini:

- `$tokenRandomize` tetap `true`, jadi setiap output token di-mask
  (mitigasi BREACH) dan hash mentah tidak pernah dikirim ke browser.
- Cookie CSRF hanya ditulis saat hash pertama kali dibuat, jadi umurnya
  dihitung dari kunjungan pertama — bukan diperpanjang tiap request.
  Setelah `$expires` habis, POST berikutnya ditolak 403 dan halaman perlu
  dimuat ulang. Nilai 28800 menyamakannya dengan `Config\Session::$expiration`.
- Penolakan HTTP 403 kini menampilkan pesan dari server bila ada, sehingga
  penolakan otorisasi tiket (`Ticketing::bolehAksesTiket()` →
  "Anda tidak berhak mengakses tiket ini.") tidak lagi disalahartikan
  sebagai sesi berakhir.
- Regresi dikunci oleh `tests/unit/CsrfTokenStabilTest.php`.
