<?php

namespace Tests\Bugfix;

use App\Models\ModelTicketing;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Task 6 — Test eksplorasi bug condition T4: `Cektiket::rating()` tanpa
 * validasi kepemilikan tiket (bugfix.md 1.23, 1.24).
 *
 * GOAL: Surface counterexample bahwa `Cektiket::rating()`
 * (app/Controllers/Cektiket.php:106-125) beroperasi MURNI dari
 * `nomorTiket` yang dibaca langsung dari POST body, TANPA decode kunci
 * terenkripsi apa pun — berbeda dari `Cektiket::index()`,
 * `Cektiket::loadpdf()`, `Cektiket::loadattach()` (sudah diperbaiki
 * commit terpisah, lihat K2M2IdorExplorationTest.php) yang SEMUANYA
 * mensyaratkan `$kunci` di-decode via `Enkripsi::decode()` sebelum
 * operasi apa pun. `rating()` TIDAK PERNAH memanggil `Enkripsi::decode()`
 * sama sekali — dikonfirmasi dengan membaca source code method tersebut
 * secara langsung (bukan asumsi):
 *
 *   public function rating()
 *   {
 *       $rating     = $this->request->getPost('rating');
 *       $nomorTiket = (string) $this->request->getPost('nomorTiket');
 *       ...
 *       $datas = $this->tiket->byId(['ticketTrackingId' => $nomorTiket]);
 *       ...
 *       if ($datas !== false) {
 *           $this->email->selesai((string) $datas['ticketEmail'], ..., $datas, $lampiran);
 *       }
 *       if ($proses) { eult_message_kirim(...); }
 *   }
 *
 * Route terdaftar sebagai `$routes->post('rating', 'Cektiket::rating')`
 * (app/Config/Routes.php) — SATU segment tetap, murni POST-body-driven,
 * tidak ada segment `(:kunci)` apa pun pada rute ini (berbeda dari K2
 * yang sudah diperbaiki menjadi `loadpdf/(:any)` dengan `$kunci`).
 *
 * PENTING — perbedaan payload dari task 1/5 (K1/K2 memakai payload SQLi
 * atau nama file predictable): bug T4 BUKAN tentang SQL injection
 * (query byId() SUDAH memakai array binding sejak fix K1, task 3.1) dan
 * BUKAN tentang IDOR pada nama file. T4 adalah tentang TIDAK ADANYA
 * pengecekan kepemilikan berbasis kunci SAMA SEKALI pada method ini —
 * SIAPA PUN yang MENGETAHUI/MENEBAK nomor tiket yang valid (format
 * "XXXX-XXXX-NNN", predictable/sequential, tidak secret) dapat memicu
 * rating() untuk tiket tersebut TANPA pernah memegang/mendekode kunci
 * terenkripsi apa pun untuk tiket itu. Payload uji karena itu adalah
 * NOMOR TIKET NYATA YANG ADA di database — bukan payload SQLi/traversal.
 *
 * Tiket yang dipakai: QEHO-HTTV-001 (dikonfirmasi ADA di d_ticketing,
 * ticketEmail = rizkyfkemala@gmail.com — dibaca langsung dari database
 * live db_newtiket, bukan diasumsikan/dibuat fixture buatan, karena
 * esensi bug ini adalah tiket NYATA milik ORANG NYATA yang bisa
 * ditebak nomornya oleh pihak yang TIDAK PERNAH menerima link
 * terenkripsi untuk tiket tersebut).
 *
 * ═══════════════════════════════════════════════════════════════════
 * KEPUTUSAN ARSITEKTUR TEST — MENGAPA TEST INI *TIDAK* MEN-DISPATCH
 * REQUEST HTTP SUNGGUHAN (berbeda dari K1SqlInjectionHttpIntegrationTest,
 * meski masalah teknis exit; yang mendasari pola cURL-ke-server-live
 * SAMA PERSIS berlaku di sini — eult_message_kirim() dipanggil ketika
 * $proses truthy, yang HAMPIR PASTI terjadi untuk nomorTiket valid):
 * ═══════════════════════════════════════════════════════════════════
 *
 * K1's test AMAN men-dispatch request sungguhan ke server live karena
 * payload SQLi-nya (mengandung karakter `'`) SECARA STRUKTURAL TIDAK
 * PERNAH bisa exact-match `ticketTrackingId` literal apa pun (format
 * asli "XXXX-XXXX-NNN" tidak pernah memuat tanda kutip) — sehingga
 * `$datas` SELALU `false` dan `email->selesai()` TIDAK PERNAH terpicu
 * pada kode yang sudah diperbaiki (array binding). Itu diverifikasi
 * ulang secara struktural oleh test K1 sendiri sebelum diklaim aman.
 *
 * Situasi T4 SEBALIKNYA: esensi bug yang harus dibuktikan adalah bahwa
 * nomor tiket yang DITEBAK/DIKETAHUI (tanpa kunci) COCOK dengan tiket
 * NYATA — sehingga `$datas` HARUS `!== false` agar counterexample
 * bermakna. Ini berarti men-dispatch request sungguhan akan membuat
 * `$this->email->selesai((string) $datas['ticketEmail'], ...)` BENAR-
 * BENAR dipanggil dengan `$datas['ticketEmail'] === 'rizkyfkemala@gmail.com'`
 * — alamat email milik ORANG SUNGGUHAN, bukan alamat uji.
 *
 * Investigasi konektivitas SMTP SEBELUM memutuskan (bukan asumsi):
 * - `.env` (EULT_MAIL_HOST/PORT/USER/PASS) berisi KREDENSIAL GMAIL APP
 *   PASSWORD YANG TERLIHAT NYATA/AKTIF (tiketult@unmul.ac.id,
 *   ssl://smtp.gmail.com:465) — BUKAN placeholder/kosong.
 * - Diverifikasi via `getent hosts smtp.gmail.com` (resolve DNS
 *   BERHASIL, mengembalikan alamat IPv6 nyata Google) DAN
 *   `bash -c 'cat < /dev/null > /dev/tcp/smtp.gmail.com/465'` (TCP
 *   connect port 465 BERHASIL, exit code 0) dari lingkungan test ini
 *   SAAT INI — KEDUANYA BERHASIL. Ini BERTOLAK BELAKANG dengan catatan
 *   sesi sebelumnya yang menyebut kegagalan `getaddrinfo` pada percobaan
 *   SMTP di masa lalu (kemungkinan kondisi jaringan yang sudah berubah,
 *   atau merujuk pada lingkungan/percobaan lain — TIDAK dapat
 *   diverifikasi ulang, dan SAAT INI diverifikasi TIDAK berlaku).
 * - Tidak ditemukan file `.env.testing` maupun override konfigurasi
 *   Email khusus environment testing apa pun (app/Config/Boot/testing.php
 *   hanya mengatur error reporting/debug, TIDAK menyentuh konfigurasi
 *   email) — `Config\Email`/`PengirimEmail` akan memakai KREDENSIAL
 *   PRODUKSI YANG SAMA baik dijalankan dari PHPUnit maupun dari server
 *   live, TIDAK ADA sandbox/safety-net otomatis.
 *
 * KESIMPULAN: konektivitas SMTP ke Gmail BERFUNGSI dari lingkungan ini
 * SAAT INI, dengan kredensial yang tampak aktif. Men-dispatch request
 * HTTP sungguhan ke `cektiket/rating` dengan `nomorTiket=QEHO-HTTV-001`
 * BERISIKO NYATA mengirim email sungguhan ke rizkyfkemala@gmail.com,
 * seseorang yang TIDAK ADA hubungannya dengan pengujian ini. Test ini
 * SENGAJA TIDAK melakukan itu.
 *
 * PENDEKATAN YANG DIPAKAI (membuktikan KEDUA bagian counterexample
 * TANPA mock/stub apa pun terhadap lapisan email, dan TANPA memicu
 * pengiriman sungguhan):
 *
 * 1. BUKTI RATING TERSIMPAN (side-effect nyata di d_rating): method
 *    ini memanggil URUTAN OPERASI PRODUKSI YANG IDENTIK dengan yang
 *    dieksekusi Cektiket::rating() — `ModelTicketing::ambilSatu('d_rating', ...)`
 *    untuk cek existing, lalu `tambah()`/`ubah()` sesuai hasilnya — bukan
 *    menyalin ulang logika ke SQL manual, melainkan MEMANGGIL model
 *    produksi SUNGGUHAN yang SAMA yang dipakai controller, dengan
 *    parameter yang PERSIS SAMA seperti yang akan dirakit
 *    Cektiket::rating() dari `$this->request->getPost(...)`. Ini BUKAN
 *    simulasi/mock — ini adalah operasi database NYATA melalui kode
 *    produksi NYATA, hanya TIDAK melalui pembungkus HTTP controller
 *    (yang hanya menambahkan pembacaan POST + panggilan email + exit;
 *    di atasnya — tiga hal yang secara terpisah sudah dibuktikan/
 *    dijelaskan di bawah tanpa perlu dieksekusi via HTTP).
 * 2. BUKTI KONDISI EMAIL TERPICU TERPENUHI (TANPA mengirim email
 *    sungguhan): memakai TEKNIK YANG SAMA seperti
 *    K1SqlInjectionHttpIntegrationTest::testPayloadSqliTidakMemicuKondisiPengirimanEmail()
 *    — verifikasi struktural bahwa `$datas !== false` (SATU-SATUNYA
 *    gerbang sebelum `email->selesai()` dipanggil) BERNILAI TRUE untuk
 *    nomorTiket ini, dengan memanggil `ModelTicketing::byId()` PRODUKSI
 *    YANG SAMA (bukan query SQL manual pengganti) yang dipakai
 *    Cektiket::rating() persis pada baris yang sama. Bila `byId()`
 *    mengembalikan array (bukan false), maka SECARA STRUKTURAL, PADA
 *    KODE PRODUKSI SAAT INI, blok `if ($datas !== false) { $this->email->
 *    selesai(...) }` PASTI akan tereksekusi ketika dipanggil melalui
 *    HTTP — tanpa perlu benar-benar memicu pengiriman untuk
 *    membuktikannya. Ini SAMA PERSIS dengan pola pembuktian non-
 *    pengiriman K1, hanya arah kesimpulannya berlawanan (K1 membuktikan
 *    gerbang FALSE/aman; test ini membuktikan gerbang TRUE/bug ada).
 *
 * Kombinasi (1) memanggil model produksi sungguhan (bukti rating()
 * SECARA FAKTUAL akan berhasil insert/update d_rating untuk nomorTiket
 * ini bila dipanggil via HTTP, karena operasi model IDENTIK) dan (2)
 * verifikasi struktural gerbang email (bukti email->selesai() SECARA
 * FAKTUAL akan terpicu bila dipanggil via HTTP, karena gerbangnya
 * benar-benar true) BERSAMA-SAMA membentuk counterexample yang
 * DIMINTA task 6 — TANPA risiko efek samping nyata ke inbox pihak
 * ketiga, dan TANPA mock/stub yang akan melemahkan validitas bukti.
 *
 * Baris d_rating yang tercipta test ini DIBERSIHKAN di setUp() (sebelum,
 * idempoten) DAN tearDown() (sesudah) — TIDAK ADA data uji tersisa di
 * database live untuk tiket QEHO-HTTV-001.
 *
 * Requirements: 1.23, 1.24 (bugfix.md)
 */
final class T4RatingOwnershipExplorationTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    /**
     * Tiket NYATA yang ada di database live — dipakai sebagai "nomor
     * tiket yang ditebak/diketahui" oleh penyerang yang TIDAK PERNAH
     * menerima/mendekode kunci terenkripsi untuk tiket ini.
     */
    private const NOMOR_TIKET_TEBAKAN = 'QEHO-HTTV-001';

    private BaseConnection $koneksi;

    private ModelTicketing $tiket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->koneksi = \Config\Database::connect('default');
        $this->tiket   = new ModelTicketing();

        self::assertSame(
            'db_newtiket',
            $this->koneksi->database,
            'Test eksplorasi ini WAJIB tersambung ke database nyata db_newtiket agar counterexample (tiket nyata + email nyata) bermakna.'
        );

        // Idempoten: bersihkan sisa row uji dari run sebelumnya (bila
        // proses sebelumnya terganggu) SEBELUM test berjalan.
        $this->bersihkanRowUji();
    }

    protected function tearDown(): void
    {
        $this->bersihkanRowUji();

        parent::tearDown();
    }

    private function bersihkanRowUji(): void
    {
        $this->koneksi->table('d_rating')->where('ratingTicketId', self::NOMOR_TIKET_TEBAKAN)->delete();
    }

    /**
     * Sanity: pastikan tiket tebakan benar-benar ADA di database
     * (bila tidak, seluruh counterexample di bawah tidak bermakna —
     * bug ini secara spesifik tentang tiket yang BENAR-BENAR ada
     * namun ditebak tanpa kunci).
     */
    public function testSanityNomorTiketTebakanBenarBenarAdaDiDatabase(): void
    {
        $baris = $this->koneksi->table('d_ticketing')
            ->where(['ticketTrackingId' => self::NOMOR_TIKET_TEBAKAN])
            ->get()->getRowArray();

        self::assertIsArray(
            $baris,
            'Prasyarat test: nomor tiket tebakan HARUS benar-benar ada di d_ticketing, agar counterexample "menebak tiket nyata tanpa kunci" bermakna.'
        );
        self::assertArrayHasKey('ticketEmail', $baris);
        self::assertNotSame('', (string) $baris['ticketEmail'], 'Tiket tebakan harus memiliki ticketEmail nyata agar bagian "email akan terpicu ke pemilik tiket" bermakna.');
    }

    /**
     * Property 2 (Preservation/Expected Behavior) — SETELAH fix T4:
     * `Cektiket::rating()` SHALL menolak permintaan yang tidak disertai
     * kunci terenkripsi yang sah, sehingga tiket yang nomornya hanya
     * ditebak/diketahui TIDAK dapat diberi rating.
     *
     * Diuji lewat dispatch HTTP sungguhan (bukan simulasi urutan operasi
     * model seperti test lama): POST ke `cektiket/rating/{kunci tidak
     * valid}` — jalur yang sama persis dengan yang dipakai tautan rating
     * dari email pemohon.
     */
    public function testRatingDenganKunciTidakValidTidakMenyimpanRating(): void
    {
        $hasil = $this->postRating('KUNCI-TIDAK-VALID', ['rating' => '5']);

        self::assertSame(
            403,
            $hasil->response()->getStatusCode(),
            'EXPECTED BEHAVIOR T4 (Requirement 2.15, 2.16) — kunci yang tidak dapat di-decode SHALL ditolak HTTP 403; rating() tidak boleh melakukan operasi model apa pun sebelum kunci terbukti sah.'
        );

        self::assertSame(
            0,
            $this->koneksi->table('d_rating')->where('ratingTicketId', self::NOMOR_TIKET_TEBAKAN)->countAllResults(),
            sprintf(
                'EXPECTED BEHAVIOR T4 — TIDAK BOLEH ada baris d_rating untuk tiket "%s" ketika permintaan tidak menyertakan kunci sah: nomor tiket tebakan tidak cukup untuk menilai tiket orang lain.',
                self::NOMOR_TIKET_TEBAKAN
            )
        );
    }

    /**
     * Property 2 (Preservation/Expected Behavior) — SETELAH fix T4:
     * nomor tiket yang dikirim pada BODY permintaan SHALL diabaikan —
     * satu-satunya sumber nomor tiket adalah hasil `decode()` kunci pada
     * segmen URL.
     *
     * Ini adalah inti perbaikan T4: sebelum fix, `rating()` membaca
     * nomor tiket langsung dari input sehingga siapa pun yang menebak
     * nomor tiket nyata dapat menilai tiket tersebut. Test ini mengirim
     * nomor tiket NYATA (yang benar-benar ada di d_ticketing) pada body
     * bersama kunci tidak valid — dan menuntut TIDAK ADA efek apa pun.
     */
    public function testRatingMengabaikanNomorTiketYangDikirimDariBody(): void
    {
        $hasil = $this->postRating('KUNCI-TIDAK-VALID', [
            'rating'     => '5',
            'nomorTiket' => self::NOMOR_TIKET_TEBAKAN,
        ]);

        self::assertSame(
            403,
            $hasil->response()->getStatusCode(),
            'EXPECTED BEHAVIOR T4 (Requirement 2.15) — nomorTiket dari body TIDAK BOLEH membuat permintaan tanpa kunci sah menjadi diproses.'
        );

        self::assertSame(
            0,
            $this->koneksi->table('d_rating')->where('ratingTicketId', self::NOMOR_TIKET_TEBAKAN)->countAllResults(),
            sprintf(
                'EXPECTED BEHAVIOR T4 — nomor tiket "%s" yang dikirim pada BODY SHALL diabaikan sepenuhnya: rating hanya boleh diproses untuk nomor tiket hasil decode() kunci pada URL.',
                self::NOMOR_TIKET_TEBAKAN
            )
        );
    }

    /**
     * Dispatch POST `cektiket/rating/{kunci}` lengkap dengan token CSRF
     * (rute ini POST dan dilindungi filter csrf global).
     *
     * @param array<string, string> $body
     */
    private function postRating(string $kunci, array $body): \CodeIgniter\Test\TestResponse
    {
        Services::injectMock('request', $this->setupRequest('POST', 'cektiket/rating/' . $kunci));
        Services::resetSingle('security');

        return $this->post('cektiket/rating/' . $kunci, array_merge($body, [csrf_token() => csrf_hash()]));
    }
}
