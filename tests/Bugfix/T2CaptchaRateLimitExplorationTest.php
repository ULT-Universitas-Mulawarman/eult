<?php

namespace Tests\Bugfix;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Config\Throttle as ThrottleConfig;

/**
 * Task 13 — Test eksplorasi bug condition T2: "Captcha Kosmetik Tanpa
 * Rate Limiting" (bugfix.md 1.18, 1.19, 1.20; design.md bagian T2).
 *
 * CRITICAL: Test ini HARUS GAGAL pada kode belum diperbaiki — kegagalan
 * mengonfirmasi bug ada. DO NOT attempt to fix the test or the code
 * ketika gagal.
 *
 * GOAL: Surface counterexample bahwa (a) nilai captcha terbaca langsung
 * dari DOM tanpa OCR/effort apa pun, DAN (b) tidak ada mekanisme rate
 * limiting apa pun (per-IP/per-sesi) pada endpoint otentikasi dan
 * buat-tiket, sehingga brute force kredensial dan mail bombing via
 * pembuatan tiket massal keduanya terbuka.
 *
 * ═══════════════════════════════════════════════════════════════════
 * MENGAPA `FeatureTestTrait` (in-process) UNTUK KEDUA ENDPOINT
 * (Otentifikasi::index() DAN Login::savetiket()) — BUKAN cURL-ke-
 * server-live (berbeda dari K1SqlInjectionHttpIntegrationTest.php,
 * T4RatingHttpIntegrationTest.php):
 * ═══════════════════════════════════════════════════════════════════
 * Kedua controller DIBACA LANGSUNG untuk test ini — TIDAK PERNAH
 * memanggil `eult_message_kirim()` (app/Helpers/eult_message_helper.php,
 * yang memanggil `response()->send(); exit;` sungguhan) pada jalur
 * mana pun:
 *
 * - `Otentifikasi::index()` (app/Controllers/Otentifikasi.php): SETIAP
 *   return statement (gagal validasi, captcha salah, kredensial salah,
 *   sukses) memakai `return $this->response->setJSON([...])` — pola
 *   return normal CI4.
 * - `Login::savetiket()` (app/Controllers/Login.php): dikonfirmasi
 *   ulang oleh T1CsrfExplorationTest.php (task 12) — SETIAP return
 *   statement memakai `return $this->response->setJSON([...])`, TIDAK
 *   ADA satu pun panggilan `eult_message_kirim()`.
 *
 * Oleh karena itu `FeatureTestTrait::call()`/`::post()` (in-process)
 * AMAN dipakai untuk KEDUA endpoint pada file test ini — berbeda dari
 * `Cektiket::rating()` yang memanggil helper tersebut pada jalur sukses
 * maupun error (lihat docblock K1SqlInjectionHttpIntegrationTest.php
 * untuk investigasi exit; yang tidak kompatibel dengan in-process
 * PHPUnit).
 *
 * ═══════════════════════════════════════════════════════════════════
 * SKALA REQUEST — MENGAPA 15 (BUKAN 1000) UNTUK KEDUA SUB-KLAIM, DAN
 * MENGAPA INI TETAP COUNTEREXAMPLE YANG MEYAKINKAN:
 * ═══════════════════════════════════════════════════════════════════
 * bugfix.md 1.19/1.20 dan design.md menyebut "1000 request" sebagai
 * angka ilustratif skenario serangan (brute force/mail bombing), BUKAN
 * ambang batas yang harus benar-benar direproduksi literal di test
 * suite otomatis. Task 13 sendiri secara eksplisit meminta
 * "konfirmasi via pencarian" bahwa TIDAK ADA `Config/Throttle.php` di
 * codebase — pencarian tersebut (`grep`/`file_search` untuk
 * `Throttle`/`Throttler` di seluruh `app/`) SUDAH dilakukan sebelum
 * test ini ditulis dan mengonfirmasi NOL kecocokan; `app/Config/
 * Filters.php` juga dikonfirmasi TIDAK memiliki alias `'throttle'`
 * apa pun terdaftar di `$aliases`, dan `otentifikasi`/`savetiket` TIDAK
 * muncul di `$globals`/`$filters` mana pun. Bug condition ini adalah
 * FAKTA ARSITEKTURAL biner (filter throttle ada dan aktif, ATAU tidak
 * ada sama sekali) — bukan properti statistik/probabilistik yang
 * butuh sampel besar untuk terdeteksi: jika filter throttle benar-benar
 * ada dengan window/limit realistis apa pun (bahkan yang sangat
 * longgar, misal 10-20 request/menit), ia AKAN menolak salah satu dari
 * 15 request beruntun dalam window singkat. Tidak satu pun DITOLAK
 * (dibuktikan test ini) sudah cukup membuktikan TIDAK ADA throttle sama
 * sekali — mengirim 1000 tidak akan mengungkap fakta baru yang berbeda
 * dari mengirim 15, karena tidak ada mekanisme apa pun yang bisa
 * "menyerah setelah N request tapi lolos di bawah N" jika N tidak ada.
 *
 * Alasan TAMBAHAN dan LEBIH KRITIS untuk membatasi angka pada KEDUA
 * sub-klaim (bukan sekadar kenyamanan performa test suite) —
 * SIDE-EFFECT DUNIA NYATA TERHADAP AKUN/LAYANAN PIHAK KETIGA:
 *
 * (1) `Otentifikasi::index()` -> `cekDatabase()` -> `OsmClient::
 *     postLogin()` (app/Libraries/OsmClient.php) memanggil
 *     `Services::curlrequest()->post()` SUNGGUHAN ke `EULT_OSM_URL`
 *     (dikonfirmasi di .env: `https://osm.unmul.ac.id`, endpoint
 *     `/auth` lalu `/login`) dengan kredensial NYATA
 *     (`EULT_OSM_PLPKKN_USER/PASS/KEY`) — SETIAP request yang melewati
 *     gerbang validasi+captcha (yaitu SETIAP request pada test ini,
 *     karena test ini justru membuktikan tidak ada apa pun yang
 *     menghentikannya) memicu 2 panggilan HTTP NYATA ke API produksi
 *     UNMUL milik pihak ketiga. `OsmClient` di-instansiasi hardcoded
 *     via `new OsmClient()` di `Otentifikasi::initController()` —
 *     TIDAK ADA seam dependency-injection untuk mengganti/mock-nya
 *     tanpa menyentuh kode produksi (di luar cakupan task 13 — itu
 *     tugas task 19 jika sama sekali relevan, dan mocking dilarang
 *     oleh pedoman testing). Mengirim 1000 request berarti 2000
 *     panggilan HTTP nyata beruntun ke server pihak ketiga yang tidak
 *     dimiliki proyek ini — analog dengan concern SMTP pada
 *     login/savetiket, namun untuk API otentikasi eksternal.
 * (2) `Login::savetiket()` jalur sukses memanggil
 *     `$this->email->buat($email, ..., $datas)` ->
 *     `PengirimEmail::buat()` -> `Services::email()->send()` — SMTP
 *     NYATA (dikonfirmasi hidup oleh T1CsrfExplorationTest.php/task 6),
 *     bukan mock. 1000 email sungguhan (bahkan ke domain `.invalid`
 *     yang gagal DNS) berisiko memicu rate-limit/pembatasan akun pada
 *     relay SMTP nyata yang dikonfigurasi di `.env`.
 *
 * Oleh karena itu KEDUA sub-klaim (bukan hanya bagian email) diskalakan
 * ke **15 request beruntun** — cukup untuk mendemonstrasikan secara
 * KONKRET (bukan sekadar argumen teoretis) bahwa TIDAK SATU PUN dari
 * rangkaian tersebut ditolak dengan sinyal rate-limit (HTTP 429 atau
 * respons setara), sekaligus membatasi panggilan nyata ke OSM (30
 * panggilan HTTP, bukan 2000) dan SMTP (15 email, bukan 1000) pada
 * skala yang wajar untuk CI/test suite otomatis.
 *
 * ═══════════════════════════════════════════════════════════════════
 * KREDENSIAL UJI Otentifikasi — username/password SENGAJA SALAH:
 * ═══════════════════════════════════════════════════════════════════
 * Test ini TIDAK bertujuan membuktikan login BERHASIL — tujuannya
 * murni membuktikan TIDAK ADA throttling pada level REQUEST, terlepas
 * hasil akhir login itu sendiri (bugfix.md 1.20: "brute force kredensial
 * admin TANPA BATAS" — yang dibuktikan adalah tidak ada hambatan
 * mengirim percobaan beruntun, bukan bahwa percobaan tersebut berhasil
 * membobol akun). Username/password acak-tidak-ada dipakai (bukan akun
 * admin nyata) — request tetap harus MELEWATI gerbang validasi
 * (`username`, `captcha`, `password` semua required) dan gerbang
 * captcha (captcha benar, di-seed via session persis pola
 * T1CsrfExplorationTest.php) untuk MENCAPAI titik di mana rate-limiting
 * SEHARUSNYA aktif (setelah captcha, sebelum/selama cekDatabase()) —
 * inilah kondisi minimal yang diminta task 13 ("field yang diperlukan
 * untuk mencapai gerbang captcha-check").
 *
 * ═══════════════════════════════════════════════════════════════════
 * DATA UJI login/savetiket — cleanup MULTIPLE baris:
 * ═══════════════════════════════════════════════════════════════════
 * `eult_generate_kode()` (app/Helpers/eult_kode_helper.php) menghasilkan
 * kode 8-huruf ACAK (format XXXX-XXXX, ruang 26^8) untuk SETIAP
 * pemanggilan `savetiket()` — 15 request beruntun pada test ini
 * masing-masing hampir pasti mendapat `ticketTrackingId` UNIK berbeda
 * (kolisi pada ruang seluas ini astronomis kecil kemungkinannya), semua
 * berbagi `ticketEmail` fixture `.invalid` YANG SAMA. Cleanup memakai
 * `WHERE ticketEmail = fixture` (BUKAN `WHERE ticketTrackingId = ...`
 * tunggal) — query ini otomatis menyapu SELURUH baris yang dihasilkan
 * loop N=15, idempoten di setUp() maupun tearDown(), mengikuti konvensi
 * T1CsrfExplorationTest.php.
 *
 * Requirements: 1.18, 1.19, 1.20 (bugfix.md — Current Behavior/Defect T2)
 *
 * @internal
 */
final class T2CaptchaRateLimitExplorationTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    /** Jumlah request beruntun dipakai untuk kedua sub-klaim rate-limit — lihat docblock kelas untuk justifikasi skala. */
    private const JUMLAH_REQUEST_BERUNTUN = 15;

    /** Domain RFC 2606 `.invalid` — tidak pernah resolve DNS sungguhan, aman dipakai sebagai ticketEmail fixture. */
    private const EMAIL_AMAN = 't2captcha-uji@example.invalid';

    /** Nilai captcha yang di-seed langsung ke session test ini sendiri (pola sama dengan T1CsrfExplorationTest.php). */
    private const CAPTCHA_DIKETAHUI = 'T2OK';

    /** Kredensial login SENGAJA SALAH — tujuan test murni membuktikan absennya throttle request, bukan login berhasil. */
    private const USERNAME_TIDAK_ADA = 'uji.t2.tidak.ada.9273';
    private const PASSWORD_SALAH     = 'password-salah-uji-t2';

    private BaseConnection $koneksi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->koneksi = \Config\Database::connect('default');

        self::assertSame(
            'db_newtiket',
            $this->koneksi->database,
            'Test eksplorasi ini WAJIB tersambung ke database nyata db_newtiket agar verifikasi efek samping (insert d_ticketing berulang) bermakna (bukan DB tests/mock).'
        );

        // Idempoten: bersihkan sisa data uji dari run sebelumnya (jika
        // ada, misal proses sebelumnya terganggu) SEBELUM test berjalan.
        $this->bersihkanData();
    }

    protected function tearDown(): void
    {
        $this->bersihkanData();

        parent::tearDown();
    }

    private function bersihkanData(): void
    {
        // WHERE ticketEmail = fixture menyapu SELURUH baris yang
        // dihasilkan loop N request (masing-masing ticketTrackingId
        // acak berbeda), bukan hanya satu baris — lihat docblock kelas.
        $this->koneksi->table('d_ticketing')->where('ticketEmail', self::EMAIL_AMAN)->delete();
    }

    /**
     * Kunci cache rate-limit untuk alias rute tertentu, dibangun PERSIS
     * mengikuti format `App\Filters\ThrottleFilter::before()` memakai IP
     * yang sama dengan request test.
     */
    private function kunciCacheThrottle(string $aliasRute): string
    {
        $request = $this->setupRequest('POST', $aliasRute);
        $ip      = $request->getIPAddress();

        return 'throttle_' . preg_replace('/[^A-Za-z0-9_]/', '_', $aliasRute) . '_' . $ip;
    }

    private function bersihkanCacheThrottle(string $aliasRute): void
    {
        Services::cache()->delete($this->kunciCacheThrottle($aliasRute));
    }

    /**
     * Menaruh counter throttle tepat PADA ambang limit dalam window yang
     * masih aktif, sehingga SATU request berikutnya sudah melewati batas
     * dan SHALL ditolak 429 — tanpa perlu mengirim puluhan request
     * beruntun (yang pada `login/savetiket` berarti puluhan insert tiket
     * + pengiriman email sungguhan, dan pada `otentifikasi` berarti
     * puluhan panggilan API eksternal `osm.unmul.ac.id`).
     *
     * Format data cache mengikuti ThrottleFilter: ['count' => int,
     * 'resetAt' => unix timestamp].
     */
    private function isiCacheThrottleSampaiLimit(string $aliasRute): void
    {
        $konfig = config(ThrottleConfig::class);
        $limit  = (int) ($konfig->routes[$aliasRute]['limit'] ?? $konfig->default['limit']);

        Services::cache()->save(
            $this->kunciCacheThrottle($aliasRute),
            ['count' => $limit, 'resetAt' => time() + 60],
            60
        );
    }

    /**
     * Menambahkan token CSRF (nama+nilai terkini) ke payload POST.
     * `injectMock('request')` + `resetSingle('security')` diperlukan agar
     * security service dibangun terhadap IncomingRequest (bukan
     * CLIRequest) — pola yang sama dipakai T2M3RateLimitHttpIntegrationTest.
     *
     * @param array<string, string> $field
     *
     * @return array<string, string>
     */
    private function denganTokenCsrf(string $aliasRute, array $field): array
    {
        Services::injectMock('request', $this->setupRequest('POST', $aliasRute));
        Services::resetSingle('security');

        return array_merge($field, [csrf_token() => csrf_hash()]);
    }

    /**
     * Property 2 (Preservation/Expected Behavior) — SETELAH fix T2:
     * captcha SHALL berupa GAMBAR, sehingga nilainya TIDAK PERNAH
     * terbaca sebagai teks pada DOM/HTML halaman login.
     *
     * Diuji lewat dispatch SUNGGUHAN `GET /login` (bukan render view
     * manual dengan data buatan): nilai captcha yang benar-benar
     * disimpan di session oleh `eult_captcha_generate()` diambil, lalu
     * dipastikan TIDAK muncul pada HTML yang dikirim ke klien, dan
     * halaman SHALL merujuk endpoint gambar `login/captcha_image`.
     */
    public function testCaptchaTidakTerbacaDariDomKarenaBerupaGambar(): void
    {
        $hasil = $this->get('login');

        $hasil->assertStatus(200);

        $htmlTerender = (string) $hasil->getBody();

        $nilaiCaptchaSesi = (string) (session()->get('captcha') ?? '');

        self::assertNotSame(
            '',
            $nilaiCaptchaSesi,
            'Prasyarat test: GET /login SHALL men-generate captcha ke session (eult_captcha_generate()).'
        );

        self::assertStringNotContainsString(
            $nilaiCaptchaSesi,
            $htmlTerender,
            sprintf(
                "EXPECTED BEHAVIOR T2 (Requirement 2.27) — nilai captcha session '%s' TIDAK BOLEH muncul sebagai teks polos pada HTML halaman login; captcha disajikan sebagai gambar agar tidak dapat dibaca dari DOM tanpa OCR.",
                $nilaiCaptchaSesi
            )
        );

        self::assertStringContainsString(
            'captcha_image',
            $htmlTerender,
            'EXPECTED BEHAVIOR T2 (Requirement 2.27) — halaman login SHALL merujuk endpoint gambar captcha (login/captcha_image).'
        );
    }

    /**
     * Property 2 (Preservation/Expected Behavior) — SETELAH fix T2:
     * `otentifikasi` SHALL dibatasi rate-limit per-IP. Request yang
     * melewati ambang batas dalam window yang sama SHALL ditolak 429
     * dengan body JSON `danger` (konsisten dengan kontrak respons
     * endpoint lain), BUKAN halaman error HTML mentah.
     *
     * Hanya SATU request yang dikirim: counter cache throttle diisi
     * tepat PADA ambang limit terlebih dahulu (lihat
     * `isiCacheThrottleSampaiLimit()`), sehingga request berikutnya
     * sudah pasti melewati batas. Pendekatan ini menghindari puluhan
     * panggilan HTTP nyata beruntun ke API pihak ketiga
     * `osm.unmul.ac.id` yang dipanggil `OsmClient::postLogin()` pada
     * setiap request yang lolos gerbang captcha.
     */
    public function testRequestBeruntunKeOtentifikasiDibatasiRateLimit(): void
    {
        $this->bersihkanCacheThrottle('otentifikasi');

        try {
            $this->isiCacheThrottleSampaiLimit('otentifikasi');

            $hasil = $this->post('otentifikasi', $this->denganTokenCsrf('otentifikasi', [
                'username' => self::USERNAME_TIDAK_ADA,
                'captcha'  => self::CAPTCHA_DIKETAHUI,
                'password' => self::PASSWORD_SALAH,
            ]));

            self::assertSame(
                429,
                $hasil->response()->getStatusCode(),
                'EXPECTED BEHAVIOR T2 (Requirement 2.29, 2.30) — request ke otentifikasi yang melewati ambang limit dalam window yang sama SHALL ditolak HTTP 429.'
            );

            self::assertStringContainsString(
                '"danger"',
                (string) $hasil->getBody(),
                'EXPECTED BEHAVIOR T2 (Requirement 2.30) — respons 429 SHALL berupa JSON graceful ({"status":"danger",...}) agar dapat ditangani JS AJAX, bukan halaman error HTML mentah.'
            );
        } finally {
            $this->bersihkanCacheThrottle('otentifikasi');
        }
    }

    /**
     * Property 2 (Preservation/Expected Behavior) — SETELAH fix T2:
     * `login/savetiket` SHALL dibatasi rate-limit per-IP, sehingga vektor
     * mail bombing / cost amplification (request beruntun yang
     * masing-masing memicu insert tiket + pengiriman email) tertutup.
     *
     * Hanya SATU request yang dikirim — counter cache throttle diisi
     * tepat PADA ambang limit terlebih dahulu (lihat
     * `isiCacheThrottleSampaiLimit()`), sehingga request berikutnya sudah
     * pasti melewati batas. Pendekatan ini menghindari puluhan insert
     * tiket DAN puluhan pengiriman email SMTP nyata yang akan terjadi
     * bila test mengirim request beruntun sampai menembus limit.
     */
    public function testRequestBeruntunKeSavetiketDibatasiRateLimit(): void
    {
        $this->bersihkanData();
        $this->bersihkanCacheThrottle('login/savetiket');

        try {
            $this->isiCacheThrottleSampaiLimit('login/savetiket');

            $hasil = $this->withSession(['captcha' => self::CAPTCHA_DIKETAHUI])
                ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
                ->post('login/savetiket', $this->denganTokenCsrf('login/savetiket', $this->fieldFormSavetiketLengkap(1)));

            self::assertSame(
                429,
                $hasil->response()->getStatusCode(),
                'EXPECTED BEHAVIOR T2 (Requirement 2.29, 2.30) — request ke login/savetiket yang melewati ambang limit dalam window yang sama SHALL ditolak HTTP 429.'
            );

            self::assertStringContainsString(
                '"danger"',
                (string) $hasil->getBody(),
                'EXPECTED BEHAVIOR T2 (Requirement 2.30) — respons 429 SHALL berupa JSON graceful ({"status":"danger",...}) agar dapat ditangani JS AJAX, bukan halaman error HTML mentah.'
            );

            self::assertSame(
                0,
                $this->koneksi->table('d_ticketing')->where('ticketEmail', self::EMAIL_AMAN)->countAllResults(),
                'EXPECTED BEHAVIOR T2 (Requirement 2.29) — request yang ditolak rate-limit SHALL TIDAK sampai membuat tiket baru (filter throttle berjalan sebelum controller), sehingga tidak ada email yang terkirim.'
            );
        } finally {
            $this->bersihkanCacheThrottle('login/savetiket');
            $this->bersihkanData();
        }
    }

    /**
     * Field POST lengkap untuk `login/savetiket` — mengikuti persis
     * pola `T1CsrfExplorationTest::fieldFormLengkapTanpaCsrf()` (gerbang
     * $aturan + FK ticketPriority), dengan ticketSubject/ticketMessage
     * disertai indeks iterasi ($nomorIterasi) semata untuk keterbacaan
     * log/debug — tidak memengaruhi logika bug yang diuji.
     *
     * @return array<string, string>
     */
    private function fieldFormSavetiketLengkap(int $nomorIterasi): array
    {
        return [
            'captcha'          => self::CAPTCHA_DIKETAHUI,
            'ticketCategories' => '1',
            'ticketEmail'      => self::EMAIL_AMAN,
            'ticketNoHp'       => '081234567890',
            'ticketSubject'    => 'Uji Eksplorasi T2 Rate-Limit #' . $nomorIterasi,
            'ticketMessage'    => 'Pesan uji eksplorasi bug condition T2 — request beruntun ke-' . $nomorIterasi . ' tanpa hambatan rate-limit apa pun.',
            'ticketName'       => 'Pemohon Uji T2RateLimit',
            'ticketPriority'   => '1',
        ];
    }
}
