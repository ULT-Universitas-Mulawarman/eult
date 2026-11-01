<?php

use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Regresi laporan operator (2026-11-01): dialog "Sesi Berakhir" muncul saat
 * menyelesaikan tiket, padahal sesi masih hidup.
 *
 * Penyebabnya bukan sesi, melainkan rotasi hash CSRF. Dengan
 * `Config\Security::$regenerate = true`, setiap POST yang lolos verifikasi
 * mengganti hash di cookie `csrf_cookie_name`. Cookie itu dipakai bersama
 * oleh SEMUA tab pada domain yang sama, sedangkan token yang tertanam di
 * halaman hanya diperbarui pada tab yang melakukan request. Akibatnya:
 *
 *   1. Operator membuka detail tiket di tab baru (GET — tidak merotasi).
 *   2. Tab detail melakukan satu POST (mis. verifikasi berkas) → hash rotasi.
 *   3. Kembali ke tab daftar, klik centang hijau → token tab itu sudah basi
 *      → HTTP 403 → eult-csrf.js menampilkan "Sesi Berakhir".
 *
 * Test ini mengunci kontrak yang diharapkan: token yang sama HARUS tetap
 * valid untuk POST berikutnya, sehingga satu tab tidak mematikan tab lain.
 *
 * @internal
 */
final class CsrfTokenStabilTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    /**
     * Rute publik yang dilindungi filter `csrf` global. Payload sengaja
     * KOSONG: `Login::cektiket()` berhenti di validasi `nomorTiket`
     * required dan mengembalikan JSON tanpa menyentuh database, sehingga
     * test ini tetap berjalan di CI yang tidak punya MySQL.
     */
    private const RUTE = 'login/cektiket';

    public function testTokenCsrfTetapValidSetelahPostSukses(): void
    {
        Services::injectMock('request', $this->setupRequest('POST', self::RUTE));
        Services::resetSingle('security');

        $token = csrf_token();
        $hash  = csrf_hash();

        $field = [$token => $hash];

        $this->postTanpaDitolak($field, 'POST pertama dengan token valid');

        // POST kedua memakai token yang SAMA — mewakili tab lain yang sudah
        // terbuka sebelum POST pertama, atau submit ulang pada halaman yang
        // sama. Dengan $regenerate = true ini melempar SecurityException
        // yang di produksi berujung dialog "Sesi Berakhir".
        $this->postTanpaDitolak(
            $field,
            'POST kedua dengan token yang sama (tab lain / submit ulang)'
        );
    }

    public function testKonfigurasiTidakMerotasiTokenDanUmurCookieMengikutiSesi(): void
    {
        $security = config('Security');
        $sesi     = config('Session');

        $this->assertFalse(
            $security->regenerate,
            'Config\Security::$regenerate SHALL false — rotasi per-POST membuat token di tab lain basi.'
        );

        $this->assertGreaterThanOrEqual(
            $sesi->expiration,
            $security->expires,
            'Umur cookie CSRF SHALL minimal sepanjang sesi, agar pengguna tidak kehilangan token '
            . 'saat meninggalkan aplikasi sejenak (laporan: 30-60 menit idle).'
        );
    }

    /**
     * Menjalankan POST dan gagal bila CSRF menolaknya. Di lingkungan test
     * `SecurityException` dilempar langsung (bukan diubah menjadi respons
     * 403), jadi penolakan dideteksi lewat exception maupun status.
     *
     * @param array<string, string> $field
     */
    private function postTanpaDitolak(array $field, string $konteks): void
    {
        try {
            $hasil = $this->post(self::RUTE, $field);
        } catch (SecurityException $e) {
            $this->fail($konteks . ' SHALL tidak ditolak CSRF, tetapi ditolak: ' . $e->getMessage());
        }

        $this->assertNotSame(
            403,
            $hasil->response()->getStatusCode(),
            $konteks . ' SHALL tidak menghasilkan HTTP 403.'
        );
    }
}
