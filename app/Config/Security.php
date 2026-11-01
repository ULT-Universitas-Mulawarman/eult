<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Security extends BaseConfig
{
    /**
     * --------------------------------------------------------------------------
     * CSRF Protection Method
     * --------------------------------------------------------------------------
     *
     * Protection Method for Cross Site Request Forgery protection.
     *
     * @var string 'cookie' or 'session'
     */
    public string $csrfProtection = 'cookie';

    /**
     * --------------------------------------------------------------------------
     * CSRF Token Randomization
     * --------------------------------------------------------------------------
     *
     * Randomize the CSRF Token for added security.
     */
    public bool $tokenRandomize = true;

    /**
     * --------------------------------------------------------------------------
     * CSRF Token Name
     * --------------------------------------------------------------------------
     *
     * Token name for Cross Site Request Forgery protection.
     */
    public string $tokenName = 'csrf_test_name';

    /**
     * --------------------------------------------------------------------------
     * CSRF Header Name
     * --------------------------------------------------------------------------
     *
     * Header name for Cross Site Request Forgery protection.
     */
    public string $headerName = 'X-CSRF-TOKEN';

    /**
     * --------------------------------------------------------------------------
     * CSRF Cookie Name
     * --------------------------------------------------------------------------
     *
     * Cookie name for Cross Site Request Forgery protection.
     */
    public string $cookieName = 'csrf_cookie_name';

    /**
     * --------------------------------------------------------------------------
     * CSRF Expires
     * --------------------------------------------------------------------------
     *
     * Expiration time for Cross Site Request Forgery protection cookie.
     *
     * Disamakan dengan umur sesi (Config\Session::$expiration = 8 jam) agar
     * token tidak kedaluwarsa lebih cepat daripada sesi yang dilindunginya.
     * Laporan operator 2026-11-01: meninggalkan aplikasi 30-60 menit untuk
     * memeriksa aplikasi lain lalu kembali menyelesaikan tiket harus tetap
     * berhasil tanpa dialog "Sesi Berakhir".
     */
    public int $expires = 28800;

    /**
     * --------------------------------------------------------------------------
     * CSRF Regenerate
     * --------------------------------------------------------------------------
     *
     * Regenerate CSRF Token on every submission.
     *
     * DINONAKTIFKAN untuk E-ULT (laporan operator 2026-11-01).
     *
     * Rotasi per-POST mengganti hash di cookie `csrf_cookie_name`, sedangkan
     * cookie itu dipakai bersama SEMUA tab pada domain yang sama. Token yang
     * tertanam di sebuah halaman hanya diperbarui pada tab yang melakukan
     * request (lihat public/assets/js/pages/eult-csrf.js), sehingga:
     *
     *   - operator membuka detail tiket di tab baru, melakukan satu aksi di
     *     sana, lalu kembali ke tab daftar → token tab daftar sudah basi;
     *   - klik centang hijau untuk menyelesaikan tiket ditolak HTTP 403 dan
     *     eult-csrf.js menampilkan dialog "Sesi Berakhir" padahal sesi masih
     *     hidup (muat ulang halaman "menyembuhkan" gejalanya).
     *
     * Dengan `false`, satu hash berlaku selama cookie CSRF hidup sehingga
     * multi-tab dan submit berurutan tetap sah. `$tokenRandomize` tetap
     * `true`, jadi setiap output token tetap di-mask (mitigasi BREACH) dan
     * token mentah tidak pernah dikirim ke browser.
     */
    public bool $regenerate = false;

    /**
     * --------------------------------------------------------------------------
     * CSRF Redirect
     * --------------------------------------------------------------------------
     *
     * Redirect to previous page with error on failure.
     *
     * @see https://codeigniter4.github.io/userguide/libraries/security.html#redirection-on-failure
     */
    public bool $redirect = (ENVIRONMENT === 'production');
}
