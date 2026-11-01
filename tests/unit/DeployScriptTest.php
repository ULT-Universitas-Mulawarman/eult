<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Pengujian TDD untuk skrip deploy produksi (.github/scripts/deploy.sh).
 *
 * Memastikan:
 * 1. Sinkronisasi metadata Git produksi (sync_git_metadata) tetap berjalan
 *    meskipun proses runner dijalankan oleh user berbeda (mis. root vs eultunmul)
 *    yang memicu penolakan Git "fatal: detected dubious ownership".
 * 2. HEAD, branch aktif, dan refs/remotes/origin/<branch> di direktori target
 *    ikut dimajukan ke commit terbaru sehingga `git status --porcelain` bersih.
 * 3. Berkas pelindung di writable/ yang di-track Git (index.html, .htaccess)
 *    dipulihkan bila hilang tanpa menghapus data runtime di writable/.
 * 4. rsync memakai --no-owner --no-group dan skrip menjaga kepemilikan berkas
 *    target bila dijalankan sebagai root.
 */
final class DeployScriptTest extends CIUnitTestCase
{
    private string $dirUji = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dirUji = sys_get_temp_dir() . '/eult_deploy_test_' . bin2hex(random_bytes(6));
        mkdir($this->dirUji, 0775, true);
    }

    protected function tearDown(): void
    {
        if ($this->dirUji !== '' && is_dir($this->dirUji)) {
            $this->hapusDirektori($this->dirUji);
        }
        parent::tearDown();
    }

    private function hapusDirektori(string $jalur): void
    {
        if (! is_dir($jalur)) {
            return;
        }

        $daftar = scandir($jalur);
        if ($daftar === false) {
            return;
        }

        foreach ($daftar as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $penuh = $jalur . '/' . $item;
            if (is_link($penuh) || is_file($penuh)) {
                @unlink($penuh);
            } elseif (is_dir($penuh)) {
                $this->hapusDirektori($penuh);
            }
        }
        @rmdir($jalur);
    }

    private function jalankan(string $perintah, ?string $cwd = null, array $envTambahan = []): array
    {
        $env = array_merge($_ENV, $_SERVER, $envTambahan);
        foreach ($env as $k => $v) {
            if (! is_scalar($v)) {
                unset($env[$k]);
            } else {
                $env[$k] = (string) $v;
            }
        }

        $deskriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proses = proc_open($perintah, $deskriptor, $pipa, $cwd ?? $this->dirUji, $env);
        self::assertIsResource($proses, 'Gagal menjalankan proses uji.');

        fclose($pipa[0]);
        $stdout = (string) stream_get_contents($pipa[1]);
        $stderr = (string) stream_get_contents($pipa[2]);
        fclose($pipa[1]);
        fclose($pipa[2]);

        $kode = proc_close($proses);

        return ['code' => $kode, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    public function testSyncGitMetadataTetapJalanSaatDubiousOwnershipDanMembuatGitStatusBersih(): void
    {
        $source = $this->dirUji . '/source';
        $target = $this->dirUji . '/target';
        $backup = $this->dirUji . '/backups';
        $bin    = $this->dirUji . '/bin';

        mkdir($source, 0775, true);
        mkdir($backup, 0775, true);
        mkdir($bin, 0775, true);

        // Siapkan repositori sumber dengan struktur minimal aplikasi CI4
        $this->jalankan('git init -b main', $source);
        $this->jalankan('git config user.email "ci@eult.test"', $source);
        $this->jalankan('git config user.name "CI EULT"', $source);

        mkdir($source . '/app', 0775, true);
        mkdir($source . '/public', 0775, true);
        mkdir($source . '/writable/cache', 0775, true);
        mkdir($source . '/writable/logs', 0775, true);

        file_put_contents($source . '/.gitignore', "/.env\n/vendor/\n/writable/cache/*\n!/writable/cache/index.html\n/writable/logs/*\n!/writable/logs/index.html\n");
        file_put_contents($source . '/public/index.php', "<?php echo 'v1';\n");
        file_put_contents($source . '/spark', "#!/usr/bin/env php\n<?php exit(0);\n");
        chmod($source . '/spark', 0755);
        file_put_contents($source . '/app/Contoh.php', "<?php // versi 1\n");
        file_put_contents($source . '/writable/cache/index.html', "<!DOCTYPE html><html></html>\n");
        file_put_contents($source . '/writable/logs/index.html', "<!DOCTYPE html><html></html>\n");

        $this->jalankan('git add -A && git commit -m "rilis awal"', $source);
        $shaAwal = trim($this->jalankan('git rev-parse HEAD', $source)['stdout']);

        // Clone ke target (simulasi direktori produksi pada commit lama)
        $this->jalankan('git clone ' . escapeshellarg($source) . ' ' . escapeshellarg($target));
        file_put_contents($target . '/.env', "CI_ENVIRONMENT = production\n");
        file_put_contents($target . '/writable/logs/log-runtime.log', "log produksi harus tetap aman\n");

        // Simulasikan kondisi kotor di produksi:
        // 1) Berkas pelindung writable/cache/index.html hilang
        // 2) Berkas pelindung writable/logs/index.html terkena chmod 0777 (fileMode kotor)
        // 3) HEAD produksi sempat detached pada commit lama
        unlink($target . '/writable/cache/index.html');
        chmod($target . '/writable/logs/index.html', 0777);
        $this->jalankan('git checkout --detach ' . escapeshellarg($shaAwal), $target);

        // Tambahkan commit baru di source + vendor hasil build produksi
        file_put_contents($source . '/public/index.php', "<?php echo 'v2';\n");
        file_put_contents($source . '/app/Contoh.php', "<?php // versi 2\n");
        file_put_contents($source . '/app/FiturBaru.php', "<?php // fitur baru\n");
        $this->jalankan('git add -A && git commit -m "rilis kedua"', $source);
        $shaBaru = trim($this->jalankan('git rev-parse HEAD', $source)['stdout']);

        mkdir($source . '/vendor/autoload', 0775, true);
        file_put_contents($source . '/vendor/autoload.php', "<?php // autoload\n");

        // Buat overlay GIT_EXEC_PATH agar subproses `git-upload-pack` yang di-spawn
        // oleh `git fetch <local-path>` (setelah Git menghapus local_repo_env seperti
        // GIT_CONFIG_COUNT dan GIT_CONFIG_PARAMETERS) juga ikut diverifikasi nyata.
        $gitAsli     = trim($this->jalankan('command -v git')['stdout']);
        $gitExecAsli = trim($this->jalankan(escapeshellarg($gitAsli) . ' --exec-path')['stdout']);
        $execOverlay = $this->dirUji . '/git-exec';
        mkdir($execOverlay, 0775, true);

        foreach (scandir($gitExecAsli) ?: [] as $perkakas) {
            if ($perkakas === '.' || $perkakas === '..' || $perkakas === 'git-upload-pack') {
                continue;
            }
            symlink($gitExecAsli . '/' . $perkakas, $execOverlay . '/' . $perkakas);
        }

        $wrapperUploadPack = <<<BASH
#!/usr/bin/env bash
daftar_aman="\$("$gitAsli" config --get-all safe.directory 2>/dev/null || true)"
if [[ "\$daftar_aman" != *"*"* ]]; then
    echo "fatal: detected dubious ownership in repository at '\${1:-}/.git'" >&2
    exit 128
fi
exec "$gitExecAsli/git-upload-pack" "\$@"
BASH;
        file_put_contents($execOverlay . '/git-upload-pack', $wrapperUploadPack);
        chmod($execOverlay . '/git-upload-pack', 0755);

        // Buat wrapper git utama yang menolak akses bila safe.directory belum disetel,
        // serta mengarahkan GIT_EXEC_PATH ke overlay di atas saat mengeksekusi git asli.
        $wrapperGit = <<<BASH
#!/usr/bin/env bash
daftar_aman="\$("$gitAsli" config --get-all safe.directory 2>/dev/null || true)"
for arg in "\$@"; do
    if [[ "\$arg" == *"safe.directory="* ]]; then
        daftar_aman="*"
    fi
done
if [[ -z "\$daftar_aman" ]]; then
    echo "fatal: detected dubious ownership in repository" >&2
    exit 128
fi
export GIT_EXEC_PATH="$execOverlay"
exec "$gitAsli" "\$@"
BASH;
        file_put_contents($bin . '/git', $wrapperGit);
        chmod($bin . '/git', 0755);

        // Stub curl agar health check mengembalikan HTTP 200 tanpa koneksi jaringan luar
        file_put_contents($bin . '/curl', "#!/usr/bin/env bash\nprintf '200'\nexit 0\n");
        chmod($bin . '/curl', 0755);

        $jalurSkrip = ROOTPATH . '.github/scripts/deploy.sh';
        $pathLama   = getenv('PATH') ?: '/usr/bin:/bin';

        $hasil = $this->jalankan(
            'bash ' . escapeshellarg($jalurSkrip),
            $source,
            [
                'PATH'                   => $bin . ':' . $pathLama,
                'TMPDIR'                 => $this->dirUji,
                'GITHUB_WORKSPACE'       => $source,
                'DEPLOY_PATH'            => $target,
                'DEPLOY_BACKUP_DIR'      => $backup,
                'DEPLOY_MIGRATE'         => 'false',
                'DEPLOY_DRY_RUN'         => 'false',
                'DEPLOY_HEALTHCHECK_URL' => 'http://127.0.0.1/',
            ]
        );

        $gabunganLog = $hasil['stdout'] . "\n" . $hasil['stderr'];

        self::assertSame(0, $hasil['code'], 'deploy.sh harus keluar dengan kode 0. Log: ' . $gabunganLog);
        self::assertStringNotContainsString(
            'dubious ownership',
            $gabunganLog,
            'sync_git_metadata maupun subproses git-upload-pack tidak boleh gagal akibat dubious ownership.'
        );
        self::assertStringContainsString(
            'Git produksi disinkronkan ke ' . substr($shaBaru, 0, 7) . ' (main)',
            $gabunganLog,
            'Log deploy wajib mengonfirmasi sinkronisasi commit Git produksi.'
        );
        self::assertStringContainsString(
            'Status git produksi bersih (working tree clean)',
            $gabunganLog,
            'Log deploy wajib mengonfirmasi working tree Git produksi bersih.'
        );

        // Pastikan HEAD, branch main, dan origin/main di target semuanya menunjuk ke commit terbaru
        $shaTarget    = trim($this->jalankan(escapeshellarg($gitAsli) . ' rev-parse HEAD', $target)['stdout']);
        $branchTarget = trim($this->jalankan(escapeshellarg($gitAsli) . ' rev-parse --abbrev-ref HEAD', $target)['stdout']);
        $originTarget = trim($this->jalankan(escapeshellarg($gitAsli) . ' rev-parse refs/remotes/origin/main', $target)['stdout']);
        $statusTarget = trim($this->jalankan(escapeshellarg($gitAsli) . ' status --porcelain', $target)['stdout']);

        self::assertSame($shaBaru, $shaTarget, 'HEAD produksi harus menunjuk ke commit yang baru di-deploy.');
        self::assertSame('main', $branchTarget, 'HEAD produksi tidak boleh tetap detached.');
        self::assertSame($shaBaru, $originTarget, 'refs/remotes/origin/main produksi harus ikut disamakan.');
        self::assertFileExists($target . '/writable/cache/index.html', 'Berkas pelindung writable yang hilang harus dipulihkan.');
        self::assertSame(
            0,
            (int) fileperms($target . '/writable/logs/index.html') & 0111,
            'Mode izin berkas pelindung writable harus dipulihkan tanpa bit executable kotor.'
        );
        self::assertFileExists($target . '/writable/logs/log-runtime.log', 'Berkas log runtime produksi tidak boleh terhapus.');
        self::assertSame([], glob($this->dirUji . '/eult-gitconfig.*') ?: [], 'Berkas konfigurasi Git sementara wajib dibersihkan setelah deploy.');
        self::assertSame('', $statusTarget, 'git status --porcelain di direktori produksi harus bersih total.');
    }

    public function testSkripDeployMenjagaKepemilikanBerkasSaatBerjalanSebagaiRoot(): void
    {
        $isi = (string) file_get_contents(ROOTPATH . '.github/scripts/deploy.sh');

        self::assertStringContainsString(
            '--no-owner',
            $isi,
            'rsync pada deploy.sh wajib menyertakan --no-owner agar tidak memaksa UID root ke produksi.'
        );
        self::assertStringContainsString(
            '--no-group',
            $isi,
            'rsync pada deploy.sh wajib menyertakan --no-group agar tidak memaksa GID root ke produksi.'
        );
        self::assertStringContainsString(
            'GIT_CONFIG_GLOBAL',
            $isi,
            'deploy.sh wajib memakai GIT_CONFIG_GLOBAL agar safe.directory tetap diwariskan ke subproses git-upload-pack.'
        );
        self::assertStringContainsString(
            'directory = *',
            $isi,
            'Berkas konfigurasi Git sementara wajib mengizinkan safe.directory = * selama sinkronisasi.'
        );
    }
}
