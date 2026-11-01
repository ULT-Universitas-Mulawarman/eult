<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Regresi cache-buster aset: date('YmdHis') berubah tiap detik sehingga
 * URL aset tidak pernah sama antar request dan browser tidak pernah
 * memakai HTTP cache (aset 4 MB dimuat ulang di setiap halaman).
 *
 * eult_versi_aset() menggantikannya dengan filemtime — versi hanya
 * berubah saat filenya berubah.
 *
 * @internal
 */
final class AsetVersiTest extends CIUnitTestCase
{
    public function testAsetAdaMengembalikanStempelWaktuNumerik(): void
    {
        $versi = eult_versi_aset('assets/js/pages/eult-csrf.js');

        $this->assertMatchesRegularExpression('/^\d+$/', $versi);
        $this->assertNotSame('1', $versi);
    }

    public function testAsetTidakAdaJatuhKeSatu(): void
    {
        $this->assertSame('1', eult_versi_aset('assets/tidak-ada-9000.js'));
    }

    public function testVersiStabilAntarPanggilan(): void
    {
        $jalur = 'assets/js/pages/eult-csrf.js';

        $this->assertSame(eult_versi_aset($jalur), eult_versi_aset($jalur));
    }

    public function testEultUrlAsetMenyertakanBaseUrlDanParameterVersi(): void
    {
        $this->assertTrue(
            function_exists('eult_url_aset'),
            'Helper eult_url_aset() harus tersedia secara global.'
        );

        $jalur = 'assets/plugins/global/plugins.bundle.js';
        $url   = eult_url_aset($jalur);
        $versi = eult_versi_aset($jalur);

        $this->assertSame(base_url($jalur) . '?v=' . $versi, $url);
        $this->assertSame($url, eult_url_aset('/' . $jalur));
    }

    public function testSeluruhViewUtamaMemuatBundleDenganParameterVersiCacheBusting(): void
    {
        $daftarView = [
            APPPATH . 'Views/layouts/template.php',
            APPPATH . 'Views/layouts/login.php',
            APPPATH . 'Views/pages/ticketing/detail_user.php',
            APPPATH . 'Views/pages/validitas/index.php',
        ];

        foreach ($daftarView as $berkasView) {
            $isi = (string) file_get_contents($berkasView);

            // Tidak boleh ada pemanggilan langsung .bundle.js / .bundle.css tanpa eult_url_aset / ?v=
            $this->assertDoesNotMatchRegularExpression(
                '/(?:href|src)=["\'][^"\']*\.bundle\.(?:js|css)["\']/i',
                $isi,
                'Berkas view ' . basename($berkasView) . ' masih memuat .bundle.js/.bundle.css tanpa parameter versi cache-busting.'
            );
        }

        $isiValiditas = (string) file_get_contents(APPPATH . 'Views/pages/validitas/index.php');
        $this->assertStringNotContainsString(
            'src="assets_val/',
            $isiValiditas,
            'validitas/index.php tidak boleh menggunakan path relatif mentah src="assets_val/.'
        );
    }

    public function testBundleUtamaSudahTerminifikasiDanMemilikiBerkasGzipPendamping(): void
    {
        $batasMaksimal = [
            'assets/plugins/global/plugins.bundle.js'          => 2_000_000,
            'assets/plugins/global/plugins.bundle.css'         => 390_000,
            'assets/js/scripts.bundle.js'                      => 160_000,
            'assets/css/style.bundle.css'                      => 1_100_000,
            'assets/plugins/custom/tinymce/tinymce.bundle.js'  => 1_150_000,
            'assets/plugins/custom/fullcalendar/fullcalendar.bundle.js' => 260_000,
            'assets_val/plugins/global/plugins.bundle.js'      => 2_550_000,
            'assets_val/plugins/global/plugins.bundle.css'     => 480_000,
            'assets_val/js/scripts.bundle.js'                  => 160_000,
            'assets_val/css/style.bundle.css'                  => 1_850_000,
        ];

        foreach ($batasMaksimal as $relatif => $batasByte) {
            $penuh = FCPATH . $relatif;
            $this->assertFileExists($penuh);
            $ukuran = (int) filesize($penuh);
            $this->assertLessThan(
                $batasByte,
                $ukuran,
                sprintf('Aset %s (%d byte) belum terminifikasi di bawah batas %d byte.', $relatif, $ukuran, $batasByte)
            );

            $jalurGz = $penuh . '.gz';
            $this->assertFileExists($jalurGz, 'Berkas prekompresi .gz wajib tersedia untuk ' . $relatif);
            $kepalaGz = (string) file_get_contents($jalurGz, false, null, 0, 2);
            $this->assertSame("\x1f\x8b", $kepalaGz, 'Berkas .gz harus memiliki header Gzip yang valid untuk ' . $relatif);
            $this->assertLessThan($ukuran, (int) filesize($jalurGz));
        }

        // Pastikan simbol global inti pada scripts.bundle.js tetap utuh (tidak di-mangle top-level)
        $isiScripts = (string) file_get_contents(FCPATH . 'assets/js/scripts.bundle.js');
        $this->assertStringContainsString('var KTApp=', $isiScripts);
        $this->assertStringContainsString('var KTUtil=', $isiScripts);
    }

    public function testBundleGlobalBebasDariProbeInlineEventHandlerDanInlineStyleCsp(): void
    {
        $berkasBundle = [
            'assets/plugins/global/plugins.bundle.js',
            'assets_val/plugins/global/plugins.bundle.js',
        ];

        foreach ($berkasBundle as $relatif) {
            $isi = (string) file_get_contents(FCPATH . $relatif);

            $this->assertDoesNotMatchRegularExpression(
                '/\.setAttribute\([^,]+,\s*["\']return;["\']\)/',
                $isi,
                sprintf('%s masih mengandung probe setAttribute(..., "return;") yang memicu pelanggaran CSP script-src-attr.', $relatif)
            );

            $this->assertStringNotContainsString(
                '<svg><p><style><img',
                $isi,
                sprintf('%s masih mengandung probe mXSS DOMPurify lama yang memicu pelanggaran CSP style-src-elem dan script-src-attr.', $relatif)
            );
        }
    }
}
