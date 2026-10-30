# Analisa: tiket tersimpan walau unggahan gagal

Tanggal analisa: 2026-10-30. Data dari database produksi (read-only).

> **Status: SELESAI DIIMPLEMENTASIKAN** (2026-10-30). P1–P5 sudah terpasang.
> Lihat bagian [Implementasi](#implementasi) di akhir dokumen.

## Ringkasan

Benar. Tiket **sudah di-INSERT dan ter-commit** sebelum berkas diunggah. Bila
unggahan gagal, request berhenti dengan pesan galat tetapi baris tiket tetap
ada. Pengguna melihat "gagal", mengulang, dan lahirlah tiket kedua — duplikat.

## Bukti di produksi

| Metrik | Nilai |
|---|---|
| Total tiket | 39.014 |
| **Tiket tanpa baris `d_archive`** | **4.156 (10,7%)** |
| Kelompok duplikat (identitas+subjek+pesan sama) | 1.929 |
| **Kelompok duplikat yang salah satunya tanpa arsip** | **742** |

Sebaran tiket tanpa arsip **konsisten setiap bulan** (28–357/bulan), termasuk
Oktober 2026 (64) — jadi ini masalah berjalan, bukan sisa data lama.

Contoh terbaru (satu orang, beberapa menit):

```
WPTA-UOQL-001  2026-10-30 16:15:31  Rizky Fitriani Kemala
MSFY-BHFI-001  2026-10-30 16:14:33  Rizky Fitriani Kemala
YDQX-WGXJ-001  2026-10-30 16:13:59  Rizky Fitriani Kemala
```

```
CKBA-CQZB-001  2026-10-29 09:26:26  Nurul Hasanah
GKEC-VDDC-001  2026-10-29 09:22:32  Nurul Hasanah
XVUR-JKMX-001  2026-10-29 09:22:10  Nurul Hasanah
PBUJ-YBZQ-001  2026-10-29 09:22:01  Nurul Hasanah
GWLQ-PPGN-001  2026-10-29 09:21:49  Nurul Hasanah
```

Lima tiket dalam 5 menit untuk orang yang sama — pola percobaan-ulang.

## Akar masalah

### Urutan operasi salah

`Login::savetiket()` (`app/Controllers/Login.php:201-247`):

```php
try {
    $idTiket   = $this->tiket->nomorTiketBerikutnya($kodeAcak);
    $arsipId   = eult_auto_increment(...);        // hitung saja, tidak menulis
    $param     = [... 'ticketArchiveId' => $arsipBaru ...];

    $proses = $this->tiket->tambah('d_ticketing', $param);   // (1) COMMIT tiket
} catch (...) { ... }
finally { $this->tiket->lepasKunciNomorTiket($kodeAcak); }

if ($proses && ... ada berkas ...) {
    eult_upload_ticket($konfig, $paramFile);      // (2) validasi + pindah + d_archive
}
```

`ModelMaster::tambah()` membuka transaksinya sendiri (`transStart` →
`transComplete`), jadi langkah (1) **sudah commit** saat langkah (2) dimulai.
Tidak ada transaksi yang membungkus keduanya.

### Kegagalan unggahan menghentikan request, bukan membatalkan data

`eult_upload_ticket()` memvalidasi ekstensi, magic byte, dan ukuran. Bila
gagal, ia memanggil `eult_message_kirim()`, yang **melempar
`ResponsAwalException`** (`app/Helpers/eult_message_helper.php`). Request
berhenti dan pengguna menerima JSON `status: danger`.

Tetapi baris `d_ticketing` sudah ada, dan `ticketArchiveId` menunjuk ke
`d_archive` yang tidak pernah dibuat.

### Dua pemicu, bukan satu

1. **Berkas tidak dilampirkan sama sekali.** Kolom unggah bertanda `*` di
   view, tetapi `<input type="file">` **tidak punya atribut `required`**, dan
   `Login::savetiket()` **tidak punya aturan validasi** untuk
   `ticketArchiveId`. Akibatnya tiket tanpa berkas tetap tersimpan dengan
   `ticketArchiveId` menggantung.
2. **Unggahan ditolak** (tipe salah, isi tidak cocok, atau melebihi batas).

Keduanya berakhir sama: tiket ada, arsip tidak.

### Tidak ada penjaga duplikat

`d_ticketing.ticketTrackingId` adalah PRIMARY KEY, jadi nomor tiket **tidak
mungkin** ganda. Tetapi isi tiket tidak dijaga apa pun: pengguna boleh
mengirim subjek+pesan identik berkali-kali, dan setiap percobaan mendapat
kode acak baru sehingga nomor tiketnya berbeda.

## Yang sudah benar (jangan diubah)

Dua tempat sudah memakai urutan yang aman:

| Lokasi | Pola |
|---|---|
| `Ticketing::lastValidated()` (`Ticketing.php:946`) | unggah dulu, baru ubah status |
| `Cektiket::saveReplies()` (`Cektiket.php:113-127`) | unggah dulu, baru insert `d_replies` |

Komentar di `Ticketing.php:946` bahkan menjelaskan alasannya. Pola ini tinggal
diterapkan ke dua tempat yang masih salah.

## Rekomendasi

### Prioritas 1 — atomik: validasi unggahan sebelum menulis apa pun

Pecah `eult_upload_ticket()` menjadi dua fase:

| Fase | Fungsi | Menulis? |
|---|---|---|
| Periksa | `eult_upload_validasi($berkas, $konfig)` — ekstensi, magic byte, ukuran | tidak |
| Simpan | `eult_upload_simpan($berkas, $konfig, $paramArsip)` — pindah berkas + insert `d_archive` | ya |

Lalu urutannya di `Login::savetiket()` dan `Ticketing::save()`:

```
1. validasi berkas          -> gagal = berhenti, TIDAK ADA yang tersimpan
2. transStart
3. insert d_ticketing
4. pindah berkas + insert d_archive
5. transComplete            -> gagal = rollback, hapus berkas
```

CI4 mendukung transaksi bersarang (`transDepth`), jadi `ModelMaster::tambah()`
di dalam transaksi luar tidak akan commit lebih awal. Ini sudah diverifikasi
di `BaseConnection::transBegin()`.

Catatan: pemindahan berkas **tidak** bisa di-rollback. Karena itu berkas
dipindah **setelah** insert tiket berhasil, dan bila `transComplete()` gagal
berkas hasil pindah dihapus manual.

### Prioritas 2 — wajibkan berkas di server

Tambahkan aturan validasi agar berkas benar-benar wajib:

```php
'ticketArchiveId' => 'uploaded[ticketArchiveId]|max_size[ticketArchiveId,10240]|ext_in[ticketArchiveId,pdf]'
```

dan atribut `required` pada `<input type="file">`. Tanpa ini, kasus "tidak
melampirkan berkas" tetap menghasilkan tiket tanpa arsip.

Bila berkas memang opsional untuk sebagian layanan, jangan simpan
`ticketArchiveId` sama sekali saat tidak ada berkas — biarkan `NULL`, jangan
menunjuk ID yang tidak ada.

### Prioritas 3 — cegah duplikat

Tiga lapis, dari paling murah:

1. **Idempotency token.** Buat token acak saat form dimuat, simpan di session,
   kirim sebagai hidden input. Tolak submit kedua dengan token yang sama.
   Ini menutup double-click dan retry cepat.
2. **Deteksi tiket serupa.** Sebelum insert, cek tiket dengan `identitas +
   subjek + pesan` sama dalam 10 menit terakhir. Bila ada, kembalikan nomor
   tiket yang sudah ada dengan pesan "permintaan Anda sudah kami terima".
   Ini yang paling ramah pengguna — mereka tidak perlu tahu ada percobaan
   sebelumnya.
3. **UNIQUE KEY** pada kombinasi isi — **tidak disarankan**: pesan bisa sama
   untuk permintaan berbeda yang sah, dan kolom `TEXT` tidak bisa diindeks
   penuh.

### Prioritas 4 — bersihkan data lama (hati-hati)

4.156 tiket tanpa arsip perlu ditinjau, bukan dihapus massal. Sebagian mungkin
sudah ditindaklanjuti petugas dan punya balasan di `d_replies`.

Urutan aman:

1. Identifikasi tiket tanpa arsip **yang juga tidak punya** `d_replies`,
   `d_disposisi`, dan `d_history` — kandidat kuat untuk percobaan gagal.
2. Ekspor daftarnya, minta petugas memverifikasi.
3. Baru hapus, dengan backup database lebih dulu.

Query identifikasi (read-only):

```sql
SELECT t.ticketTrackingId, t.ticketCreated, t.ticketName, t.ticketSubject
  FROM d_ticketing t
 WHERE NOT EXISTS (SELECT 1 FROM d_archive a WHERE a.archiveId = t.ticketArchiveId)
   AND NOT EXISTS (SELECT 1 FROM d_replies r WHERE r.repliesTicketId = t.ticketTrackingId)
   AND NOT EXISTS (SELECT 1 FROM d_disposisi d WHERE d.disposisiTicketId = t.ticketTrackingId)
 ORDER BY t.ticketCreated DESC;
```

### Prioritas 5 — cegah berulang

Tambahkan pemeriksaan berkala ke pipeline (job terjadwal) yang menghitung
tiket tanpa arsip per hari. Bila angkanya naik, ada regresi.

## Berkas yatim

`d_archive` jenis TIKET = 35.917 baris, berkas fisik = 36.021 (selisih 104).
Selisih ini arah sebaliknya: berkas ada tanpa baris — kemungkinan insert
`d_archive` gagal setelah berkas dipindah, atau baris terhapus tanpa berkas
ikut terhapus. Jumlahnya kecil dan tidak berdampak ke pengguna, tetapi
pembersihan berkala layak dipertimbangkan.

## Estimasi dampak perbaikan

| Perbaikan | Menutup kasus |
|---|---|
| Validasi sebelum tulis (P1) | unggahan ditolak → tidak ada tiket |
| Berkas wajib di server (P2) | tiket tanpa berkas |
| Idempotency token (P3.1) | double-click, retry cepat |
| Deteksi tiket serupa (P3.2) | retry setelah galat apa pun |

Bila P1+P2 terpasang, penyebab utama 4.156 tiket hilang. P3 menutup sisanya
dan membuat pengalaman pengguna lebih baik karena mereka menerima nomor tiket
yang sudah ada, bukan pesan galat.

## Implementasi

Semua rekomendasi sudah terpasang (2026-10-30).

### P1 — validasi sebelum tulis, simpan atomik

`app/Helpers/eult_upload_helper.php` dipecah menjadi tiga fungsi:

| Fungsi | Menulis? | Isi |
|---|---|---|
| `eult_upload_periksa()` | **tidak** | ekstensi, magic byte, ukuran |
| `eult_upload_simpan()` | ya | pindah berkas + insert `d_archive` |
| `eult_upload_hapus()` | ya | hapus berkas saat rollback |

`Login::savetiket()` dan `Ticketing::save()` kini:

```
1. eult_upload_periksa()      -> gagal = berhenti, TIDAK ADA yang tersimpan
2. $db->transBegin()
3. insert d_ticketing
4. eult_upload_simpan()       -> insert d_archive
5. $db->transCommit()         -> gagal = rollback + eult_upload_hapus()
```

CI4 mendukung transaksi bersarang (`transDepth`), jadi `ModelMaster::tambah()`
di dalam transaksi luar tidak commit lebih awal. `resetTransStatus()` dipanggil
setelah rollback karena mode strict membiarkan `transStatus = false` dan akan
menggagalkan query berikutnya di request yang sama.

Pemindahan berkas tidak bisa di-rollback database, karena itu `eult_upload_simpan()`
menghapus berkas sendiri bila insert `d_archive` gagal, dan controller
memanggil `eult_upload_hapus()` pada jalur rollback.

### P2 — berkas wajib

- `eult_upload_periksa()` dipanggil dengan `$wajib = true` di portal publik
  (default), sehingga berkas kosong ditolak **sebelum** tiket dibuat.
- `<input type="file">` di view mendapat `required` + `aria-required="true"`.
- Validasi klien menambahkan aturan `ticketArchiveId: required + extension pdf`.

Di `Ticketing::save()` berkas tetap **opsional** (`$wajib = false`) karena
petugas boleh mengubah tiket tanpa mengunggah ulang.

### P3 — pencegahan duplikat

**Lapis 1 — idempotency token** (`eult_submit_token()` di `eult_kode_helper.php`):

- Token disimpan di session, dikirim sebagai hidden input `submitToken`.
- Token yang sama hanya menghasilkan satu tiket; percobaan kedua ditolak
  dengan pesan "sudah diproses sebelumnya".
- Token **dirotasi** setelah sukses, dan nilai barunya dikirim di respons
  (`submit_token`) agar klien menyimpannya. Token tidak dirotasi saat gagal,
  sehingga pengguna bisa memperbaiki berkas lalu mengirim ulang.

**Lapis 2 — deteksi tiket serupa** (`ModelTicketing::tiketSerupa()`):

- Mencari tiket dengan identitas + subjek sama dalam 10 menit terakhir.
- Pesan dibandingkan lewat hash SHA-256 (bukan kolom `TEXT` langsung) agar
  tidak bergantung collation dan tetap cepat.
- Bila cocok, pengguna menerima **nomor tiket yang sudah ada** dengan status
  `success`, bukan pesan galat. Ini yang membuat "gagal lalu coba lagi"
  tidak menghasilkan duplikat.

### P4 — tinjau data lama

```bash
php spark eult:arsip --dry-run              # ringkasan + 20 contoh (default)
php spark eult:arsip --semua                # semua kandidat
php spark eult:arsip --csv=kandidat.csv     # ekspor untuk petugas
php spark eult:arsip --hapus --ya           # hapus (BACKUP DULU)
```

Kandidat hapus = tiket tanpa arsip **dan** tanpa `d_replies` **dan** tanpa
`d_disposisi` **dan** tanpa `d_history`. Tiket yang pernah ditindaklanjuti
petugas tidak pernah diusulkan untuk dihapus, walau arsipnya hilang.

### P5 — deteksi regresi

`.github/workflows/regresi-data.yml` berjalan **harian 06:00 WITA** di
self-hosted runner. Menjalankan `eult:arsip --dry-run` dan **gagal** bila
jumlah kandidat ≠ 0, sehingga regresi terlihat di tab Actions.

### Tes pengunci

`tests/unit/AlurSimpanTiketTest.php` — 23 tes, termasuk:

- `eult_upload_periksa()` tidak boleh memanggil `move/mkdir/unlink/insert`
- validasi berkas harus **sebelum** `transBegin()`
- insert tiket dan arsip harus di antara `transBegin()` dan `transCommit()`
- rollback harus memanggil `eult_upload_hapus()`
- kedua controller tidak boleh memakai pola lama (upload setelah commit)
- kandidat hapus harus mengecualikan tiket berjejak penanganan
- workflow regresi harus terjadwal dan memakai `--dry-run`
