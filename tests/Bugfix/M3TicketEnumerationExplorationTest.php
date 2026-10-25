<?php

namespace Tests\Bugfix;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Config\Throttle as ThrottleConfig;

/**
 * Task 16 — Test eksplorasi bug condition M3: "Enumerasi Nomor Tiket
 * Tanpa Rate Limit" (bugfix.md 1.29, 1.30; design.md bagian M3).
 *
 * CRITICAL: Test ini HARUS GAGAL pada kode belum diperbaiki — kegagalan
 * mengonfirmasi bug ada. DO NOT attempt to fix the test or the code
 * ketika gagal.
 *
 * GOAL: Surface counterexample bahwa `Login::cektiket()`
 * (app/Controllers/Login.php:56-81, route `POST login/cektiket`)
 * memproses SELURUH request enumerasi nomor tiket beruntun TANPA
 * hambatan rate-limit apa pun, DAN pesan "Nomor tiket ditemukan..."
 * vs "Nomor tiket tidak ditemukan." tetap dapat dibedakan sepanjang
 * rangkaian tersebut — inilah oracle konkret yang memungkinkan
 * penyerang membedakan nomor tiket valid vs tidak valid secara masif
 * (bugfix.md 1.29, 1.30). M3 adalah derivatif eksplisit dari T2 (task
 * 13) — akar masalah SAMA (tidak ada `Config/Throttle.php`, tidak ada
 * filter rate-limiting apa pun di codebase), diterapkan pada endpoint
 * berbeda.
 *
 * ═══════════════════════════════════════════════════════════════════
 * KONFIRMASI PERILAKU `Login::cektiket()` SAAT INI (dibaca ulang
 * langsung dari app/Controllers/Login.php, BUKAN paraphrase — file ini
 * juga disentuh task 3.1/K1 lebih awal dalam sesi ini, sehingga
 * diverifikasi ulang state terkininya):
 * ═══════════════════════════════════════════════════════════════════
 * ```
 * public function cektiket()
 * {
 *     if (! $this->validate(['nomorTiket' => 'required'])) {
 *         return $this->response->setJSON([
 *             'status'  => 'danger',
 *             'message' => 'Nomor tiket wajib diisi.',
 *         ]);
 *     }
 *
 *     $nomorTiket = (string) $this->request->getPost('nomorTiket');
 *     $datas      = $this->tiket->byId(['ticketTrackingId' => $nomorTiket]);
 *
 *     if ($datas !== false) {
 *         $kunci = $this->enkripsi->encode($nomorTiket);
 *
 *         return $this->response->setJSON([
 *             'status'       => 'success',
 *             'message'      => 'Nomor tiket ditemukan, mengalihkan halaman...',
 *             'redirect_url' => base_url('cektiket/index/') . $kunci,
 *         ]);
 *     }
 *
 *     return $this->response->setJSON([
 *         'status'  => 'danger',
 *         'message' => 'Nomor tiket tidak ditemukan.',
 *     ]);
 * }
 * ```
 * Sudah memakai `byId(['ticketTrackingId' => $nomorTiket])` (array
 * binding, hasil fix K1/task 3.1 — bukan lagi string mentah), NAMUN
 * ini TIDAK relevan dengan bug M3: M3 murni soal ABSENSI rate-limit
 * pada endpoint ini, bukan soal SQL injection (K1 sudah tertutup
 * terpisah). Setiap return statement memakai
 * `return $this->response->setJSON([...])` — TIDAK ADA satu pun
 * panggilan `eult_message_kirim()` (app/Helpers/eult_message_helper.php,
 * yang memanggil `response()->send(); exit;` sungguhan) di method ini.
 * Method ini juga TIDAK melakukan insert/update/delete apa pun — HANYA
 * `byId()` (SELECT read-only) — sehingga TIDAK ADA efek samping
 * database yang perlu cleanup.
 *
 * ═══════════════════════════════════════════════════════════════════
 * MENGAPA `FeatureTestTrait` (in-process) — BUKAN cURL-ke-server-live:
 * ═══════════════════════════════════════════════════════════════════
 * Berdasarkan konfirmasi di atas, `cektiket()` AMAN didispatch
 * in-process via `FeatureTestTrait::post()` — sama seperti
 * `T2CaptchaRateLimitExplorationTest::testSeluruhRequestOtentifikasi
 * BeruntunDiprosesTanpaRateLimit()`/
 * `testSeluruhRequestSavetiketSuksesBeruntunDiprosesTanpaRateLimit()`,
 * berbeda dari `Cektiket::rating()`/`Cektiket::loadpdf()` yang memanggil
 * `eult_message_kirim()` pada jalur tertentu (lihat docblock
 * K1SqlInjectionHttpIntegrationTest.php) dan karenanya membutuhkan
 * dispatch HTTP terpisah ke server live.
 *
 * ═══════════════════════════════════════════════════════════════════
 * SKALA REQUEST — MENGAPA 15 (BUKAN 100/10.000) — REUSE JUSTIFIKASI
 * T2CaptchaRateLimitExplorationTest SECARA IDENTIK:
 * ═══════════════════════════════════════════════════════════════════
 * bugfix.md 1.30 dan task 16 menyebut "100 (atau 10.000 sesuai skenario
 * bugfix.md)" sebagai angka ilustratif skenario serangan enumerasi
 * massal, BUKAN ambang batas yang harus benar-benar direproduksi
 * literal di test suite otomatis. Bug condition M3 berbagi akar
 * masalah ARSITEKTURAL yang identik dengan T2 (task 13): tidak ada
 * `Config/Throttle.php` di codebase (dikonfirmasi ulang via pencarian
 * `Throttle`/`Throttler` di seluruh `app/` sebelum test ini ditulis —
 * NOL kecocokan selain lodash-style `throttle()` pada vendor JS plugin
 * tinymce yang tidak terkait), `app/Config/Filters.php` TIDAK memiliki
 * alias `'throttle'` apa pun terdaftar di `$aliases`, dan `login/
 * cektiket` TIDAK muncul di `$globals`/`$filters` mana pun. Fakta ini
 * BINER (filter throttle ada dan aktif, ATAU tidak ada sama sekali) —
 * bukan properti statistik yang butuh sampel besar: jika filter
 * throttle benar-benar ada dengan limit realistis apa pun (bahkan yang
 * sangat longgar), ia AKAN menolak salah satu dari 15 request beruntun
 * dalam window singkat. Tidak satu pun ditolak pada 15 request sudah
 * cukup membuktikan TIDAK ADA throttle sama sekali — mengirim 100/
 * 10.000 tidak mengungkap fakta baru yang berbeda dari mengirim 15,
 * karena tidak ada mekanisme apa pun yang bisa "menyerah setelah N
 * request tapi lolos di bawah N" jika N tidak ada. Endpoint ini juga
 * TIDAK memicu efek samping pihak ketiga (tidak ada email/API eksternal
 * di jalur `cektiket()` — hanya SELECT read-only), sehingga tidak ada
 * pertimbangan biaya/risiko tambahan seperti pada T2 (SMTP/OSM) — angka
 * 15 dipertahankan semata untuk KONSISTENSI dengan precedent T2 (per
 * instruksi task 16), bukan karena ada batasan biaya nyata di endpoint
 * ini secara spesifik.
 *
 * ═══════════════════════════════════════════════════════════════════
 * PEMBACAAN SINYAL "DITEMUKAN"/"TIDAK DITEMUKAN" DARI RESPONS — KUIRK
 * PEMBUNGKUS BODY JSON (identik dengan temuan T2/task 13):
 * ═══════════════════════════════════════════════════════════════════
 * Diverifikasi ulang secara empiris (bukan asumsi) pada endpoint ini
 * secara spesifik bahwa `$hasil->getBody()` pada dispatch
 * `FeatureTestTrait` di environment ini mengembalikan body yang
 * DIBUNGKUS shell `<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.0
 * Transitional//EN" ...><html><body>{...JSON asli...}</body></html>`
 * — `json_decode()` pada body tersebut SELALU mengembalikan `null`.
 * Oleh karena itu deteksi sinyal "ditemukan" vs "tidak ditemukan"
 * TIDAK memakai `json_decode()` sama sekali — memakai PENCARIAN
 * SUBSTRING LANGSUNG pada string body mentah untuk potongan pesan
 * literal method ini: `'Nomor tiket ditemukan'` (jalur `$datas !==
 * false`) vs `'Nomor tiket tidak ditemukan.'` (jalur sebaliknya).
 * Kedua substring ini SALING EKSKLUSIF dan tidak overlap satu sama
 * lain sebagai substring (dikonfirmasi dengan membaca literal pesan
 * di atas), sehingga pencarian `str_contains()` pada body mentah
 * adalah sinyal yang RELIABLE tanpa perlu parsing JSON — konsisten
 * dengan pendekatan T2 yang bergantung pada kode status HTTP + bukti
 * langsung dari database, bukan body JSON terparse.
 *
 * Requirements: 1.29, 1.30 (bugfix.md — Current Behavior/Defect M3)
 *
 * @internal
 */
final class M3TicketEnumerationExplorationTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    /**
     * Nomor tiket VALID sungguhan, terverifikasi ada di d_ticketing
     * (sama seperti dipakai K1SqlInjectionPreservationTest.php dan
     * T4RatingOwnershipExplorationTest.php — ticketEmail =
     * rizkyfkemala@gmail.com, dibaca langsung dari database live
     * db_newtiket pada sesi verifikasi sebelumnya).
     */
    private const NOMOR_TIKET_VALID = 'QEHO-HTTV-001';

    /** Potongan pesan literal jalur "ditemukan" — persis app/Controllers/Login.php::cektiket(). */
    private const PESAN_DITEMUKAN = 'Nomor tiket ditemukan';

    /** Potongan pesan literal jalur "tidak ditemukan" — persis app/Controllers/Login.php::cektiket(). */
    private const PESAN_TIDAK_DITEMUKAN = 'Nomor tiket tidak ditemukan.';

    private BaseConnection $koneksi;

    /**
     * Kunci cache rate-limit `login/cektiket`, dibangun PERSIS mengikuti
     * format `App\Filters\ThrottleFilter::before()` memakai IP yang sama
     * dengan request test.
     */
    private function kunciCacheThrottle(): string
    {
        $request = $this->setupRequest('POST', 'login/cektiket');
        $ip      = $request->getIPAddress();

        return 'throttle_login_cektiket_' . $ip;
    }

    private function bersihkanCacheThrottle(): void
    {
        Services::cache()->delete($this->kunciCacheThrottle());
    }

    /**
     * Menaruh counter throttle tepat PADA ambang limit dalam window yang
     * masih aktif, sehingga SATU request berikutnya sudah melewati batas
     * dan SHALL ditolak 429 — membuktikan penolakan tanpa harus mengirim
     * puluhan request beruntun.
     */
    private function isiCacheThrottleSampaiLimit(): void
    {
        $konfig = config(ThrottleConfig::class);
        $limit  = (int) ($konfig->routes['login/cektiket']['limit'] ?? $konfig->default['limit']);

        Services::cache()->save(
            $this->kunciCacheThrottle(),
            ['count' => $limit, 'resetAt' => time() + 60],
            60
        );
    }

    /**
     * Menambahkan token CSRF ke payload POST. `injectMock('request')` +
     * `resetSingle('security')` diperlukan agar security service dibangun
     * terhadap IncomingRequest (bukan CLIRequest) — pola yang sama dipakai
     * T2M3RateLimitHttpIntegrationTest.
     *
     * @param array<string, string> $field
     *
     * @return array<string, string>
     */
    private function denganTokenCsrf(array $field): array
    {
        Services::injectMock('request', $this->setupRequest('POST', 'login/cektiket'));
        Services::resetSingle('security');

        return array_merge($field, [csrf_token() => csrf_hash()]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->koneksi = \Config\Database::connect('default');

        self::assertSame(
            'db_newtiket',
            $this->koneksi->database,
            'Test eksplorasi ini WAJIB tersambung ke database nyata db_newtiket agar nomor tiket valid yang dipakai benar-benar merepresentasikan record nyata.'
        );

        self::assertNotFalse(
            $this->koneksi->table('d_ticketing')->where('ticketTrackingId', self::NOMOR_TIKET_VALID)->get()->getFirstRow('array'),
            sprintf(
                'Prasyarat test: nomor tiket %s SHALL benar-benar ada di d_ticketing agar sinyal "ditemukan" dapat diverifikasi terhadap record nyata.',
                self::NOMOR_TIKET_VALID
            )
        );
    }

    /**
     * `Login::cektiket()` HANYA melakukan `byId()` (SELECT read-only)
     * — TIDAK ADA insert/update/delete pada jalur mana pun (dikonfirmasi
     * via pembacaan penuh method di docblock kelas). Method setUp()
     * di atas hanya melakukan SELECT (pengecekan prasyarat), sehingga
     * TIDAK diperlukan tearDown()/cleanup apa pun — tidak ada baris
     * baru yang tercipta oleh test ini.
     */

    /**
     * Property 2 (Preservation/Expected Behavior) — SETELAH fix M3/T2:
     * enumerasi nomor tiket pada `login/cektiket` SHALL dibatasi
     * rate-limit per-IP, SEKALIGUS oracle pembeda
     * "ditemukan"/"tidak ditemukan" tetap berlaku normal untuk request
     * yang masih dalam ambang batas (fix rate-limit TIDAK mengubah pesan
     * spesifik menjadi generik — kontrol primernya adalah pembatasan
     * laju request, bukan penyamaran pesan).
     *
     * Pembuktian penolakan hanya memerlukan SATU request: counter cache
     * throttle diisi tepat PADA ambang limit terlebih dahulu (lihat
     * `isiCacheThrottleSampaiLimit()`), bukan dengan mengirim puluhan
     * request beruntun.
     */
    public function testEnumerasiNomorTiketDibatasiRateLimitDanSinyalTetapTerbedakan(): void
    {
        $this->bersihkanCacheThrottle();

        try {
            // (1) Oracle pembeda tetap berfungsi untuk request DALAM limit.
            $hasilValid = $this->post('login/cektiket', $this->denganTokenCsrf([
                'nomorTiket' => self::NOMOR_TIKET_VALID,
            ]));

            $hasilValid->assertStatus(200);

            $bodiValid = (string) $hasilValid->getBody();

            self::assertStringContainsString(
                self::PESAN_DITEMUKAN,
                $bodiValid,
                sprintf(
                    "Property 2 M3: nomor tiket VALID (%s) SHALL menghasilkan pesan '%s' selama request masih dalam ambang batas.",
                    self::NOMOR_TIKET_VALID,
                    self::PESAN_DITEMUKAN
                )
            );

            $hasilTebakan = $this->post('login/cektiket', $this->denganTokenCsrf([
                'nomorTiket' => 'ZZZZ-TEBK-001-ENUM01',
            ]));

            $hasilTebakan->assertStatus(200);

            $bodiTebakan = (string) $hasilTebakan->getBody();

            self::assertStringContainsString(
                self::PESAN_TIDAK_DITEMUKAN,
                $bodiTebakan,
                sprintf(
                    "Property 2 M3: nomor tiket TEBAKAN SHALL menghasilkan pesan '%s' selama request masih dalam ambang batas.",
                    self::PESAN_TIDAK_DITEMUKAN
                )
            );

            // (2) Melewati ambang limit → enumerasi masif terhenti (429).
            $this->isiCacheThrottleSampaiLimit();

            $hasilDitolak = $this->post('login/cektiket', $this->denganTokenCsrf([
                'nomorTiket' => self::NOMOR_TIKET_VALID,
            ]));

            self::assertSame(
                429,
                $hasilDitolak->response()->getStatusCode(),
                'EXPECTED BEHAVIOR M3 (Requirement 2.30, 2.42) — request ke login/cektiket yang melewati ambang limit dalam window yang sama SHALL ditolak HTTP 429, sehingga enumerasi nomor tiket tidak dapat dilakukan secara masif.'
            );

            self::assertStringContainsString(
                '"danger"',
                (string) $hasilDitolak->getBody(),
                'EXPECTED BEHAVIOR M3 (Requirement 2.30) — respons 429 SHALL berupa JSON graceful ({"status":"danger",...}), bukan halaman error HTML mentah.'
            );
        } finally {
            $this->bersihkanCacheThrottle();
        }
    }
}
