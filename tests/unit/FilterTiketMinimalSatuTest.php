<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Kontrak filter daftar tiket "wajib minimal satu" (tanpa DB).
 *
 * Sebelumnya rentangTanggal selalu required (JS + controller) dan kata
 * kunci hanya menyaring baris DOM yang sudah tampil, sehingga daftar
 * tidak bisa disaring hanya dengan pencarian/status. Sekarang: semua
 * filter kosong ditolak, tetapi satu filter terisi (rentang, kata kunci,
 * layanan, atau status) sudah cukup untuk menyaring data.
 *
 * @internal
 */
final class FilterTiketMinimalSatuTest extends CIUnitTestCase
{
    private function baca(string $jalur): string
    {
        $isi = file_get_contents(ROOTPATH . $jalur);
        $this->assertNotFalse($isi, $jalur . ' harus terbaca.');

        return (string) $isi;
    }

    public function testKataKunciIkutTerkirimKeServer(): void
    {
        $view = $this->baca('app/Views/pages/ticketing/index.php');

        // Tanpa name, form.serialize() tidak mengirim kata kunci, jadi
        // filter "search saja" tidak pernah sampai ke controller.
        $this->assertMatchesRegularExpression(
            '#<input[^>]+id="keyword_filter"[^>]+name="keyword"#',
            $view,
            'Kolom kata kunci harus bernama keyword agar terkirim ke server.'
        );
    }

    public function testJsTidakLagiMewajibkanRentangDanLayananSecaraMutlak(): void
    {
        $js = $this->baca('public/assets/js/pages/custom/pages/ticketing/ticketing.js');

        $this->assertStringNotContainsString("rentangTanggal: 'required'", $js);
        $this->assertStringNotContainsString("layanan: 'required'", $js);
        // Penjaga minimal-satu di sisi klien sebelum AJAX dikirim.
        $this->assertStringContainsString('minimal satu filter', $js);
    }

    public function testControllerMenolakSemuaFilterKosongTetapiMengizinkanSatuTerisi(): void
    {
        $controller = $this->baca('app/Controllers/Ticketing.php');

        $this->assertStringNotContainsString("'rentangTanggal' => 'required'", $controller);
        $this->assertStringContainsString('minimal satu filter', $controller);
        $this->assertStringContainsString("getPost('keyword')", $controller);
    }

    public function testModelMenerapkanPencarianKataKunci(): void
    {
        $model = $this->baca('app/Models/ModelTicketing.php');

        $this->assertStringContainsString("like('ticketTrackingId'", $model);
        $this->assertStringContainsString("orLike('ticketName'", $model);
    }
}
