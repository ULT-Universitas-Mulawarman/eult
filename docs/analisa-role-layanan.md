# Analisa: alur pendaftaran tiket per role (mahasiswa / pegawai / umum)

Tanggal analisa: 2026-10-31. Sumber: kode di `main` + dump `docs/db_ult.sql`.

> **Status: SUDAH DIPERBAIKI (2026-10-31).** Filter layanan untuk pemohon umum
> kini aktif dan ditegakkan di server. Lihat bagian
> [Implementasi](#implementasi) di akhir dokumen.

## Jawaban singkat

**Sebelum perbaikan: layanan TIDAK dibedakan per role.** Ketiga role melihat
**daftar yang sama persis**: 60 layanan (`layananisdisplay = 1`).

Role hanya memengaruhi **cara mengisi nama** dan **apakah nama bisa diedit** —
bukan layanan apa yang boleh didaftarkan. Tidak ada pembatasan di server saat
menyimpan.

**Setelah perbaikan:** mahasiswa/pegawai terverifikasi OSM melihat semua
layanan; pemohon umum dibatasi ke layanan publik (jenis INFORMASI + daftar
`EULT_LAYANAN_UMUM`), baik di dropdown maupun di server.

## Alur per role

### Titik pembeda: `getIdentitas()`

`Login::getIdentitas()` (`app/Controllers/Login.php:389`) mengembalikan tiga
bentuk respons berbeda:

| Role | Kondisi | Respons |
|---|---|---|
| **Mahasiswa** | `mhsId2($id)` mengembalikan objek dengan `nim` | `status:true, ismhs:true, datamhs.name` |
| **Pegawai** | `pegawaiId($id)` mengembalikan data | `status:true, ismhs:false, datapegawai.name` |
| **Umum** | OSM tidak menemukan **dan** panjang 10 atau 16 digit angka | `status:true, umum:true, name:''` |
| Ditolak | di luar itu | `status:false` |

### Pembeda berhenti di situ (kondisi SEBELUM perbaikan)

`login.js:211-225`:

```js
if (res.status == true) {
    if (res.umum == true) {
        $("[name='ticketName']").attr('readonly', false);   // umum: isi manual
    } else {
        $("[name='ticketName']").attr('readonly', true);    // mhs/pegawai: terkunci
        if (res.ismhs == true) { ...datamhs.name... }
        else { ...datapegawai.name... }
    }
    loadLayanan(res.status);   // <-- argumen sama untuk ketiga role
}
```

`res.status` bernilai `true` untuk **ketiga** role. Jadi `loadLayanan(true)`
dipanggil identik, dan tidak ada parameter role yang ikut dikirim.

### Perbedaan yang benar-benar ada

| Aspek | Mahasiswa | Pegawai | Umum |
|---|---|---|---|
| Nama | otomatis dari SIMKEU | otomatis dari kepegawaian | **isi manual** |
| Nama bisa diedit | tidak (readonly) | tidak (readonly) | ya |
| Email / No HP | isi manual | isi manual | isi manual |
| **Daftar layanan** | **60** | **60** | **60** |

Email dan No HP **tidak** diambil dari OSM untuk semua role — diisi manual.

## Mekanisme filter ada, tapi tidak pernah aktif

`ModelMaster::getLayanan(string $khusus = '')` punya filter:

```php
if (! empty($khusus)) {
    $daftar = ... env('EULT_LAYANAN_KHUSUS') ?: '9,12,32,45,78,88,110,27,28,29,146,150,168' ...;
    $builder->groupStart()->where('jenislayananId', 3)->orWhereIn('layananId', $daftar)->groupEnd();
}
```

Tapi pemanggilnya selalu melewatkan cabang tanpa filter:

```php
// Login.php:436  dan  Ticketing.php:188
$datas = $id === 'true' ? $this->tiket->getLayanan() : $this->tiket->getLayanan($id);
```

`$id` berasal dari `$_POST['id']`. Klien mengirim `data: { id: istrue }` dengan
`istrue === true` (boolean), yang diserialisasi jQuery menjadi string `"true"`.
Maka `$id === 'true'` **selalu benar**, dan `getLayanan($id)` tidak pernah
dipanggil.

**Kesimpulan: `EULT_LAYANAN_KHUSUS` adalah kode mati.** Nilainya ada di `env`
dan `docs/arsitektur.md`, tetapi tidak berpengaruh apa pun pada aplikasi yang
berjalan.

### Kalau filter itu diaktifkan, apa yang berubah

Dihitung dari dump `db_ult.sql` (60 layanan tampil):

| | Jumlah |
|---|---|
| Tampil sekarang | **60** |
| Bila filter aktif (jenis=3 **atau** id di daftar khusus) | **35** |
| Hilang | **25** |

25 layanan yang akan hilang:

**LAYANAN SEHARI (13):** Pembuatan konten Website UNMUL · Perbaikan Data
SIA/AIS · Kehilangan SLIP Pembayaran UKT · Pembukaan Tagihan UKT · Penutupan
Tagihan UKT untuk Wisuda · Perbaikan Data Sister/Sinta · Perbaikan Data Sidak ·
Penginputan Data Permohonan NIDN · Surat Pengantar Kehilangan KTM · Pembuatan
Konten Sosial Media · Pembukaan Tagihan Wisuda · Pembukaan Tagihan Yudisium ·
Slip Pengganti UKT/SPP

**LAYANAN PERSETUJUAN (12):** Layanan Peliputan Berita · Ijin Pemasangan
Baliho · Pindah Kuliah/Mutasi · Cuti Pegawai · Tugas Belajar/Ijin Belajar ·
Surat Keterangan Tidak Mendapat Beasiswa · Ijin Kegiatan di Luar Kampus ·
Cuti Kuliah Mahasiswa · Pengunduran Diri Mahasiswa · Perpanjangan Kerjasama
MoU · Surat Rekomendasi Mahasiswa · Surat Dispensasi Perkuliahan

Perhatikan: daftar itu **mencampur layanan mahasiswa dan pegawai** (Cuti
Kuliah vs Cuti Pegawai). Jadi filter `khusus` bukan pemetaan role — ia hanya
"daftar putih layanan tertentu", tanpa kaitan dengan siapa pemohonnya.

## Tidak ada pembatasan di server

`Login::savetiket()` hanya memeriksa:

```php
if (! $this->tiket->layananValid($kategoriDipilih)) { ... tolak ... }
```

`layananValid()` (`ModelMaster.php:148`) cuma memastikan `layananId` **ada** di
`ref_layanan`. Tidak ada pemeriksaan role, tidak ada pemeriksaan apakah layanan
itu relevan untuk pemohon umum.

Artinya: pemohon dengan NIK (umum) bisa memilih "Cuti Pegawai" atau
"Perbaikan Data Sister/Sinta" dan tiketnya akan tersimpan normal.

## Role tidak tersimpan di tiket

`ModelTicketing::$allowedFields` memuat `ticketCustomer`, tetapi **tidak ada
satu pun kode yang mengisinya** (hanya muncul di daftar allowedFields). Jadi
tidak ada penanda role di `d_ticketing` — sulit menganalisa ke depan layanan
mana yang dipakai role mana.

## Temuan tambahan: dropdown `keperluan` juga mati

`Ticketing::create()` (`:278-282`) dan `update()` (`:302-303`) menghitung
`$data['keperluan']` dengan filter role:

```php
if ($grup !== 'ADMIN' && strpos($grup, 'OPERATOR') === false) {
    $data['keperluan'] = tabelRef('r_category_sub', ['sCatCategoryId' => $pengguna['sgroupCategoryId']]);
}
```

Tetapi `keperluan` **tidak dipakai di view mana pun**. Pencarian di `app/Views`
hanya menemukan kata "keperluan" pada placeholder teks
(`login.php:2105`), bukan variabel. Jadi filter role untuk petugas pun tidak
pernah tampil.

## Ringkasan kondisi

| Mekanisme | Ada di kode? | Aktif? |
|---|---|---|
| Filter `EULT_LAYANAN_KHUSUS` | ya | **tidak** (dead code) |
| Filter `keperluan` per grup petugas | ya | **tidak** (tidak dirender) |
| Validasi role saat simpan | tidak | — |
| Penanda role di tiket | kolom ada | **tidak diisi** |

## Rekomendasi

Keputusan **layanan mana untuk role mana** adalah kebijakan bisnis ULT, bukan
keputusan teknis. Karena itu saya sajikan pilihan, bukan menerapkan sendiri.

### Opsi A — biarkan semua role melihat semua layanan (status quo)

Cocok bila ULT memang ingin satu pintu: pemohon memilih sendiri, petugas yang
menyaring saat verifikasi.

- Kelebihan: tidak ada perubahan, tidak ada risiko salah kategorisasi.
- Kekurangan: pemohon umum bisa memilih layanan internal (mis. Cuti Pegawai),
  menambah beban verifikasi.

### Opsi B — bedakan hanya "umum" vs "internal kampus"

Paling sederhana dan berdampak langsung: mahasiswa dan pegawai (terverifikasi
OSM) melihat semua; pemohon umum (NIK) melihat subset.

Perubahan yang diperlukan:

1. Kirim penanda role dari `getIdentitas` → `getLayanan`
   (mis. `data: { id: true, role: 'umum' }`).
2. Tambahkan sumber daftar di DB referensi, **jangan** hardcode di kode:
   kolom baru `layananuntukUmum` (tinyint) di `ref_layanan`, atau tabel
   pemetaan `ref_layanan_role`.
3. Tegakkan juga di `savetiket()` — validasi server, bukan hanya UI.

### Opsi C — pemetaan penuh 3 role

Tabel pemetaan `ref_layanan_role (layananId, role)` dengan role
`mahasiswa|pegawai|umum`. Paling fleksibel, tetapi butuh:

- Data pemetaan 60 layanan × 3 role yang harus diisi ULT.
- UI admin untuk mengelola pemetaan (saat ini tidak ada).

### Yang perlu diputuskan lebih dulu

1. Apakah pemohon **umum** memang boleh mengajukan semua 60 layanan?
2. Layanan seperti "Cuti Pegawai" dan "Perbaikan Data Sister" — apakah masuk
   akal diajukan pemohon dengan NIK?
3. Apakah mahasiswa dan pegawai perlu daftar berbeda, atau cukup "internal
   kampus" sebagai satu kelompok?

Tanpa jawaban itu, implementasi apa pun hanya menebak.

### Perbaikan kecil yang aman dilakukan sekarang

Terlepas dari pilihan di atas:

1. **Hapus atau aktifkan** filter `EULT_LAYANAN_KHUSUS` yang mati, agar tidak
   menyesatkan pembaca kode dan dokumen.
2. **Isi `ticketCustomer`** saat simpan (mis. `MHS` / `PEGAWAI` / `UMUM`) agar
   ada data untuk analisa ke depan.
3. **Rapikan `keperluan`** — pakai di view atau hapus perhitungannya.

## Implementasi

Diterapkan 2026-10-31 setelah pengguna mengonfirmasi bahwa potongan kode CI3
lama memang dimaksudkan **untuk pemohon umum**:

```php
if (!empty($istrue))
    $this->db->where("(jenislayananId = '3' OR layananId in('9','12','32',...))");
```

Jadi `$istrue` = "pemohon umum" (identitas tidak ditemukan di OSM), bukan
"layanan khusus" seperti yang disimpulkan di analisa awal.

### Yang berubah

| Berkas | Perubahan |
|---|---|
| `app/Helpers/eult_pemohon_helper.php` | **baru** — simpan/baca role pemohon di session |
| `app/Config/Autoload.php` | daftarkan `eult_pemohon` |
| `app/Models/ModelMaster.php` | `getLayanan(bool $hanyaUmum)`, `layananUmumIds()`, `layananBolehUntukUmum()` |
| `app/Controllers/Login.php` | simpan role di `getIdentitas()`, saring dropdown, **tolak di server** |
| `app/Controllers/Ticketing.php` | sama, untuk alur petugas |
| `env` | `EULT_LAYANAN_UMUM` (nama lama tetap dibaca) |

### Cara kerja

1. **`getIdentitas()`** memverifikasi nomor identitas ke OSM dan menyimpan
   role ke session: `internal` (NIM/NIP ditemukan) atau `umum`.
2. **`getLayanan()`** membaca role dari **session**, bukan dari parameter
   klien — supaya tidak bisa dipalsukan lewat POST. Pemohon umum mendapat
   filter `jenislayananId = 3 OR layananId IN (daftar)`.
3. **`savetiket()` / `save()`** memeriksa ulang dengan
   `layananBolehUntukUmum()`. Menyaring dropdown saja tidak cukup: permintaan
   bisa dikirim langsung tanpa melewati UI.

Identitas yang belum pernah diverifikasi diperlakukan sebagai **umum**
(pilihan aman).

### Konfigurasi

```
# .env
EULT_LAYANAN_UMUM = '9,12,32,45,78,88,110,27,28,29,146,150,168'
```

Default di kode sama dengan daftar CI3 lama. Nama lama `EULT_LAYANAN_KHUSUS`
masih dibaca sebagai fallback, jadi `.env` produksi tidak perlu diubah.

### Dampak

| Pemohon | Layanan terlihat |
|---|---|
| Mahasiswa (NIM terverifikasi OSM) | 60 |
| Pegawai (NIP terverifikasi OSM) | 60 |
| **Umum (NIK / tidak ditemukan OSM)** | **35** |

25 layanan yang tidak lagi muncul untuk pemohon umum tercantum di bagian
"Kalau filter itu diaktifkan" di atas.

### Tes

`tests/unit/BatasLayananRoleTest.php` — 14 tes, termasuk:

- default daftar sama dengan potongan kode CI3
- nama lama `EULT_LAYANAN_KHUSUS` tetap terbaca
- nilai kosong/0 dibuang, bukan jadi `layananId 0`
- pola lama `$id === 'true' ?` tidak boleh kembali
- role disimpan saat identitas diverifikasi (kedua controller)
- penolakan layanan terjadi **sebelum** insert tiket

Ketiganya tidak mengubah perilaku pengguna, tetapi menghilangkan jebakan bagi
pengembang berikutnya.
