# Deploy E-ULT (CI/CD self-hosted runner)

Pipeline CI/CD berjalan di **self-hosted runner** yang terpasang langsung di
server produksi. Tidak ada server CI terpisah dan tidak ada kredensial deploy
yang disimpan di GitHub — runner sudah berada di mesin tujuan.

## Kondisi server (terverifikasi 2026-10-30)

| Item | Nilai |
|---|---|
| Host | `ulthost.unmul.ac.id` (VM KVM, Sangfor aCloud) |
| OS | Debian GNU/Linux 13 (trixie), kernel 6.12 |
| CPU / RAM / Disk | 8 vCPU / 15 GiB / 279 GB (121 GB bebas) |
| Web server | nginx + Virtualmin, site `eult.unmul.ac.id` |
| PHP | 8.5.12 (CLI `/usr/bin/php8.5`, FPM `php8.5-fpm.service`) |
| Pool FPM | `eultunmul` → socket `/run/php/1792665430372593.sock` |
| Database | MariaDB 11.8.8 |
| Runner | `ulthost`, grup `ult`, label `self-hosted`, `Linux`, `X64`, `eult` |
| Work folder | `eult-project` → `/home/eultunmul/apps/actions-runner/eult-project` |
| Direktori aplikasi | `/home/eultunmul/apps/eult` (milik `eultunmul`, mode 775) |
| Web root | `/home/eultunmul/public_html` (symlink ke `apps/eult/public/*`) |
| `.env` produksi | `/home/eultunmul/apps/eult/.env` (mode 664, tidak di-track) |

Karena runner berjalan sebagai `eultunmul` — pemilik direktori aplikasi —
**deploy tidak memerlukan sudo**.

## Alur

| Workflow | Runner | Pemicu | Tugas |
|---|---|---|---|
| `tests.yml` | GitHub-hosted | PR + push | Matriks PHP 8.2 & 8.3 — penjaga kompatibilitas minimum |
| `deploy.yml` → `Verifikasi` | self-hosted | PR + push `main` | Ekstensi PHP, `composer validate`, audit CVE, lint, unit test |
| `deploy.yml` → `Deploy produksi` | self-hosted | push `main` + manual | Backup → rsync → cache clear → health check |
| `rehearsal.yml` | self-hosted | manual | Latihan penuh ke salinan produksi, termasuk uji rollback |

Job `Deploy produksi` hanya berjalan bila `Verifikasi` hijau **dan** ref-nya
`main`. Pull request tidak pernah menyentuh produksi.

## Apa yang dilakukan `deploy.sh`

1. **Preflight** — direktori tujuan ada, `.env` produksi ada, `public/index.php`
   ada, user runner bisa menulis ke direktori tujuan.
2. **Backup** — `tar.gz` kode lama ke `/home/eultunmul/apps/eult-backups/`
   (tanpa `writable/` dan `.git/`), menyimpan 5 arsip terakhir.
3. **Rsync** — sinkron dari workspace runner ke direktori produksi dengan
   `--delete`, mengecualikan:
   - `/.env` → konfigurasi server tidak pernah tertimpa
   - `/writable/` → log, session, upload tidak pernah terhapus
   - `/.git/`, `/.github/`, `/build/`, `/phpunit.xml`, `/vendor/mpdf/mpdf/tmp/`

   Pola diawali `/` supaya hanya cocok di root — tanpa itu, `/.github/` juga
   mengenai `vendor/*/.github/` dan rsync gagal menghapus direktori vendor.

4. **Writable** — memastikan `cache/ logs/ session/ uploads/ debugbar/` ada
   (dibuat bila belum, tidak pernah dihapus).
5. **Migrasi** — hanya bila `DEPLOY_MIGRATE=true` (lihat bagian Migrasi).
6. **Cache** — `php spark cache:clear`.
7. **Health check** — HTTP ke nginx dengan header `Host: eult.unmul.ac.id`,
   10 percobaan × 3 detik. Lalu smoke test `php spark routes`.
8. **Rollback otomatis** — bila langkah 3–7 gagal, arsip backup diekstrak
   kembali ke direktori produksi.

Tidak diperlukan reload php-fpm: `opcache.validate_timestamps=On` dengan
`revalidate_freq=2`, jadi PHP otomatis memuat ulang file yang berubah.

## Deploy **tidak** memakai `git pull` di produksi

Ini penyebab kebingungan yang paling sering. Alurnya:

```
push ke main
   → runner checkout repo ke workspace-nya sendiri
     /home/eultunmul/apps/actions-runner/eult-project/eult/eult
   → deploy.sh rsync workspace itu ke /home/eultunmul/apps/eult
```

Direktori produksi **bukan** working copy yang di-`git pull`. Karena itu:

- **Jangan jalankan `git pull` di `/home/eultunmul/apps/eult`.** Isinya sudah
  diperbarui oleh rsync. `git pull` di sana akan gagal dengan pesan
  `local changes would be overwritten` — bukan karena ada masalah, tapi karena
  HEAD git di produksi tertinggal di belakang isi file.
- `git pull` juga meminta passphrase SSH key `id_ed25519`. Passphrase itu tidak
  relevan untuk CI/CD: runner memakai `GITHUB_TOKEN` saat checkout, dan deploy
  tidak pernah membuka koneksi git ke GitHub.

Sejak versi ini, `deploy.sh` menyamakan metadata git produksi secara otomatis
(`git fetch` dari workspace lokal + `git reset --hard`, tanpa jaringan dan tanpa
kredensial), sehingga `git log` dan `git status` di produksi kembali akurat.
`.env`, `writable/`, dan `vendor/` di-gitignore sehingga tidak tersentuh.

Kalau `git pull` di produksi terlanjur gagal, cukup jalankan sekali:

```bash
cd /home/eultunmul/apps/eult
git fetch origin main
git reset --hard origin/main
```

atau biarkan deploy berikutnya yang merapikannya sendiri.

## Variabel repository (opsional)

**Settings → Secrets and variables → Actions → Variables**:

| Variabel | Default | Keterangan |
|---|---|---|
| `DEPLOY_PATH` | `/home/eultunmul/apps/eult` | Direktori aplikasi |
| `DEPLOY_HEALTHCHECK_URL` | `http://192.168.55.105/` | URL yang di-curl dari server |
| `DEPLOY_HEALTHCHECK_HOST` | `eult.unmul.ac.id` | Header `Host` untuk health check |
| `DEPLOY_MIGRATE` | `true` (diset di repo; fallback workflow `false`) | Jalankan `php spark migrate` saat deploy |
| `DEPLOY_KEEP_BACKUPS` | `5` | Jumlah arsip backup disimpan |

> Health check memakai IP + header `Host` karena `https://eult.unmul.ac.id`
> tidak dapat di-resolve dari dalam server itu sendiri (DNS hairpin).

## Migrasi database

**Migrasi otomatis sudah aktif** (`DEPLOY_MIGRATE=true`).

### Riwayat: kenapa sempat tidak bisa

Awalnya `php spark migrate` gagal karena user `eult_app` hanya punya
`SELECT, INSERT, UPDATE, DELETE`:

```
CREATE command denied to user 'eult_app'@'localhost' for table `db_eultunmul`.`migrations`
```

Grant diperluas lewat Virtualmin → Edit Database Permissions untuk
`db_eultunmul`, user `eult_app`, host `127.0.0.1`:

| Privilege | Dipakai untuk |
|---|---|
| `CREATE` | tabel `migrations` |
| `ALTER` | `addColumn` pada migrasi |
| `INDEX` | tambah/ubah index |
| `DROP` | `migrate:rollback` / `migrate:refresh` |
| `REFERENCES` | foreign key |

`GRANT OPTION` sengaja **tidak** diberikan — user aplikasi tidak boleh
membagikan hak akses.

Sama dengan SQL:

```sql
GRANT CREATE, ALTER, INDEX, DROP, REFERENCES
  ON db_eultunmul.* TO 'eult_app'@'127.0.0.1';
FLUSH PRIVILEGES;
```

> Host-nya `127.0.0.1`, bukan `localhost` — `EULT_DB_HOST=127.0.0.1` membuat
> koneksi tercatat sebagai `eult_app@127.0.0.1`. Pesan error menampilkan
> `@localhost` karena itu nama host koneksi, bukan entri grant.

### Status sekarang

Migrasi `2026-10-28-223816_AddRatingKeterangan` sudah dijalankan (batch 1) dan
kolom `d_rating.ratingKeterangan` (`TEXT NULL`) sudah ada. Fitur keterangan
rating pada tiket aktif.

Migrasi ini idempoten — memeriksa `tableExists('d_rating')` dan
`getFieldNames('d_rating')` sebelum `addColumn`, jadi aman diulang. Kode
`Ticketing::simpanRating()` dan `Cektiket::simpanRating()` juga memeriksa
`getFieldNames()` sebelum menulis, sehingga tidak error bila kolom belum ada.

### Rollback migrasi

```bash
cd /home/eultunmul/apps/eult
php spark migrate:rollback   # membatalkan batch terakhir
php spark migrate:status     # cek status
```

Catatan: rollback kode (otomatis saat deploy gagal) **tidak** membatalkan
migrasi database. Bila rilis berisi migrasi yang mengubah skema, siapkan
`migrate:rollback` manual.

## Rollback manual

```bash
ls -1t /home/eultunmul/apps/eult-backups/*.tar.gz | head
tar -xzf /home/eultunmul/apps/eult-backups/eult-20261030-115850.tar.gz \
    -C /home/eultunmul/apps/eult
```

Migrasi database tidak ikut di-rollback. Bila rilis berisi migrasi yang
mengubah skema, siapkan `php spark migrate:rollback` manual.

## Deploy manual / dry run

**Actions → Deploy → Run workflow**, centang `dry_run` untuk berhenti sebelum
menyentuh produksi.

Atau langsung di server, dari direktori workspace runner:

```bash
cd /home/eultunmul/apps/actions-runner/eult-project/eult/eult
GITHUB_WORKSPACE="$PWD" DEPLOY_DRY_RUN=true bash .github/scripts/deploy.sh
```

## Troubleshooting

| Gejala | Penyebab umum | Tindakan |
|---|---|---|
| Job `queued` terus | Service runner mati, atau runner group `ult` belum diberi akses ke repo | `sudo systemctl status 'actions.runner.*'`; cek Organization → Actions → Runner groups → `ult` → Repository access |
| `Ekstensi PHP belum terpasang` | Paket PHP kurang | `sudo apt install php8.5-{intl,mbstring,gd,mysql,curl,zip,xml}` |
| `Tidak bisa menulis ke ...` | Runner bukan `eultunmul` | Jalankan service runner sebagai `eultunmul` |
| `Health check gagal` | nginx mati / `.env` salah / app error | `tail writable/logs/log-<tanggal>.log`, `curl -H 'Host: eult.unmul.ac.id' http://192.168.55.105/` |
| Deploy sukses tapi halaman 500 | permission `writable/` | `chmod -R ug+rwX writable/`, cek log |
| `Migrasi database gagal` | Izin DB kurang | Lihat bagian Migrasi di atas |
