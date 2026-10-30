#!/usr/bin/env bash
#
# Deploy E-ULT ke server produksi.
# Dijalankan oleh self-hosted runner (job "deploy" di .github/workflows/deploy.yml).
#
# Alur: backup -> rsync -> migrate -> clear cache -> reload php-fpm -> health check.
# Bila salah satu langkah gagal, kode dikembalikan dari backup (rollback otomatis).
#
# Variabel lingkungan (semua opsional, punya default aman):
#   DEPLOY_PATH              direktori aplikasi di server   (default /home/eultunmul/apps/eult)
#   DEPLOY_BACKUP_DIR        direktori arsip rilis lama     (default <parent>/eult-backups)
#   DEPLOY_KEEP_BACKUPS      jumlah backup yang disimpan    (default 5)
#   DEPLOY_HEALTHCHECK_URL   URL yang dicek setelah deploy  (default: smoke test CLI)
#   DEPLOY_DRY_RUN           true = berhenti sebelum menyentuh produksi
#   GITHUB_WORKSPACE         checkout sumber (diisi runner)

set -Eeuo pipefail

TARGET="${DEPLOY_PATH:-/home/eultunmul/apps/eult}"
BACKUP_DIR="${DEPLOY_BACKUP_DIR:-$(dirname "${TARGET%/}")/eult-backups}"
KEEP_BACKUPS="${DEPLOY_KEEP_BACKUPS:-5}"
HEALTH_URL="${DEPLOY_HEALTHCHECK_URL:-}"
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

[ -d "$SOURCE/app" ] || die "Sumber tidak terlihat seperti aplikasi CI4 (app/ tidak ada): $SOURCE"
[ -d "$TARGET" ]     || die "Direktori produksi tidak ditemukan: $TARGET"
[ -f "$TARGET/.env" ] || die ".env produksi tidak ditemukan di $TARGET — deploy dibatalkan agar konfigurasi tidak hilang"
[ -f "$TARGET/public/index.php" ] || die "$TARGET bukan aplikasi CI4 (public/index.php tidak ada)"

command -v rsync  >/dev/null || die "rsync belum terpasang di server (apt install rsync)"
command -v php    >/dev/null || die "php tidak ditemukan di PATH runner"
command -v tar    >/dev/null || die "tar tidak ditemukan"

# Deploy butuh tulis ke direktori produksi. Bila runner bukan pemiliknya,
# pakai sudo -n (harus NOPASSWD). Bila dua-duanya tidak bisa, berhenti.
SUDO=""
if [ ! -w "$TARGET" ]; then
    if sudo -n true 2>/dev/null; then
        SUDO="sudo -n"
        log "Direktori produksi tidak bisa ditulis langsung — memakai sudo"
    else
        die "Tidak punya izin tulis ke $TARGET dan sudo tanpa password tidak tersedia"
    fi
fi

run() { if [ -n "$SUDO" ]; then $SUDO "$@"; else "$@"; fi; }

# Deteksi user web server (php-fpm pool) untuk kepemilikan writable/.
WEB_USER=""
for cand in www-data apache nginx http; do
    if id "$cand" >/dev/null 2>&1; then WEB_USER="$cand"; break; fi
done
WEB_USER="${WEB_USER:-www-data}"

# Perintah spark harus dijalankan sebagai pemilik writable/, kalau tidak CI4
# gagal menulis cache/log. Pakai sudo -u <web user> bila diizinkan.
SPARK_AS_WEB=0
if [ -n "$SUDO" ] && [ "$(id -un)" != "$WEB_USER" ] && $SUDO -u "$WEB_USER" true 2>/dev/null; then
    SPARK_AS_WEB=1
fi
spark() {
    if [ "$SPARK_AS_WEB" -eq 1 ]; then $SUDO -u "$WEB_USER" php spark "$@"; else php spark "$@"; fi
}

# Deteksi unit php-fpm yang aktif.
FPM_SERVICE=""
FPM_SERVICE="$(systemctl list-units --type=service --state=running --no-legend --no-pager 2>/dev/null \
    | awk '{print $1}' | grep -E '^php[0-9.]+-fpm\.service$' | head -1 || true)"
if [ -z "$FPM_SERVICE" ]; then
    for cand in php-fpm php8.4-fpm php8.3-fpm php8.2-fpm; do
        if systemctl list-unit-files --no-legend --no-pager 2>/dev/null | grep -q "^${cand}\.service"; then
            FPM_SERVICE="${cand}.service"; break
        fi
    done
fi
[ -n "$FPM_SERVICE" ] || warn "Unit php-fpm tidak terdeteksi — langkah reload akan dilewati"
log "Web user : $WEB_USER"
log "php-fpm  : ${FPM_SERVICE:-<tidak ada>}"

if [ "$DRY_RUN" = "true" ]; then
    log "DRY RUN aktif — berhenti sebelum menyentuh direktori produksi."
    exit 0
fi

# ------------------------------------------------------------- backup dulu ---

run mkdir -p "$BACKUP_DIR"
ARCHIVE="$BACKUP_DIR/eult-$STAMP.tar.gz"

log "Membuat backup kode produksi -> $ARCHIVE"
# writable/ tidak diikutkan: isinya data runtime (log, session, upload), bukan kode.
run tar -czf "$ARCHIVE" \
    --exclude='./writable' \
    --exclude='./.git' \
    -C "$TARGET" . 2>/dev/null || die "Backup gagal — deploy dibatalkan"

# Simpan hanya N backup terakhir.
if [ -z "$SUDO" ]; then
    ls -1t "$BACKUP_DIR"/eult-*.tar.gz 2>/dev/null | tail -n +$((KEEP_BACKUPS + 1)) | xargs -r rm -f
else
    $SUDO bash -c "ls -1t '$BACKUP_DIR'/eult-*.tar.gz 2>/dev/null | tail -n +$((KEEP_BACKUPS + 1)) | xargs -r rm -f"
fi

ROLLBACK_NEEDED=1
rollback() {
    local code=$?
    if [ "$ROLLBACK_NEEDED" -eq 1 ] && [ -f "$ARCHIVE" ]; then
        warn "Deploy gagal (exit $code) — mengembalikan kode dari $ARCHIVE"
        if run tar -xzf "$ARCHIVE" -C "$TARGET"; then
            warn "Rollback selesai. Kode kembali ke rilis sebelumnya."
            if [ -n "$FPM_SERVICE" ]; then run systemctl reload "$FPM_SERVICE" || true; fi
            gha error "Deploy gagal; rollback otomatis berhasil. Periksa log job."
        else
            gha error "Deploy gagal DAN rollback gagal. Restore manual dari $ARCHIVE"
        fi
    fi
    exit "$code"
}
trap rollback ERR

# ---------------------------------------------------------------- sinkronkan ---

log "Menyinkronkan kode ke produksi"
# Yang TIDAK boleh tertimpa: .env (konfigurasi server) dan writable/ (data runtime).
# Yang tidak perlu ikut: artefak dev/CI.
run rsync -a --delete --delay-updates \
    --exclude='.git/' \
    --exclude='.github/' \
    --exclude='.env' \
    --exclude='writable/' \
    --exclude='tests/' \
    --exclude='docs/' \
    --exclude='build/' \
    --exclude='node_modules/' \
    --exclude='.idea/' \
    --exclude='.vscode/' \
    --exclude='phpunit.xml' \
    --exclude='phpunit.dist.xml' \
    --exclude='.phpunit.cache/' \
    --exclude='.deploy/' \
    "$SOURCE"/ "$TARGET"/

# Pastikan struktur writable/ lengkap dan bisa ditulis web server.
for d in cache logs session uploads debugbar; do
    run mkdir -p "$TARGET/writable/$d"
done
run chown -R "$WEB_USER":"$WEB_USER" "$TARGET/writable" 2>/dev/null || warn "chown writable ke $WEB_USER gagal (lanjut)"
run chmod -R ug+rwX "$TARGET/writable" 2>/dev/null || true

# ------------------------------------------------------- migrasi & cache -----

log "Menjalankan migrasi database"
cd "$TARGET"
spark migrate 2>&1 | sed 's/^/    /' || die "Migrasi database gagal"

log "Membersihkan cache aplikasi"
spark cache:clear 2>&1 | sed 's/^/    /' || warn "cache:clear gagal (tidak fatal)"

# ------------------------------------------------------------- reload fpm ----

if [ -n "$FPM_SERVICE" ]; then
    log "Reload $FPM_SERVICE (reset OPcache)"
    run systemctl reload "$FPM_SERVICE" || warn "Reload $FPM_SERVICE gagal"
fi

# ------------------------------------------------------------ health check ---

if [ -n "$HEALTH_URL" ]; then
    log "Health check: $HEALTH_URL"
    ok=0
    for i in $(seq 1 10); do
        code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$HEALTH_URL" || true)"
        case "$code" in
            200|301|302|303|307|308) ok=1; break ;;
        esac
        warn "  percobaan $i: HTTP $code — ulangi dalam 3s"
        sleep 3
    done
    [ "$ok" -eq 1 ] || die "Health check gagal (HTTP terakhir: ${code:-tanpa respons})"
    log "Health check OK (HTTP $code)"
else
    log "DEPLOY_HEALTHCHECK_URL kosong — memakai smoke test CLI"
    spark routes >/dev/null 2>&1 || die "Aplikasi gagal boot (php spark routes error)"
    log "Smoke test CLI OK"
fi

ROLLBACK_NEEDED=0
trap - ERR

log "Deploy selesai: $STAMP"
gha notice "Deploy E-ULT $STAMP berhasil ke $TARGET"
