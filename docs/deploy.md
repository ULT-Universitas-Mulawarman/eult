# Deploy E-ULT (CI/CD self-hosted runner)

Panduan ini menjelaskan alur CI/CD yang berjalan di server produksi sendiri
(self-hosted runner), bukan di mesin GitHub.

## Ringkasan alur

| Workflow | Runner | Pemicu | Tugas |
|---|---|---|---|
| `tests.yml` | GitHub-hosted (`ubuntu-latest`) | PR + push | Matriks PHP 8.2 & 8.3 — penjaga kompatibilitas |
| `deploy.yml` → job `verify` | self-hosted (`self-hosted`, `eult`) | push `main` + manual | Lint, audit CVE, unit test dengan PHP server |
| `deploy.yml` → job `deploy` | self-hosted (`self-hosted`, `eult`) | push `main` + manual | Backup → rsync → migrate → reload → health check |

`deploy` hanya jalan bila `verify` hijau dan ref-nya `main`. PR tidak pernah
menyentuh produksi.

## Prasyarat server (Debian 13 trixie)

Runner sudah terdaftar dengan label `self-hosted`, `Linux`, `X64`, `eult`
dan work folder `eult-project`. Yang perlu dipastikan ada di server:

```bash
# PHP CLI + ekstensi yang dipakai aplikasi dan test
sudo apt install -y php-cli php-intl php-mbstring php-gd php-mysql \
                    php-curl php-zip php-xml php-sqlite3

# Alat bantu deploy
sudo apt install -y rsync unzip curl git

# Composer (bila belum ada)
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

Cek cepat:

```bash
php -v
php -m | grep -E 'intl|mbstring|gd|mysqli|curl|zip'
composer --version
rsync --version | head -1
```

## Izin direktori

Runner harus bisa menulis ke direktori produksi
(`/home/eultunmul/apps/eult`) **atau** punya sudo tanpa password.

Opsi paling sederhana — jalankan runner sebagai pemilik direktori:

```bash
# pemilik aplikasi
stat -c '%U:%G' /home/eultunmul/apps/eult

# jalankan service runner sebagai user tersebut
sudo systemctl edit actions.runner.<org>-<repo>.<runner-name>.service
```

Tambahkan:

```ini
[Service]
User=eultunmul
Group=eultunmul
```

lalu:

```bash
sudo systemctl daemon-reload
sudo systemctl restart actions.runner.<org>-<repo>.<runner-name>.service
```

Bila runner harus tetap memakai user lain, berikan sudo terbatas:

```bash
echo 'runner ALL=(ALL) NOPASSWD: /usr/bin/rsync, /usr/bin/tar, /usr/bin/systemctl, /usr/bin/chown, /usr/bin/mkdir, /usr/bin/php' \
  | sudo tee /etc/sudoers.d/eult-deploy
sudo chmod 0440 /etc/sudoers.d/eult-deploy
```

## Pastikan runner melayani repo ini

Runner terdaftar di **level organisasi** (runner group `ult`). Agar job dari
repo ini bisa memakainya, runner group harus diberi akses ke repository
`ULT-Universitas-Mulawarman/eult`:

> Organization **Settings → Actions → Runner groups → `ult` →
> Repository access** → pilih *Selected repositories* → tambahkan
> `eult` (atau pilih *All repositories*).

Cek dari sisi server:

```bash
# service runner harus active (running)
sudo systemctl status 'actions.runner.*' --no-pager

# log listener — harus muncul "Listening for Jobs"
sudo journalctl -u 'actions.runner.*' -n 30 --no-pager
```

Bila job berstatus **queued** lebih dari satu menit, hampir selalu karena
salah satu dari dua hal di atas: runner group belum memberi akses ke repo,
atau service runner mati.

## Variabel repository (opsional)

**Settings → Secrets and variables → Actions → Variables**:

| Variabel | Default | Keterangan |
|---|---|---|
| `DEPLOY_PATH` | `/home/eultunmul/apps/eult` | Direktori aplikasi produksi |
| `DEPLOY_HEALTHCHECK_URL` | *(kosong)* | URL yang di-curl setelah deploy. Bila kosong, dipakai smoke test `php spark routes` |
| `DEPLOY_KEEP_BACKUPS` | `5` | Jumlah arsip backup yang disimpan |

Sangat disarankan mengisi `DEPLOY_HEALTHCHECK_URL` (mis. `https://eult.unmul.ac.id/`)
supaya deploy benar-benar terbukti melayani request, bukan hanya lolos boot CLI.

## Apa yang dilakukan `deploy.sh`

1. **Preflight** — memastikan direktori tujuan ada, `.env` produksi ada, dan
   `public/index.php` ada. Deploy dibatalkan bila `.env` hilang.
2. **Backup** — `tar.gz` kode lama ke `<parent>/eult-backups/eult-<timestamp>.tar.gz`
   (tanpa `writable/`), simpan `DEPLOY_KEEP_BACKUPS` terakhir.
3. **Rsync** — sinkron dari workspace runner ke direktori produksi dengan
   `--delete`, tetapi mengecualikan:
   - `.env` → konfigurasi server tidak pernah tertimpa
   - `writable/` → log, session, upload tidak pernah terhapus
   - `tests/`, `docs/`, `.git/`, `.github/`, `build/`, `phpunit*.xml`
4. **Permission** — `writable/` di-`chown` ke user web server dan diberi `g+w`.
5. **Migrasi** — `php spark migrate` (hanya migrasi yang belum jalan).
6. **Cache** — `php spark cache:clear`.
7. **Reload** — `systemctl reload <php-fpm>` untuk reset OPcache.
8. **Health check** — HTTP ke `DEPLOY_HEALTHCHECK_URL` (10 percobaan × 3 detik)
   atau smoke test CLI bila URL kosong.
9. **Rollback otomatis** — bila langkah 3–8 gagal, arsip backup diekstrak
   kembali ke direktori produksi dan php-fpm di-reload.

## Rollback manual

```bash
ls -1t /home/eultunmul/apps/eult-backups/*.tar.gz | head
tar -xzf /home/eultunmul/apps/eult-backups/eult-20261030-120000.tar.gz \
    -C /home/eultunmul/apps/eult
sudo systemctl reload php*-fpm
```

Catatan: migrasi database **tidak** di-rollback otomatis. Bila rilis berisi
migrasi yang mengubah skema, siapkan `php spark migrate:rollback` manual.

## Deploy manual / dry run

Dari tab **Actions → Deploy → Run workflow**:

- `dry_run` dicentang → hanya verifikasi, berhenti sebelum menyentuh produksi.

Atau langsung di server:

```bash
cd /path/ke/workspace/runner
GITHUB_WORKSPACE="$PWD" DEPLOY_DRY_RUN=true bash .github/scripts/deploy.sh
```

## Troubleshooting

| Gejala | Penyebab umum | Tindakan |
|---|---|---|
| Job `queued` terus | Runner group tidak memberi akses ke repo, atau service mati | Lihat bagian "Pastikan runner melayani repo ini" |
| `Ekstensi PHP belum terpasang` | Paket PHP kurang | `sudo apt install php-{intl,mbstring,gd,mysql,curl,zip,xml}` |
| `Tidak punya izin tulis` | Runner bukan pemilik direktori dan tanpa sudo | Jalankan runner sebagai `eultunmul` atau pasang sudoers |
| `Health check gagal` | php-fpm belum reload / `.env` salah / app error | Cek `writable/logs/log-<tanggal>.log` |
| `Migrasi database gagal` | Kredensial `.env` salah / DB mati | `php spark migrate:status` di direktori produksi |
| Deploy sukses tapi halaman 500 | OPcache / permission `writable/` | `sudo systemctl reload php*-fpm`, cek `chown` |
