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
        $posisiIframe     = strpos($isi, '<iframe src="<?= esc($loadpdf_url');

        self::assertNotFalse($posisiPembungkus, 'Iframe pratinjau SHALL dibungkus kelas yang menyembunyikannya di layar kecil.');
        self::assertNotFalse($posisiIframe, 'Iframe pratinjau PDF desktop SHALL tetap ada.');
        self::assertLessThan(
            $posisiIframe,
            $posisiPembungkus,
            'Pembungkus d-none d-lg-block SHALL mendahului iframe agar iframe hanya tampil di layar besar.'
        );
    }
}
