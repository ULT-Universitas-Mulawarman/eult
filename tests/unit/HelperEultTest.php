<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Test helper EULT (captcha, kode, tanggal, waktu, password).
 *
 * @internal
 */
final class HelperEultTest extends CIUnitTestCase
{
    public function testCaptchaGenerateDanCek(): void
    {
        $kode = eult_captcha_generate(4);

        $this->assertSame(4, strlen($kode));
        $this->assertTrue(eult_captcha_check($kode));
        $this->assertTrue(eult_captcha_check(strtolower($kode)));
        $this->assertFalse(eult_captcha_check('SALAH'));
    }

    public function testCaptchaTanpaKarakterAmbigu(): void
    {
        // Karakter yang mudah tertukar (O/0, I/1, S/5, B/8, Z/2) adalah
        // penyebab utama captcha "sering salah" — tidak boleh muncul.
        for ($i = 0; $i < 50; $i++) {
            $kode = eult_captcha_generate(4);
            $this->assertMatchesRegularExpression('/^[ABCDEFGHJKLMNPQRSTUVWXYZ3479]{4}$/', $kode, 'Captcha di luar alfabet aman: ' . $kode);
            $this->assertDoesNotMatchRegularExpression('/[OI012568]/', $kode, 'Captcha mengandung karakter ambigu: ' . $kode);
        }
    }

    public function testCaptchaGambarLebihBesar(): void
    {
        $png  = eult_captcha_image('AB34');
        $info = getimagesizefromstring($png);

        $this->assertNotFalse($info, 'Captcha harus berupa gambar valid.');
        $this->assertGreaterThanOrEqual(200, $info[0], 'Lebar captcha minimal 200px agar terbaca.');
        $this->assertGreaterThanOrEqual(70, $info[1], 'Tinggi captcha minimal 70px agar terbaca.');
    }

    public function testGenerateKodeFormatTiket(): void
    {
        $kode = eult_generate_kode();

        $this->assertMatchesRegularExpression('/^[A-Z]{4}-[A-Z]{4}$/', $kode);
    }

    public function testTanggalIndo(): void
    {
        $this->assertSame('13 September 2026', eult_tanggal_indo('2026-09-13'));
        $this->assertFalse(eult_tanggal_indo('0000-00-00'));
        $this->assertSame('Senin', eult_hari_indo('Mon'));
        $this->assertSame('Tidak di ketahui', eult_hari_indo('Xyz'));
    }

    public function testWaktuLalu(): void
    {
        $hasil = eult_waktu_lalu(date('Y-m-d H:i:s', time() - 10));

        $this->assertStringEndsWith('ago', $hasil);
    }

    public function testGeneratePasswordAcakMinimal12Karakter(): void
    {
        $sandi = eult_generate_password(6);

        // Permintaan di bawah 12 karakter dinaikkan ke minimum agar password
        // sementara tidak dapat ditebak (sebelumnya 6 digit angka unik).
        $this->assertSame(12, strlen($sandi));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $sandi);
        $this->assertSame(16, strlen(eult_generate_password(16)));
        $this->assertNotSame(eult_generate_password(), eult_generate_password());
    }
}
