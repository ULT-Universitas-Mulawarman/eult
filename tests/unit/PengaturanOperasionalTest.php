<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Test perbaikan issue operasional 2026-10-30:
 * zona waktu WITA, batas unggah 10 MB, masa sesi, dan panjang identitas.
 *
 * @internal
 */
final class PengaturanOperasionalTest extends CIUnitTestCase
{
    // ------------------------------------------------------------- timezone ---

    public function testZonaWaktuAplikasiWita(): void
    {
        $this->assertSame(
            'Asia/Makassar',
            config('App')->appTimezone,
            'Zona waktu aplikasi harus WITA, bukan UTC — jam tiket sempat mundur 8 jam.',
        );
    }

    public function testZonaWaktuPhpIkutWita(): void
    {
        // CI4 memanggil date_default_timezone_set(appTimezone) saat boot.
        // Bila tidak, date() di controller tetap memakai zona php.ini.
        $this->assertSame('Asia/Makassar', date_default_timezone_get());
    }

    public function testSelisihWitaTerhadapUtcDelapanJam(): void
    {
        $wita = new DateTime('2026-10-30 12:00:00', new DateTimeZone('Asia/Makassar'));
        $utc  = new DateTime('2026-10-30 04:00:00', new DateTimeZone('UTC'));

        $this->assertSame(
            $utc->getTimestamp(),
            $wita->getTimestamp(),
            'WITA harus UTC+8 tanpa offset DST.',
        );
    }

    // --------------------------------------------------------------- upload ---

    public function testBatasUnggahDefaultSepuluhMb(): void
    {
        // Pastikan .env tidak menyetel nilai lain saat tes berjalan.
        putenv('EULT_UPLOAD_MAX_MB');
        $_ENV['EULT_UPLOAD_MAX_MB'] = $_SERVER['EULT_UPLOAD_MAX_MB'] = null;

        $this->assertSame(10, eult_upload_max_mb());
        $this->assertSame(10 * 1024, eult_upload_max_kb());
    }

    public function testBatasUnggahBisaDioverrideEnv(): void
    {
        putenv('EULT_UPLOAD_MAX_MB=25');

        try {
            $this->assertSame(25, eult_upload_max_mb());
            $this->assertSame(25 * 1024, eult_upload_max_kb());
        } finally {
            putenv('EULT_UPLOAD_MAX_MB');
        }
    }

    public function testBatasUnggahTidakMelebihiBatasWajar(): void
    {
        putenv('EULT_UPLOAD_MAX_MB=99999');

        try {
            $this->assertSame(512, eult_upload_max_mb(), 'Nilai .env harus dibatasi agar tidak melampaui batas PHP.');
        } finally {
            putenv('EULT_UPLOAD_MAX_MB');
        }
    }

    public function testKonfigurasiUnggahControllerMemakaiHelper(): void
    {
        // Angka batas tidak boleh ditulis ulang per controller: dulu ada
        // 15*1024 dan 20*1024 yang tidak konsisten dengan sisi klien 10 MB.
        $berkas = [
            'app/Controllers/Login.php',
            'app/Controllers/Cektiket.php',
            'app/Controllers/Ticketing.php',
        ];

        foreach ($berkas as $jalur) {
            $isi = file_get_contents(ROOTPATH . $jalur);

            $this->assertNotFalse($isi, $jalur . ' harus terbaca.');
            $this->assertStringNotContainsString("'size'     => 15 * 1024", $isi, $jalur . ' masih memakai batas 15 MB.');
            $this->assertStringNotContainsString("'size'     => 20 * 1024", $isi, $jalur . ' masih memakai batas 20 MB.');
            $this->assertStringContainsString('eult_upload_max_kb()', $isi, $jalur . ' harus memakai eult_upload_max_kb().');
        }
    }

    public function testBatasKlienSamaDenganBatasServer(): void
    {
        // Validasi JS di halaman login memakai 10 MB; harus sama dengan
        // batas server agar pengguna tidak ditolak setelah menunggu unggah.
        $isi = file_get_contents(ROOTPATH . 'app/Views/layouts/login.php');

        $this->assertNotFalse($isi);
        $this->assertStringContainsString('10 * 1024 * 1024', $isi, 'Batas sisi klien harus 10 MB.');
        $this->assertSame(10 * 1024 * 1024, eult_upload_max_kb() * 1024, 'Batas server harus sama dengan batas klien.');
    }

    // -------------------------------------------------------------- session ---

    public function testMasaSesiMinimalDelapanJam(): void
    {
        $expiration = config('Session')->expiration;

        $this->assertGreaterThanOrEqual(
            8 * 3600,
            $expiration,
            'Sesi di bawah 8 jam memaksa operator login ulang di tengah pekerjaan.',
        );
    }

    public function testMasaSesiTidakBerlebihan(): void
    {
        // Sesi terlalu panjang memperbesar risiko bila perangkat dipakai
        // bersama. Batas atas 24 jam.
        $this->assertLessThanOrEqual(24 * 3600, config('Session')->expiration);
    }

    // ------------------------------------------------------------ identitas ---

    /**
     * @return array<string, array{string, bool}>
     */
    public static function panjangIdentitasProvider(): array
    {
        return [
            'NIM lama 10 digit'   => ['0707055155', true],
            'NIK KTP 16 digit'    => ['6472032410880003', true],
            'NIP 18 digit'        => ['198810242014041001', false],
            'NPWP 15 digit'       => ['836972869722000', false],
            'terlalu pendek'      => ['12345', false],
            'bukan angka'         => ['abcdefghij', false],
            'kosong'              => ['', false],
        ];
    }

    /**
     * @dataProvider panjangIdentitasProvider
     */
    public function testPanjangIdentitasPemohonUmum(string $id, bool $diharapkan): void
    {
        // Logika yang sama dipakai di Login::getIdentitas() dan
        // Ticketing::getIdentitas(). Dulu keduanya berbeda (10 vs 16),
        // sehingga NIK 16 digit ditolak di portal publik.
        $diterima = in_array(strlen($id), [10, 16], true) && ctype_digit($id);

        $this->assertSame($diharapkan, $diterima, 'Identitas "' . $id . '" panjang ' . strlen($id));
    }

    public function testKeduaControllerMemakaiAturanPanjangSama(): void
    {
        foreach (['app/Controllers/Login.php', 'app/Controllers/Ticketing.php'] as $jalur) {
            $isi = file_get_contents(ROOTPATH . $jalur);

            $this->assertNotFalse($isi, $jalur . ' harus terbaca.');
            $this->assertStringContainsString(
                'in_array(strlen($id), [10, 16], true)',
                $isi,
                $jalur . ' harus menerima 10 digit (NIM lama) dan 16 digit (NIK KTP).',
            );
        }
    }

    public function testIdentitasDicekKeOsmSebelumFallbackUmum(): void
    {
        // Data OSM harus diutamakan: bila NIM/NIP ditemukan, nama terisi
        // otomatis dan tidak dianggap pemohon umum.
        foreach (['app/Controllers/Login.php', 'app/Controllers/Ticketing.php'] as $jalur) {
            $isi = file_get_contents(ROOTPATH . $jalur);

            $this->assertNotFalse($isi);

            $posisiMhs     = strpos($isi, 'mhsId2(');
            $posisiUmum    = strpos($isi, 'in_array(strlen($id), [10, 16], true)');

            $this->assertNotFalse($posisiMhs, $jalur . ' harus memanggil mhsId2().');
            $this->assertNotFalse($posisiUmum, $jalur . ' harus punya fallback pemohon umum.');
            $this->assertLessThan(
                $posisiUmum,
                $posisiMhs,
                $jalur . ': pengecekan OSM harus dilakukan SEBELUM fallback umum.',
            );
        }
    }

    // ------------------------------------------------------- spark eult:waktu ---

    /**
     * CI4::parseCommandLine() memakai ltrim($arg, '-') tanpa memisah '=',
     * sehingga `--since=2026-10-25` menjadi key `since=2026-10-25`, bukan
     * `since`. Bila command hanya membaca $params['since'], opsi berbentuk
     * `=` diabaikan diam-diam dan perintah berjalan dengan nilai default —
     * berbahaya untuk perintah yang mengubah data produksi.
     *
     * @return array<string, array{array<string, string|null>, string, ?string}>
     */
    public static function opsiCommandProvider(): array
    {
        return [
            'bentuk sama dengan (--since=...)' => [['since=2026-10-25' => null], 'since', '2026-10-25'],
            'bentuk spasi (--since ...)'       => [['since' => '2026-10-25'], 'since', '2026-10-25'],
            'nilai ikut sebagai value'         => [['since=2026-10-25' => '2026-10-25'], 'since', '2026-10-25'],
            'tidak diberikan'                  => [[], 'since', null],
            'opsi lain tidak tertukar'         => [['batalkan' => null], 'since', null],
        ];
    }

    /**
     * @dataProvider opsiCommandProvider
     */
    public function testParserOpsiCommandEultWaktu(array $params, string $nama, ?string $diharapkan): void
    {
        $metode = new ReflectionMethod($this->command(), 'nilaiOpsi');
        $metode->setAccessible(true);

        $this->assertSame($diharapkan, $metode->invoke($this->command(), $params, $nama));
    }

    public function testFlagCommandTerdeteksiDalamKeduaBentuk(): void
    {
        $metode = new ReflectionMethod($this->command(), 'punyaOpsi');
        $metode->setAccessible(true);

        $this->assertTrue($metode->invoke($this->command(), ['dry-run' => null], 'dry-run'));
        $this->assertTrue($metode->invoke($this->command(), ['dry-run=true' => null], 'dry-run'));
        $this->assertFalse($metode->invoke($this->command(), [], 'dry-run'));
        $this->assertFalse($metode->invoke($this->command(), ['batalkan' => null], 'dry-run'));
    }

    public function testCommandTerdaftarDiSpark(): void
    {
        $this->assertTrue(
            class_exists(\App\Commands\PerbaikiWaktu::class),
            'Perintah eult:waktu harus ada agar koreksi data bisa dijalankan.',
        );
    }

    /**
     * BaseCommand butuh LoggerInterface + Commands; ambil dari service agar
     * tes tidak perlu meniru konstruksi CLI.
     */
    private function command(): \App\Commands\PerbaikiWaktu
    {
        return new \App\Commands\PerbaikiWaktu(service('logger'), service('commands'));
    }
}
