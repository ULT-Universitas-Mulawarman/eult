<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Regresi bug rating IKM admin: "tulisan sukses tapi email tidak terkirim
 * dan rating tidak tersimpan".
 *
 * Akar masalah: ticketing.js mengirim `$('#ticketId').text()` mentah yang
 * mengandung newline + indentasi markup, sehingga rating tersimpan di bawah
 * ID ber-spasi (tidak pernah ter-JOIN kembali) dan email dilewati karena
 * tiket "tidak ditemukan". JS juga menampilkan sukses hardcode tanpa
 * memeriksa status respons server.
 *
 * @internal
 */
final class RatingNomorTiketTest extends CIUnitTestCase
{
    public function testTicketingJsMengirimNomorTiketTanpaSpasi(): void
    {
        $js = (string) file_get_contents(FCPATH . 'assets/js/pages/custom/pages/ticketing/ticketing.js');

        $this->assertStringContainsString(
            "$('#ticketId').text().trim()",
            $js,
            'nomorTiket harus di-trim: teks #ticketId mengandung newline/indentasi markup.'
        );
    }

    public function testRatingAdminMemangkasInputDanMenolakTiketTidakDitemukan(): void
    {
        $sumber = (string) file_get_contents(APPPATH . 'Controllers/Ticketing.php');

        $this->assertStringContainsString(
            "trim((string) \$this->request->getPost('nomorTiket'))",
            $sumber,
            'Server tidak boleh memercayai nomorTiket mentah dari klien.'
        );
        $this->assertStringContainsString(
            'Tiket tidak ditemukan.',
            $sumber,
            'Rating untuk tiket yang tidak ada di DB harus ditolak eksplisit, bukan sukses semu.'
        );
    }

    public function testJsRatingAdminMemeriksaStatusResponsServer(): void
    {
        $js = (string) file_get_contents(FCPATH . 'assets/js/pages/custom/pages/ticketing/ticketing.js');

        $this->assertMatchesRegularExpression(
            '/res\.status\s*!==?\s*[\'"]success[\'"]/',
            $js,
            'Pesan sukses IKM tidak boleh hardcode: harus memeriksa status respons server.'
        );
    }

    public function testJsRatingPublikMemeriksaStatusResponsServer(): void
    {
        $sumber = (string) file_get_contents(APPPATH . 'Views/pages/ticketing/detail_user.php');

        $this->assertMatchesRegularExpression(
            '/response\.status\s*!==?\s*[\'"]success[\'"]|status\s*!==?\s*[\'"]success[\'"]/',
            $sumber,
            'Halaman lacak tiket tidak boleh menampilkan sukses bila server mengembalikan error.'
        );
    }

    public function testKegagalanKirimEmailDicatatKeLog(): void
    {
        $sumber = (string) file_get_contents(APPPATH . 'Libraries/PengirimEmail.php');

        $this->assertStringContainsString(
            'log_message',
            $sumber,
            'Hasil send() yang false tidak boleh hilang diam-diam; wajib tercatat di log.'
        );
    }

    public function testRatingMembedakanPesanBerkasTerkirimDanGagal(): void
    {
        foreach (['Ticketing.php', 'Cektiket.php'] as $berkas) {
            $sumber = (string) file_get_contents(APPPATH . 'Controllers/' . $berkas);

            $this->assertStringContainsString(
                'Penilaian tersimpan, tetapi berkas gagal dikirim via email.',
                $sumber,
                $berkas . ': rating tersimpan + email gagal tidak boleh diklaim terkirim.'
            );
        }
    }

    public function testRatingEmailGagalTetapDilaporkanSuksesSimpan(): void
    {
        // Rating yang sudah tersimpan TIDAK BOLEH dilaporkan gagal hanya
        // karena email berkas gagal terkirim; JS memeriksa status, jadi
        // cabang email-gagal wajib memakai status 'success'.
        foreach (['Ticketing.php', 'Cektiket.php'] as $berkas) {
            $sumber = (string) file_get_contents(APPPATH . 'Controllers/' . $berkas);

            $this->assertMatchesRegularExpression(
                "/berkas gagal dikirim via email\.[^']*',\s*'success'/",
                $sumber,
                $berkas . ': cabang email-gagal setelah simpan rating harus berstatus success.'
            );
        }
    }

    public function testRatingKeteranganDisimpanDanDikirimKlien(): void
    {
        foreach (['Ticketing.php', 'Cektiket.php'] as $berkas) {
            $sumber = (string) file_get_contents(APPPATH . 'Controllers/' . $berkas);

            $this->assertStringContainsString(
                "'ratingKeterangan'",
                $sumber,
                $berkas . ': keterangan rating harus ikut disimpan ke d_rating.'
            );
        }

        $publik = (string) file_get_contents(APPPATH . 'Views/pages/ticketing/detail_user.php');
        $this->assertStringContainsString('id="ratingKeterangan"', $publik, 'Form IKM publik harus punya field keterangan.');

        $admin = (string) file_get_contents(FCPATH . 'assets/js/pages/custom/pages/ticketing/ticketing.js');
        $this->assertStringContainsString('ratingKeterangan', $admin, 'JS rating admin harus mengirim ratingKeterangan.');
    }

    public function testPesanSuksesRatingIkutPesanServer(): void
    {
        $publik = (string) file_get_contents(APPPATH . 'Views/pages/ticketing/detail_user.php');
        $admin  = (string) file_get_contents(FCPATH . 'assets/js/pages/custom/pages/ticketing/ticketing.js');

        foreach (['publik' => $publik, 'admin' => $admin] as $jalur => $sumber) {
            $this->assertDoesNotMatchRegularExpression(
                "/text:\\s*'[^']*berkas telah kami kirimkan via email/i",
                $sumber,
                'JS rating ' . $jalur . ' tidak boleh hardcode klaim email terkirim; pakai pesan server.'
            );
        }
    }
}
