#!/usr/bin/env bash
#
# Deploy E-ULT ke server produksi (Debian 13 trixie, Virtualmin, nginx + php8.5-fpm).
# Dijalankan oleh self-hosted runner sebagai user `eultunmul` (pemilik direktori
# produksi), jadi TIDAK memerlukan sudo.
#
# Alur: preflight -> backup -> rsync -> (migrate) -> cache clear -> health check.
# Bila langkah sinkronisasi/health check gagal, kode dikembalikan dari backup.
#
# Variabel lingkungan (opsional, punya default sesuai server saat ini):
#   DEPLOY_PATH              direktori aplikasi      (default /home/eultunmul/apps/eult)
#   DEPLOY_BACKUP_DIR        arsip rilis lama        (default <parent>/eult-backups)
#   DEPLOY_KEEP_BACKUPS      jumlah backup disimpan  (default 5)
#   DEPLOY_HEALTHCHECK_URL   URL yang di-curl        (default http://192.168.55.105/)
#   DEPLOY_HEALTHCHECK_HOST  header Host             (default eult.unmul.ac.id)
#   DEPLOY_MIGRATE           true = jalankan migrate (default false)
#   DEPLOY_DRY_RUN           true = berhenti sebelum menyentuh produksi
#   GITHUB_WORKSPACE         checkout sumber (diisi runner)

set -Eeuo pipefail

TARGET="${DEPLOY_PATH:-/home/eultunmul/apps/eult}"
BACKUP_DIR="${DEPLOY_BACKUP_DIR:-$(dirname "${TARGET%/}")/eult-backups}"
KEEP_BACKUPS="${DEPLOY_KEEP_BACKUPS:-5}"
HEALTH_URL="${DEPLOY_HEALTHCHECK_URL:-http://192.168.55.105/}"
HEALTH_HOST="${DEPLOY_HEALTHCHECK_HOST:-eult.unmul.ac.id}"
DO_MIGRATE="${DEPLOY_MIGRATE:-false}"
DRY_RUN="${DEPLOY_DRY_RUN:-false}"
SOURCE="${GITHUB_WORKSPACE:-$(pwd)}"
STAMP="$(date +%Y%m%d-%H%M%S)"

log()  { printf '\033[1;34m[deploy]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[deploy]\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m[deploy]\033[0m %s\n' "$*" >&2; exit 1; }
gha()  { printf '::%s::%s\n' "$1" "$2"; }

# ---------------------------------------------------------------- preflight ---

log "Sumber   : $SOURCE"
log "Tujuan   : $TARGET"
log "Backup   : $BACKUP_DIR (simpan $KEEP_BACKUPS terakhir)"

[ -d "$SOURCE/app" ]              || die "Sumber bukan aplikasi CI4 (app/ tidak ada): $SOURCE"
[ -d "$TARGET" ]                  || die "Direktori produksi tidak ditemukan: $TARGET"
[ -f "$TARGET/.env" ]             || die ".env produksi tidak ada di $TARGET — deploy dibatalkan agar konfigurasi tidak hilang"
[ -f "$TARGET/public/index.php" ] || die "$TARGET bukan aplikasi CI4 (public/index.php tidak ada)"
[ -w "$TARGET" ]                  || die "User $(id -un) tidak bisa menulis ke $TARGET (jalankan runner sebagai pemilik direktori)"

command -v rsync >/dev/null || die "rsync belum terpasang"
command -v php   >/dev/null || die "php tidak ditemukan di PATH"
command -v tar   >/dev/null || die "tar tidak ditemukan"
command -v curl  >/dev/null || die "curl tidak ditemukan"

log "PHP      : $(php -r 'echo PHP_VERSION;') ($(readlink -f "$(command -v php)"))"

if [ "$DRY_RUN" = "true" ]; then
    log "DRY RUN aktif — berhenti sebelum menyentuh direktori produksi."
    exit 0
fi

# ------------------------------------------------------------- backup dulu ---

mkdir -p "$BACKUP_DIR"
ARCHIVE="$BACKUP_DIR/eult-$STAMP.tar.gz"

log "Membuat backup kode produksi -> $ARCHIVE"
# writable/ dan .git/ tidak diikutkan: writable berisi data runtime, .git riwayat.
tar -czf "$ARCHIVE" --exclude='./writable' --exclude='./.git' -C "$TARGET" . \
    || die "Backup gagal — deploy dibatalkan"

# Simpan hanya N backup terakhir.
ls -1t "$BACKUP_DIR"/eult-*.tar.gz 2>/dev/null | tail -n +$((KEEP_BACKUPS + 1)) | xargs -r rm -f

ROLLBACK_NEEDED=1
rollback() {
    local code=$?
    if [ "$ROLLBACK_NEEDED" -eq 1 ] && [ -f "$ARCHIVE" ]; then
        warn "Deploy gagal (exit $code) — mengembalikan kode dari $ARCHIVE"
        if tar -xzf "$ARCHIVE" -C "$TARGET"; then
            warn "Rollback selesai; kode kembali ke rilis sebelumnya."
            gha error "Deploy gagal; rollback otomatis berhasil. Periksa log job."
        else
            gha error "Deploy gagal DAN rollback gagal. Restore manual: tar -xzf $ARCHIVE -C $TARGET"
        fi
    fi
    exit "$code"
}
trap rollback ERR

# ---------------------------------------------------------------- sinkronkan ---

log "Menyinkronkan kode ke produksi"
# Exclude yang dilindungi rsync: .env (konfigurasi server) dan writable/ (data
# runtime) tidak pernah ditimpa maupun dihapus oleh --delete.
#
# PENTING: pola diawali '/' agar hanya cocok di ROOT transfer. Tanpa itu,
# exclude '.github/' juga mengenai 'vendor/phar-io/manifest/.github/' dan
# membuat rsync gagal menghapus direktori vendor ("cannot delete non-empty
# directory"), sehingga paket dev tertinggal di produksi.
#
# .github/ ikut disinkronkan (bukan dikecualikan) supaya working tree produksi
# sama persis dengan commit dan `git status` di sana bersih. File workflow di
# produksi tidak pernah dieksekusi.
rsync -a --delete --delay-updates --itemize-changes \
    --exclude='/.git/' \
    --exclude='/.env' \
    --exclude='/writable/' \
    --exclude='/build/' \
    --exclude='/node_modules/' \
    --exclude='/.idea/' \
    --exclude='/.vscode/' \
    --exclude='/phpunit.xml' \
    --exclude='/.phpunit.cache/' \
    --exclude='/vendor/mpdf/mpdf/tmp/' \
    "$SOURCE"/ "$TARGET"/ > /tmp/eult-rsync.log 2>&1 || true

# Tampilkan ringkasan, bukan ribuan baris perubahan vendor.
changed=$(grep -c '^[<>ch]' /tmp/eult-rsync.log 2>/dev/null || echo 0)
log "  $changed entri berubah/dihapus"
grep -E '^(deleting|\*deleting)' /tmp/eult-rsync.log | head -20 | sed 's/^/    /' || true
grep -iE 'cannot delete|error|failed' /tmp/eult-rsync.log | head -10 | sed 's/^/    /' || true
rm -f /tmp/eult-rsync.log

# rsync exit 24 = "some files vanished" (bukan kegagalan). Kegagalan nyata
# sudah tertangkap oleh pemeriksaan di bawah: file inti harus ada.
[ -f "$TARGET/public/index.php" ] || die "Sinkronisasi gagal: public/index.php hilang"
[ -f "$TARGET/spark" ]            || die "Sinkronisasi gagal: spark hilang"
[ -d "$TARGET/vendor" ]           || die "Sinkronisasi gagal: vendor/ hilang"

# ------------------------------------------------------- metadata git target ---
#
# Deploy memakai rsync, BUKAN `git pull` di direktori produksi. Akibatnya HEAD
# git di produksi tertinggal, dan `git status`/`git pull` di sana menampilkan
# "local changes would be overwritten" padahal isinya sudah benar.
#
# Di sini metadata git disamakan dengan commit yang baru di-deploy. Objeknya
# diambil dari workspace runner (path lokal) sehingga tidak butuh jaringan,
# SSH key, maupun passphrase.
#
# Sengaja TIDAK memakai `reset --hard`: perintah itu menulis ulang working tree
# dan bisa menghapus/menimpa file. Yang dipakai:
#   update-ref HEAD  -> memindahkan branch ke commit baru
#   reset --mixed    -> menyamakan index, tanpa menyentuh satu file pun
# Working tree sudah disamakan oleh rsync, jadi hasilnya `git status` bersih.
#
# Aman: .env, writable/, dan vendor/ di-gitignore sehingga tidak pernah
# dilaporkan sebagai perubahan. Kegagalan di sini hanya diperingatkan.
sync_git_metadata() {
    [ -d "$TARGET/.git" ] || return 0
    command -v git >/dev/null 2>&1 || { warn "git tidak ada; metadata git produksi dilewati"; return 0; }
    git -C "$SOURCE" rev-parse --git-dir >/dev/null 2>&1 \
        || { warn "Sumber bukan repo git; metadata git produksi dilewati"; return 0; }

    local sha branch err shallow
    sha=$(git -C "$SOURCE" rev-parse HEAD 2>/dev/null) || return 0
    branch=$(git -C "$TARGET" rev-parse --abbrev-ref HEAD 2>/dev/null) || branch="main"
    [ "$branch" = "HEAD" ] && branch="main"

    # Repo produksi bisa berupa shallow clone (mis. `git clone --depth 1`).
    # Pada clone shallow, `fetch HEAD` hanya membawa satu commit tanpa parent,
    # sehingga `git log`/`git status` gagal ("Could not read <parent>").
    # Karena itu shallow di-unshallow lebih dulu; history repo ini kecil.
    shallow=$(git -C "$TARGET" rev-parse --is-shallow-repository 2>/dev/null || echo false)
    if [ "$shallow" = "true" ]; then
        log "Repo produksi shallow — mengambil history lengkap"
        if ! err=$(git -C "$TARGET" fetch --quiet --no-tags --unshallow "$SOURCE" HEAD 2>&1); then
            warn "unshallow gagal; metadata git dilewati"
            [ -n "$err" ] && warn "  $err"
            return 0
        fi
    elif ! err=$(git -C "$TARGET" fetch --quiet --no-tags "$SOURCE" HEAD 2>&1); then
        warn "fetch objek git dari workspace gagal; metadata git dilewati"
        [ -n "$err" ] && warn "  $err"
        return 0
    fi

    if ! err=$(git -C "$TARGET" update-ref "refs/heads/$branch" "$sha" 2>&1); then
        warn "update-ref ke ${sha:0:7} gagal; metadata git dilewati"
        [ -n "$err" ] && warn "  $err"
        return 0
    fi

    if ! err=$(git -C "$TARGET" reset --mixed --quiet 2>&1); then
        warn "reset --mixed gagal; metadata git mungkin tidak akurat"
        [ -n "$err" ] && warn "  $err"
        return 0
    fi

    log "Git produksi disinkronkan ke ${sha:0:7} ($branch)"
}
sync_git_metadata

# Pastikan struktur writable/ lengkap (dibuat bila belum ada, tidak dihapus).
for d in cache logs session uploads debugbar; do
    mkdir -p "$TARGET/writable/$d"
done

# ------------------------------------------------------- migrasi & cache -----

cd "$TARGET"

if [ "$DO_MIGRATE" = "true" ]; then
    log "Menjalankan migrasi database"
    php spark migrate 2>&1 | sed 's/^/    /' || die "Migrasi database gagal"
else
    warn "Migrasi DILEWATI (DEPLOY_MIGRATE=false)."
    warn "  User DB 'eult_app' belum punya izin CREATE/ALTER, dan tabel"
    warn "  'migrations' belum pernah dibuat. Untuk mengaktifkan:"
    warn "    1) GRANT CREATE, ALTER, INDEX, DROP, REFERENCES ON db_eultunmul.* TO 'eult_app'@'localhost';"
    warn "    2) set variabel repo DEPLOY_MIGRATE=true"
fi

log "Membersihkan cache aplikasi"
php spark cache:clear 2>&1 | sed 's/^/    /' || warn "cache:clear gagal (tidak fatal)"

# ------------------------------------------------------------- health check ---

log "Health check: $HEALTH_URL (Host: $HEALTH_HOST)"
ok=0
code=""
for i in $(seq 1 10); do
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 -H "Host: $HEALTH_HOST" "$HEALTH_URL" || true)"
    case "$code" in
        200|301|302|303|307|308) ok=1; break ;;
    esac
    warn "  percobaan $i: HTTP ${code:-000} — ulangi dalam 3s"
    sleep 3
done
[ "$ok" -eq 1 ] || die "Health check gagal (HTTP terakhir: ${code:-000})"
log "Health check OK (HTTP $code)"

# Smoke test CLI: memastikan framework boot penuh dengan .env produksi.
php spark routes >/dev/null 2>&1 || die "Aplikasi gagal boot (php spark routes error)"
log "Smoke test CLI OK"

ROLLBACK_NEEDED=0
trap - ERR

log "Deploy selesai: $STAMP"
gha notice "Deploy E-ULT $STAMP berhasil ke $TARGET (HTTP $code)"
