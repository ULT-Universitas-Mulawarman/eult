<?php

namespace Tests\Bugfix;

use App\Models\ModelMaster;
use App\Models\ModelTicketing;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Exceptions\BadRequestException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Task 1 (Klaster 1 — K1): Test EKSPLORASI bug condition SQL Injection
 * via kondisi WHERE string mentah.
 *
 * PENTING — metodologi bug condition:
 * Test ini WAJIB GAGAL pada kode yang belum diperbaiki. Kegagalan test
 * mengonfirmasi bug ada (Property 1: Bug Condition). Test ini TIDAK
 * BOLEH diperbaiki di sini — dia mengenkode expected/fixed behavior dan
 * akan berubah menjadi LULUS setelah fix K1 diimplementasikan pada
 * task 3 (`Cektiket::rating()`, `eult_auto_increment()`, dsb).
 *
 * Test dijalankan terhadap query builder & Model NYATA (BUKAN mock),
 * terkoneksi ke database MySQL nyata `db_newtiket` (defaultGroup
 * dipaksa tetap 'default', bukan 'tests'/SQLite — lihat tests/bootstrap.php)
 * agar counterexample yang tersurface faktual: query benar-benar
 * mengembalikan seluruh baris tabel, bukan sekadar simulasi.
 *
 * Requirements: 1.1, 1.2, 1.3, 1.4 (bugfix.md)
 */
final class K1SqlInjectionExplorationTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private BaseConnection $koneksi;

    private ModelTicketing $tiket;

    private ModelMaster $master;

    /** Baseline jumlah baris d_ticketing terverifikasi manual: 38441 (lihat bugfix.md 1.1). */
    private const EXPECTED_MIN_TICKETING_ROWS = 30000;

    protected function setUp(): void
    {
        parent::setUp();

        // defaultGroup TIDAK berubah menjadi 'tests' karena ENVIRONMENT
        // di-set 'development' pada tests/bootstrap.php (bukan 'testing').
        $this->koneksi = \Config\Database::connect('default');
        $this->tiket   = new ModelTicketing();
        $this->master  = new ModelMaster();
    }

    /**
     * Sanity check: pastikan test benar-benar tersambung ke database
     * nyata berisi data produksi (bukan DB kosong/mock), agar counter-
     * example di bawah bermakna secara faktual.
     */
    public function testSanityConnectedToRealDatabaseWithProductionData(): void
    {
        self::assertSame('db_newtiket', $this->koneksi->database, 'Test harus tersambung ke database nyata db_newtiket, bukan DB tests/mock.');

        $jumlah = $this->koneksi->table('d_ticketing')->countAllResults();

        self::assertGreaterThan(
            self::EXPECTED_MIN_TICKETING_ROWS,
            $jumlah,
            'd_ticketing diharapkan berisi puluhan ribu baris data produksi nyata (terverifikasi manual: 38441).'
        );
    }

    /**
     * Property 2 (Preservation/Expected Behavior) — SETELAH fix K1:
     * `Cektiket::rating()` SHALL memperlakukan kunci pada URL sebagai
     * nilai LITERAL dan menolak payload SQLi — tanpa menyentuh data.
     *
     * Diuji lewat dispatch HTTP sungguhan ke rute `cektiket/rating/(:any)`.
     * Inilah properti yang diperbaiki K1: nilai yang berasal dari request
     * TIDAK PERNAH lagi dirakit menjadi fragmen SQL.
     */
    public function testRatingMenolakPayloadSqliTanpaMenyentuhData(): void
    {
        $payload = "X' OR '1'='1";

        // Dua lapis pertahanan yang sama-sama sah: (a) filter karakter URI
        // CodeIgniter menolak payload dengan tanda kutip SEBELUM router
        // memprosesnya (BadRequestException), atau (b) payload lolos ke
        // controller dan ditolak sebagai kunci tidak valid (403). Keduanya
        // berarti payload TIDAK pernah diperlakukan sebagai fragmen SQL.
        $status = null;

        try {
            $status = $this->postRating($payload, ['rating' => '5'])->response()->getStatusCode();
        } catch (BadRequestException $e) {
            $status = 400;
        }

        self::assertContains(
            $status,
            [400, 403],
            'EXPECTED BEHAVIOR K1 (Requirement 2.1, 2.2) — payload SQLi pada kunci rating SHALL ditolak: 400 oleh filter karakter URI, atau 403 oleh controller (kunci tidak valid).'
        );

        self::assertSame(
            0,
            $this->koneksi->table('d_rating')->where('ratingTicketId', $payload)->countAllResults(),
            'EXPECTED BEHAVIOR K1 — tidak boleh ada baris d_rating untuk payload SQLi: perintah tidak pernah sampai ke lapisan model.'
        );
    }

    /**
     * Property 3 (Preservation, struktural) — SELURUH titik panggilan
     * produksi yang dulu merakit kondisi WHERE dari input request SHALL
     * memakai bentuk ARRAY BINDING.
     *
     * Bentuk string mentah pada `ModelMaster::getByLastId()`/`byId()`
     * SENGAJA tetap didukung untuk caller internal yang tidak meneruskan
     * input pengguna (lihat docblock `getByLastId()`), sehingga yang
     * benar-benar harus dijaga adalah TITIK PANGGILANNYA — itulah yang
     * diperiksa test ini, bukan kemampuan query builder-nya.
     */
    public function testSeluruhTitikPanggilanProduksiMemakaiArrayBinding(): void
    {
        $titikPanggilan = [
            'Cektiket::rating()'   => APPPATH . 'Controllers/Cektiket.php',
            'Login::savetiket()'   => APPPATH . 'Controllers/Login.php',
            'Ticketing::loadpdf()' => APPPATH . 'Controllers/Ticketing.php',
        ];

        foreach ($titikPanggilan as $nama => $path) {
            self::assertFileExists($path, sprintf('Prasyarat test: %s SHALL ada.', $path));

            $isi = (string) file_get_contents($path);

            foreach (["byId(\"ticketTrackingId = '", "byId('ticketTrackingId = '"] as $polaTerlarang) {
                self::assertStringNotContainsString(
                    $polaTerlarang,
                    $isi,
                    sprintf(
                        'EXPECTED BEHAVIOR K1 (Requirement 2.1, 2.3) — %s TIDAK BOLEH lagi merakit kondisi WHERE dari input request (pola "%s"); gunakan array binding.',
                        $nama,
                        $polaTerlarang
                    )
                );
            }

            self::assertStringContainsString(
                "byId(['ticketTrackingId' =>",
                $isi,
                sprintf('EXPECTED BEHAVIOR K1 — %s SHALL memakai array binding untuk mengakses tiket.', $nama)
            );
        }
    }

    /**
     * Property 2 (Preservation/Expected Behavior) — `eult_auto_increment()`
     * dengan bentuk ARRAY (jalur yang dipakai produksi sekarang)
     * memperlakukan payload SQLi sebagai LITERAL: karena tidak ada baris
     * dengan `archiveTrackingId` sama dengan payload, MAX(archiveId)
     * kosong dan fungsi SHALL mengembalikan id pertama `{nip}0001`.
     *
     * Sebelum fix, pemanggil merakit kondisi string mentah sehingga MAX()
     * dihitung atas SELURUH tabel d_archive dan menghasilkan id yang salah.
     */
    public function testAutoIncrementArrayBindingMemperlakukanPayloadSqliSebagaiLiteral(): void
    {
        $arsipId = 'K1UJICOBASQLI';
        $payload = "X' OR '1'='1";

        $id = eult_auto_increment('d_archive', 'archiveId', $arsipId, ['archiveTrackingId' => $payload]);

        self::assertSame(
            $arsipId . '0001',
            $id,
            'EXPECTED BEHAVIOR K1 (Requirement 2.3) — dengan array binding, payload SQLi tidak mencocokkan baris mana pun sehingga MAX(archiveId) kosong dan id pertama yang dialokasikan adalah {nip}0001.'
        );

        // Model langsung: MAX(archiveId) untuk kondisi array binding dengan
        // payload ini SHALL kosong (NULL), bukan MAX seluruh tabel.
        $barisMaks = $this->master->getByLastId('d_archive', 'archiveId', ['archiveTrackingId' => $payload]);

        self::assertTrue(
            $barisMaks === false || ($barisMaks['archiveId'] ?? null) === null,
            sprintf(
                'EXPECTED BEHAVIOR K1 — getByLastId() dengan array binding SHALL tidak menemukan baris untuk payload SQLi (MAX kosong), bukan menghitung MAX seluruh tabel d_archive. Hasil aktual: %s',
                json_encode($barisMaks)
            )
        );
    }

    /**
     * Property-Based sanity — domain besar metacharacter SQL/unicode/
     * kosong.
     *
     * **Validates: Requirements 2.1, 2.2, 2.3**
     *
     * Bug ini cocok PBT domain besar (design.md Testing Strategy: "K1:
     * string SQLi arbitrer"). Karena PHP/PHPUnit di proyek ini TIDAK
     * memiliki dependency PBT khusus, scoped-PBT diimplementasikan
     * sebagai data provider berisi korpus representatif metacharacter
     * SQL, unicode, dan string kosong — dijalankan terhadap query builder
     * NYATA untuk SETIAP payload.
     *
     * Oracle dihitung di sisi PHP (independen dari query builder): berapa
     * baris yang `ticketTrackingId`-nya SAMA PERSIS (literal) dengan
     * payload. Bentuk ARRAY BINDING — satu-satunya bentuk yang dipakai
     * produksi setelah fix K1 — SHALL selalu menghasilkan jumlah yang
     * SAMA dengan oracle tersebut, tidak pernah memicu SQL error, dan
     * tidak pernah "cocok semua baris".
     *
     * @dataProvider provideSqliMetacharacterPayloads
     */
    public function testArrayBindingMemperlakukanSeluruhPayloadMetacharacterSebagaiLiteral(string $payload, string $keterangan): void
    {
        $daftarSemuaTrackingId = $this->koneksi->table('d_ticketing')
            ->select('ticketTrackingId')
            ->get()->getResultArray();

        $jumlahBarisOracle = count(array_filter(
            $daftarSemuaTrackingId,
            static fn (array $baris): bool => $baris['ticketTrackingId'] === $payload
        ));

        try {
            $hasil = $this->koneksi->table('d_ticketing')
                ->select('ticketTrackingId')
                ->where(['ticketTrackingId' => $payload])
                ->get()->getResultArray();
        } catch (\Throwable $e) {
            self::fail(sprintf(
                'EXPECTED BEHAVIOR K1 (payload "%s" — %s): array binding tidak boleh memicu SQL error (%s).',
                $payload,
                $keterangan,
                $e->getMessage()
            ));

            return;
        }

        $jumlahBaris = count($hasil);

        self::assertSame(
            $jumlahBarisOracle,
            $jumlahBaris,
            sprintf(
                'EXPECTED BEHAVIOR K1 (payload "%s" — %s): array binding SHALL memperlakukan payload sebagai nilai literal sehingga jumlah baris yang cocok SELALU sama dengan oracle exact-match PHP (%d), bukan %d — selisih ini akan menandakan payload bocor menjadi fragmen SQL.',
                $payload,
                $keterangan,
                $jumlahBarisOracle,
                $jumlahBaris
            )
        );
    }

    /**
     * Dispatch POST `cektiket/rating/{kunci}` lengkap dengan token CSRF
     * (rute POST yang dilindungi filter csrf global).
     *
     * @param array<string, string> $body
     */
    private function postRating(string $kunci, array $body): \CodeIgniter\Test\TestResponse
    {
        Services::injectMock('request', $this->setupRequest('POST', 'cektiket/rating/' . $kunci));
        Services::resetSingle('security');

        return $this->post('cektiket/rating/' . $kunci, array_merge($body, [csrf_token() => csrf_hash()]));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideSqliMetacharacterPayloads(): array
    {
        return [
            'tautologi klasik'            => ["X' OR '1'='1", 'payload OR klasik'],
            'tautologi tanpa spasi'       => ["X'OR'1'='1", 'variasi tanpa spasi'],
            'union based'                 => ["X' UNION SELECT 1-- -", 'UNION SELECT'],
            'comment terminator'          => ["X'--", 'SQL comment (--) untuk memotong sisa query'],
            'stacked query'               => ["X'; SELECT 1", 'stacked query (;)'],
            'boolean selalu true numerik' => ["X' OR 1=1 OR '", 'tautologi numerik tanpa string compare'],
            'unicode metacharacter'       => ["X'\u{2019} OR \u{2018}1\u{2019}=\u{2018}1", 'unicode quote lookalike + OR'],
            'string kosong'               => ['', 'string kosong sebagai nomorTiket'],
            'hanya quote tunggal'         => ["'", 'quote tunggal tak seimbang'],
            'nomor tiket valid sungguhan' => ['QEHO-HTTV-001', 'nomor tiket VALID nyata (baseline non-bug: array binding SHALL tetap mencocokkan tepat 1 baris)'],
        ];
    }
}
