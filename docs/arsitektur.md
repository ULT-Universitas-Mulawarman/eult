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
