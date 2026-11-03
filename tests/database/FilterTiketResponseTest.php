<?php

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Perilaku filter daftar tiket "wajib minimal satu" (butuh MySQL: suite Database).
 *
 * Kontrak POST ticketing/response sebagai ADMIN:
 *  - semua filter kosong => ditolak dengan pesan JSON error;
 *  - kata kunci saja cukup untuk menyaring nomor tracking / nama pemohon;
 *  - status saja cukup;
 *  - rentang tanggal saja tetap cukup (perilaku lama dipertahankan).
 *
 * @internal
 */
final class FilterTiketResponseTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const TIKET_ALFA = 'ZZFIL-0000-001';
    private const TIKET_BETA = 'ZZFIL-0000-002';

    private BaseConnection $koneksi;

    private string $statusAlfa;
    private string $statusBeta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->koneksi = \Config\Database::connect('default');
        self::assertSame('db_newtiket', $this->koneksi->database, 'Test ini WAJIB memakai database nyata db_newtiket.');

        $status = $this->koneksi->table('r_status')->select('statusId')->orderBy('statusId')->limit(2)->get()->getResultArray();
        self::assertCount(2, $status, 'Dibutuhkan minimal dua baris r_status.');
        $this->statusAlfa = (string) $status[0]['statusId'];
        $this->statusBeta = (string) $status[1]['statusId'];

        $this->bersihkanData();
        $this->koneksi->table('d_ticketing')->insert([
            'ticketTrackingId' => self::TIKET_ALFA,
            'ticketName'       => 'Pemohon Filter Alfa',
            'ticketEmail'      => 'alfa@example.com',
            'ticketCreated'    => '2026-01-15 08:30:00',
            'ticketStatus'     => $this->statusAlfa,
        ]);
        $this->koneksi->table('d_ticketing')->insert([
            'ticketTrackingId' => self::TIKET_BETA,
            'ticketName'       => 'Pemohon Filter Beta',
            'ticketEmail'      => 'beta@example.com',
            'ticketCreated'    => '2026-02-20 08:30:00',
            'ticketStatus'     => $this->statusBeta,
        ]);
    }

    protected function tearDown(): void
    {
        $this->bersihkanData();
        parent::tearDown();
    }

    private function bersihkanData(): void
    {
        $this->koneksi->table('d_disposisi')->whereIn('disposisiTicketId', [self::TIKET_ALFA, self::TIKET_BETA])->delete();
        $this->koneksi->table('d_ticketing')->whereIn('ticketTrackingId', [self::TIKET_ALFA, self::TIKET_BETA])->delete();
    }

    /**
     * @return array<string, string>
     */
    private function sesiAdmin(): array
    {
        return ['logged_in' => [
            'susrNama'           => 'admin_uji_filter',
            'susrSgroupNama'     => 'ADMIN',
            'susrSgroupNama_ori' => 'ADMIN',
            'susrProfil'         => 'Admin Uji Filter',
        ]];
    }

    /**
     * @param array<string, string> $filter
     */
    private function kirimFilter(array $filter): string
    {
        $hasil = $this->withSession($this->sesiAdmin())->post('ticketing/response', array_merge([
            'rentangTanggal' => '',
            'layanan'        => '',
            'status_layanan' => '',
            'keyword'        => '',
        ], $filter, [csrf_token() => csrf_hash()]));

        $hasil->assertStatus(200);

        return $hasil->getBody();
    }

    public function testSemuaFilterKosongDitolak(): void
    {
        $body = $this->kirimFilter([]);

        $this->assertStringContainsString('"status": "error"', $body);
        $this->assertStringContainsString('minimal satu filter', $body);
        $this->assertStringNotContainsString(self::TIKET_ALFA, $body);
    }

    public function testKataKunciSajaCukupMenyaring(): void
    {
        $body = $this->kirimFilter(['keyword' => 'Filter Alfa']);

        $this->assertStringContainsString(self::TIKET_ALFA, $body);
        $this->assertStringNotContainsString(self::TIKET_BETA, $body);
    }

    public function testStatusSajaCukupMenyaring(): void
    {
        $body = $this->kirimFilter(['status_layanan' => $this->statusAlfa]);

        $this->assertStringContainsString(self::TIKET_ALFA, $body);
        $this->assertStringNotContainsString(self::TIKET_BETA, $body);
    }

    public function testRentangTanggalSajaTetapMenyaring(): void
    {
        $body = $this->kirimFilter(['rentangTanggal' => '01-01-2026 / 31-01-2026']);

        $this->assertStringContainsString(self::TIKET_ALFA, $body);
        $this->assertStringNotContainsString(self::TIKET_BETA, $body);
    }
}
