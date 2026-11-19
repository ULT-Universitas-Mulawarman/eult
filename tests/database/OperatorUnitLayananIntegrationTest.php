<?php

use App\Libraries\Enkripsi;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Uji integrasi HTTP pembatasan OPERATOR_* berbasis unit layanan
 * (s_user_group.sgroupCategoryId -> db_ult.ref_unit.unitId).
 *
 * Skenario yang diuji terhadap db_newtiket & db_ult:
 *  a. POST ticketing/response sebagai OPERATOR_KEUANGAN (unit 21) dengan hanya
 *     status_layanan = 1 (layanan kosong) HANYA mengembalikan tiket Keuangan
 *     (ZZOPU-KEU-001) dan TIDAK mengembalikan tiket Akademik (ZZOPU-AKA-002).
 *  b. POST ticketing/response sebagai OPERATOR_KEUANGAN dengan parameter layanan = 22
 *     (dimanipulasi ke Bagian Akademik) tetap HANYA mengembalikan ZZOPU-KEU-001.
 *  c. POST ticketing/response sebagai OPERATOR_CALLCENTER (kategori 99) dengan
 *     keyword = ZZOPU- mengembalikan KEDUA tiket (ZZOPU-KEU-001 & ZZOPU-AKA-002).
 *  d. GET ticketing/detail/<kunciAka> dan POST ticketing/tolak/<kunciAka> sebagai
 *     OPERATOR_KEUANGAN ditolak dengan HTTP 403, sedangkan <kunciKeu> menghasilkan HTTP 200.
 *  e. GET home sebagai OPERATOR_KEUANGAN TIDAK membocorkan tiket urgent ZZOPU-AKA-002
 *     dari Bagian Akademik.
 *  f. GET ticketing sebagai OPERATOR_KEUANGAN hanya merender Bagian Keuangan (terpilih)
 *     pada dropdown #filter_layanan tanpa Bagian Akademik, sedangkan OPERATOR_CALLCENTER
 *     merender kedua unit layanan.
 *  g. GET ticketing/assign/<kunciAka>, POST ticketing/delete/<kunciAka>, dan
 *     GET ticketing/export/<kunciAka> sebagai OPERATOR_KEUANGAN ditolak HTTP 403.
 *  h. POST laporanlayanan/response sebagai OPERATOR_KEUANGAN dengan manipulasi layanan = 22
 *     tetap memaksa unitId = 21 (Bagian Keuangan) dan tidak membocorkan baris Bagian Akademik.
 *
 * @internal
 */
final class OperatorUnitLayananIntegrationTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const TIKET_KEU = 'ZZOPU-KEU-001';
    private const TIKET_AKA = 'ZZOPU-AKA-002';

    private BaseConnection $koneksi;
    private BaseConnection $koneksiUlt;
    private Enkripsi $enkripsi;

    private string $layananKeuanganId;
    private string $layananAkademikId;
    private string $kunciKeu;
    private string $kunciAka;
    private string $tanggalUji;

    private mixed $koneksiTestsAsli = null;
    private bool $adaKoneksiTestsAsli = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Cegah kehabisan memori apabila pada fase RED query belum terfilter unit
        ini_set('memory_limit', '512M');

        $this->koneksi    = \Config\Database::connect('default');
        $this->koneksiUlt = \Config\Database::connect('dbult');
        $this->enkripsi   = new Enkripsi();

        // Pastikan panggilan \Config\Database::connect() tanpa argumen (seperti di Home::index)
        // juga mengarah ke koneksi nyata db_newtiket pada suite integrasi Database ini.
        $ref = new \ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
        $ref->setAccessible(true);
        $instances = $ref->getValue();
        $this->adaKoneksiTestsAsli = array_key_exists('tests', $instances);
        $this->koneksiTestsAsli    = $instances['tests'] ?? null;
        $instances['tests']        = $this->koneksi;
        $ref->setValue(null, $instances);

        self::assertSame('db_newtiket', $this->koneksi->database, 'Test ini WAJIB memakai database nyata db_newtiket.');

        $layananKeu = $this->koneksiUlt->table('ref_layanan')
            ->select('layananId')
            ->where('layananunitId', 21)
            ->orderBy('layananId', 'ASC')
            ->limit(1)
            ->get()
            ->getRowArray();
        self::assertIsArray($layananKeu, 'Dibutuhkan minimal satu layanan Bagian Keuangan (layananunitId = 21).');
        $this->layananKeuanganId = (string) $layananKeu['layananId'];

        $layananAka = $this->koneksiUlt->table('ref_layanan')
            ->select('layananId')
            ->where('layananunitId', 22)
            ->orderBy('layananId', 'ASC')
            ->limit(1)
            ->get()
            ->getRowArray();
        self::assertIsArray($layananAka, 'Dibutuhkan minimal satu layanan Bagian Akademik (layananunitId = 22).');
        $this->layananAkademikId = (string) $layananAka['layananId'];

        $this->kunciKeu   = (string) $this->enkripsi->encode(self::TIKET_KEU);
        $this->kunciAka   = (string) $this->enkripsi->encode(self::TIKET_AKA);
        $this->tanggalUji = date('Y-m-d H:i:s', time() + 60);

        $this->bersihkanData();

        $this->koneksi->table('d_ticketing')->insert([
            'ticketTrackingId' => self::TIKET_KEU,
            'ticketName'       => 'Pemohon Uji Keuangan',
            'ticketEmail'      => 'keu.opu@example.com',
            'ticketCategories' => $this->layananKeuanganId,
            'ticketCreated'    => $this->tanggalUji,
            'ticketStatus'     => '1',
        ]);

        $this->koneksi->table('d_ticketing')->insert([
            'ticketTrackingId' => self::TIKET_AKA,
            'ticketName'       => 'Pemohon Uji Akademik',
            'ticketEmail'      => 'aka.opu@example.com',
            'ticketCategories' => $this->layananAkademikId,
            'ticketCreated'    => $this->tanggalUji,
            'ticketStatus'     => '1',
        ]);
    }

    protected function tearDown(): void
    {
        $this->bersihkanData();

        $ref = new \ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
        $ref->setAccessible(true);
        $instances = $ref->getValue();
        if ($this->adaKoneksiTestsAsli) {
            $instances['tests'] = $this->koneksiTestsAsli;
        } else {
            unset($instances['tests']);
        }
        $ref->setValue(null, $instances);

        parent::tearDown();
    }

    private function bersihkanData(): void
    {
        $this->koneksi->table('d_history')->whereIn('ticketTrackingIdHistory', [self::TIKET_KEU, self::TIKET_AKA])->delete();
        $this->koneksi->table('d_replies')->whereIn('repliesTicketId', [self::TIKET_KEU, self::TIKET_AKA])->delete();
        $this->koneksi->table('d_disposisi')->whereIn('disposisiTicketId', [self::TIKET_KEU, self::TIKET_AKA])->delete();
        $this->koneksi->table('d_ticketing')->whereIn('ticketTrackingId', [self::TIKET_KEU, self::TIKET_AKA])->delete();
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function sesiOperator(string $grup, string $kategoriId = ''): array
    {
        return ['logged_in' => [
            'susrNama'           => 'op_uji_' . strtolower($grup),
            'susrSgroupNama'     => $grup,
            'susrSgroupNama_ori' => $grup,
            'susrProfil'         => 'Operator Uji ' . $grup,
            'susrCategoryId'     => $kategoriId,
        ]];
    }

    public function testResponseOperatorKeuanganDenganStatusSajaHanyaMenampilkanTiketUnitKeuangan(): void
    {
        $hasil = $this->withSession($this->sesiOperator('OPERATOR_KEUANGAN', '21'))
            ->post('ticketing/response', [
                'rentangTanggal' => '',
                'layanan'        => '',
                'status_layanan' => '1',
                'keyword'        => '',
                csrf_token()     => csrf_hash(),
            ]);

        $hasil->assertStatus(200);
        $body = $hasil->getBody();

        $this->assertTrue(
            str_contains($body, self::TIKET_KEU),
            'Respons OPERATOR_KEUANGAN dengan status_layanan=1 wajib memuat tiket Keuangan (' . self::TIKET_KEU . ').'
        );
        $this->assertFalse(
            str_contains($body, self::TIKET_AKA),
            'Respons OPERATOR_KEUANGAN dengan status_layanan=1 tidak boleh memuat tiket Akademik (' . self::TIKET_AKA . ').'
        );
    }

    public function testResponseOperatorKeuanganMengabaikanManipulasiLayananUnitLain(): void
    {
        $hasil = $this->withSession($this->sesiOperator('OPERATOR_KEUANGAN', '21'))
            ->post('ticketing/response', [
                'rentangTanggal' => '',
                'layanan'        => '22',
                'status_layanan' => '1',
                'keyword'        => 'ZZOPU-',
                csrf_token()     => csrf_hash(),
            ]);

        $hasil->assertStatus(200);
        $body = $hasil->getBody();

        $this->assertTrue(
            str_contains($body, self::TIKET_KEU),
            'Respons OPERATOR_KEUANGAN wajib tetap memuat tiket Keuangan meski POST layanan dimanipulasi ke 22.'
        );
        $this->assertFalse(
            str_contains($body, self::TIKET_AKA),
            'Respons OPERATOR_KEUANGAN tidak boleh memuat tiket Akademik meski POST layanan dimanipulasi ke 22.'
        );
    }

    public function testResponseOperatorCallCenterMelihatTiketSemuaUnit(): void
    {
        $hasil = $this->withSession($this->sesiOperator('OPERATOR_CALLCENTER', '99'))
            ->post('ticketing/response', [
                'rentangTanggal' => '',
                'layanan'        => '',
                'status_layanan' => '',
                'keyword'        => 'ZZOPU-',
                csrf_token()     => csrf_hash(),
            ]);

        $hasil->assertStatus(200);
        $body = $hasil->getBody();

        $this->assertTrue(
            str_contains($body, self::TIKET_KEU),
            'Respons OPERATOR_CALLCENTER wajib memuat tiket Keuangan (' . self::TIKET_KEU . ').'
        );
        $this->assertTrue(
            str_contains($body, self::TIKET_AKA),
            'Respons OPERATOR_CALLCENTER wajib memuat tiket Akademik (' . self::TIKET_AKA . ').'
        );
    }

    public function testDetailDanTolakSebagaiOperatorKeuanganDitolak403UntukTiketAkademik(): void
    {
        $sesiKeu = $this->sesiOperator('OPERATOR_KEUANGAN', '21');

        // Tiket unit sendiri (Keuangan = 21) -> HTTP 200
        $detailKeu = $this->withSession($sesiKeu)->get('ticketing/detail/' . $this->kunciKeu);
        $detailKeu->assertStatus(200);
        $this->assertTrue(str_contains($detailKeu->getBody(), self::TIKET_KEU));

        // Tiket unit lain (Akademik = 22) -> HTTP 403 pada detail
        $detailAka = $this->withSession($sesiKeu)->get('ticketing/detail/' . $this->kunciAka);
        $detailAka->assertStatus(403);

        // Tiket unit lain (Akademik = 22) -> HTTP 403 pada aksi tolak
        $tolakAka = $this->withSession($sesiKeu)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post('ticketing/tolak/' . $this->kunciAka, [
                'pesan_tolak' => 'Coba tolak lintas unit',
                csrf_token()  => csrf_hash(),
            ]);
        $tolakAka->assertStatus(403);

        $barisAka = $this->koneksi->table('d_ticketing')
            ->select('ticketStatus')
            ->where('ticketTrackingId', self::TIKET_AKA)
            ->get()
            ->getRowArray();
        $this->assertSame('1', (string) ($barisAka['ticketStatus'] ?? ''), 'Status tiket Akademik tidak boleh berubah oleh OPERATOR_KEUANGAN.');
    }

    public function testHomeSebagaiOperatorKeuanganTidakMembocorkanTiketUrgentAkademik(): void
    {
        $hasil = $this->withSession($this->sesiOperator('OPERATOR_KEUANGAN', '21'))->get('home');

        $hasil->assertStatus(200);
        $body = $hasil->getBody();

        $this->assertTrue(
            str_contains($body, self::TIKET_KEU),
            'Dashboard home OPERATOR_KEUANGAN wajib menampilkan tiket urgent Keuangan (' . self::TIKET_KEU . ').'
        );
        $this->assertFalse(
            str_contains($body, self::TIKET_AKA),
            'Dashboard home OPERATOR_KEUANGAN tidak boleh membocorkan tiket urgent Akademik (' . self::TIKET_AKA . ').'
        );
    }

    public function testIndexTicketingMembatasiDropdownFilterLayananUntukOperatorKeuangan(): void
    {
        $keu = $this->withSession($this->sesiOperator('OPERATOR_KEUANGAN', '21'))->get('ticketing');
        $keu->assertStatus(200);
        $bodyKeu = $keu->getBody();

        $this->assertSame(1, preg_match('/<select[^>]*id="filter_layanan"[^>]*>(.*?)<\/select>/s', $bodyKeu, $cocokKeu));
        $selectKeu = $cocokKeu[1] ?? '';

        $this->assertTrue(
            str_contains($selectKeu, 'Bagian Keuangan') && str_contains($selectKeu, 'selected'),
            'Dropdown #filter_layanan untuk OPERATOR_KEUANGAN wajib memuat Bagian Keuangan dalam keadaan selected.'
        );
        $this->assertFalse(
            str_contains($selectKeu, 'Bagian Akademik'),
            'Dropdown #filter_layanan untuk OPERATOR_KEUANGAN tidak boleh memuat Bagian Akademik.'
        );

        $cc = $this->withSession($this->sesiOperator('OPERATOR_CALLCENTER', '99'))->get('ticketing');
        $cc->assertStatus(200);
        $bodyCc = $cc->getBody();

        $this->assertSame(1, preg_match('/<select[^>]*id="filter_layanan"[^>]*>(.*?)<\/select>/s', $bodyCc, $cocokCc));
        $selectCc = $cocokCc[1] ?? '';

        $this->assertTrue(
            str_contains($selectCc, 'Bagian Keuangan') && str_contains($selectCc, 'Bagian Akademik'),
            'Dropdown #filter_layanan untuk OPERATOR_CALLCENTER wajib memuat Bagian Keuangan dan Bagian Akademik.'
        );
    }

    public function testAssignDeleteDanExportSebagaiOperatorKeuanganDitolak403UntukTiketAkademik(): void
    {
        $sesiKeu = $this->sesiOperator('OPERATOR_KEUANGAN', '21');

        // 1. GET ticketing/assign/<kunciAka> -> HTTP 403
        $assignAka = $this->withSession($sesiKeu)->get('ticketing/assign/' . $this->kunciAka);
        $assignAka->assertStatus(403);

        // 2. POST ticketing/delete/<kunciAka> -> HTTP 403 & baris tiket tetap utuh
        $deleteAka = $this->withSession($sesiKeu)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post('ticketing/delete/' . $this->kunciAka, [
                csrf_token() => csrf_hash(),
            ]);
        $deleteAka->assertStatus(403);

        $masihAda = $this->koneksi->table('d_ticketing')
            ->where('ticketTrackingId', self::TIKET_AKA)
            ->countAllResults();
        $this->assertSame(1, $masihAda, 'Tiket Akademik tidak boleh terhapus oleh OPERATOR_KEUANGAN.');

        // 3. GET ticketing/export/<kunciAka> -> HTTP 403, sedangkan <kunciKeu> -> HTTP 200
        $exportAka = $this->withSession($sesiKeu)->get('ticketing/export/' . $this->kunciAka);
        $exportAka->assertStatus(403);

        $exportKeu = $this->withSession($sesiKeu)->get('ticketing/export/' . $this->kunciKeu);
        $exportKeu->assertStatus(200);
        $this->assertTrue(
            str_contains($exportKeu->getBody(), self::TIKET_KEU),
            'Ekspor CSV tiket Keuangan oleh OPERATOR_KEUANGAN wajib memuat nomor tiket ' . self::TIKET_KEU . '.'
        );
    }

    public function testLaporanLayananResponseOperatorKeuanganMengabaikanManipulasiUnitLain(): void
    {
        $tgl   = date('Y-m-d', strtotime($this->tanggalUji));
        $hasil = $this->withSession($this->sesiOperator('OPERATOR_KEUANGAN', '21'))
            ->post('laporanlayanan/response', [
                'rentangTanggal' => $tgl . ' / ' . $tgl,
                'layanan'        => '22',
                csrf_token()     => csrf_hash(),
            ]);

        $hasil->assertStatus(200);
        $body = $hasil->getBody();

        $this->assertTrue(
            str_contains($body, 'Bagian Keuangan'),
            'Laporan layanan OPERATOR_KEUANGAN wajib tetap menampilkan rekap Bagian Keuangan meski POST layanan dimanipulasi ke 22.'
        );
        $this->assertFalse(
            str_contains($body, 'Bagian Akademik'),
            'Laporan layanan OPERATOR_KEUANGAN tidak boleh membocorkan baris Bagian Akademik meski POST layanan dimanipulasi ke 22.'
        );
    }
}
