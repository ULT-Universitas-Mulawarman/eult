<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Regresi PDF validitas di mobile: penampil PDF bawaan browser mobile
 * membungkus PDF dalam sub-frame internal berskema lokal (konsol
 * melaporkan URL kosong `''`) yang tidak dapat diizinkan secara
 * spesifik oleh `frame-src`, sehingga iframe pratinjau tampil sebagai
 * konten terblokir. Navigasi top-level ke URL PDF yang sama TIDAK
 * diatur `frame-src`/`child-src`, jadi halaman validitas wajib
 * menyediakan tautan buka-PDF top-level dan iframe pratinjau hanya
 * untuk layar besar.
 *
 * Pemeriksaan struktural atas source view (pola AsetVersiTest) agar
 * mandiri dari state request global saat dirender dalam suite.
 *
 * @internal
 */
final class ValiditasPdfMobileTest extends CIUnitTestCase
{
    public function testValiditasMenyediakanTautanBukaPdfTopLevelUntukMobile(): void
    {
        $isi = (string) file_get_contents(APPPATH . 'Views/pages/validitas/index.php');

        self::assertStringContainsString(
            '<a href="<?= esc($loadpdf_url ?? \'\') ?>" target="_blank" rel="noopener"',
            $isi,
            'Halaman validitas SHALL menyediakan tautan top-level ke URL PDF yang sama agar dapat dibuka di mobile tanpa melalui frame.'
        );

        $posisiPembungkus = strpos($isi, 'd-none d-lg-block');
        $posisiIframe     = strpos($isi, '<iframe id="iframe-pratinjau-pdf" data-pdf-url="<?= esc($loadpdf_url');

        self::assertNotFalse($posisiPembungkus, 'Iframe pratinjau SHALL dibungkus kelas yang menyembunyikannya di layar kecil.');
        self::assertNotFalse($posisiIframe, 'Iframe pratinjau PDF desktop SHALL tetap ada.');
        self::assertLessThan(
            $posisiIframe,
            $posisiPembungkus,
            'Pembungkus d-none d-lg-block SHALL mendahului iframe agar iframe hanya tampil di layar besar.'
        );
        self::assertStringContainsString(
            "window.matchMedia('(min-width: 992px)')",
            $isi,
            'URL iframe SHALL hanya dipasang pada layar besar agar di mobile tidak ada request frame yang ditolak frame-src.'
        );
        self::assertStringNotContainsString(
            '<iframe src=',
            $isi,
            'Iframe SHALL tidak membawa src sejak awal; src dipasang skrip hanya pada layar besar.'
        );
    }

    public function testBannerSegelTerverifikasiDanProgresHalamanMobile(): void
    {
        $isi = (string) file_get_contents(APPPATH . 'Views/pages/validitas/index.php');

        self::assertStringContainsString('Surat Terverifikasi', $isi, 'Hasil validasi berhasil SHALL ditegaskan lewat banner terverifikasi.');
        self::assertStringContainsString('class="segel-lingkaran"', $isi, 'Segel verifikasi SHALL berupa SVG garis yang dapat digambar animasinya.');
        self::assertStringContainsString('class="segel-centang"', $isi, 'Segel verifikasi SHALL memuat centang SVG.');
        self::assertStringContainsString('prefers-reduced-motion', $isi, 'Animasi segel dan halaman SHALL menghormati prefers-reduced-motion.');
        self::assertStringContainsString("'Memuat halaman ' + nomor + ' dari ' + pdf.numPages", $isi, 'Pemuatan PDF mobile SHALL melaporkan progres per halaman secara jujur.');
        self::assertStringContainsString('halaman-pdf-masuk', $isi, 'Kanvas halaman SHALL memakai kelas transisi masuk.');
    }

    public function testPolishMobileJudulTabelTombolDanStatusDapatDiakses(): void
    {
        $isi = (string) file_get_contents(APPPATH . 'Views/pages/validitas/index.php');

        self::assertStringContainsString('<html lang="id">', $isi, 'Bahasa dokumen SHALL sesuai konten Indonesia.');
        self::assertStringContainsString('class="tabel-validasi"', $isi, 'Tabel data validasi SHALL memakai kelas responsif khusus.');
        self::assertStringContainsString('.tabel-validasi td:nth-child(2)', $isi, 'Kolom titik dua SHALL disembunyikan pada susunan bertumpuk mobile.');
        self::assertStringContainsString('btn btn-primary', $isi, 'Tombol buka PDF SHALL memakai warna aksi primer sistem.');
        self::assertStringContainsString('tombol-buka-pdf', $isi, 'Tombol buka PDF SHALL memakai kelas target sentuh mobile.');
        self::assertStringContainsString('aria-live="polite"', $isi, 'Status pemuatan pratinjau SHALL diumumkan pembaca layar.');
        self::assertStringContainsString('.card-header .ribbon-target', $isi, 'Ribbon dekoratif SHALL diatur agar tidak menabrak judul di mobile.');
    }

    public function testPenampilPdfMobileMerenderKeCanvasDenganPdfJsSelfHosted(): void
    {
        $isi = (string) file_get_contents(APPPATH . 'Views/pages/validitas/index.php');

        self::assertStringContainsString('id="penampil-pdf-mobile"', $isi, 'Wadah pratinjau canvas mobile SHALL ada.');
        self::assertStringContainsString('assets_val/js/pdfjs/pdf.min.js', $isi, 'PDF.js SHALL dimuat dari aset sendiri, bukan CDN.');
        self::assertStringContainsString('assets_val/js/pdfjs/pdf.worker.min.js', $isi, 'Worker PDF.js SHALL dimuat dari aset sendiri.');
        self::assertStringContainsString('pdfjsLib.getDocument', $isi, 'Pratinjau mobile SHALL memuat PDF lewat PDF.js.');
        self::assertStringContainsString("createElement('canvas')", $isi, 'Halaman PDF SHALL dirender ke canvas, bukan frame.');

        foreach (['pdf.min.js', 'pdf.worker.min.js'] as $berkas) {
            $jalur = FCPATH . 'assets_val/js/pdfjs/' . $berkas;
            self::assertFileExists($jalur, 'Aset PDF.js ' . $berkas . ' SHALL tersedia di public.');
            self::assertGreaterThan(100_000, (int) filesize($jalur), 'Aset ' . $berkas . ' SHALL berisi library utuh, bukan berkas kosong.');
        }
    }
}
