<?php

namespace Tests\Bugfix;

use App\Commands\TinjauArsipHilang;
use App\Libraries\Enkripsi;
use App\Libraries\OsmClient;
use App\Models\ModelMaster;
use App\Models\ModelTicketing;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Filters\CITestStreamFilter;
use Config\Filters;
use Config\Services;
use ReflectionProperty;

/**
 * Pengujian TDD (RED -> GREEN) untuk 8 Temuan Rendah Audit Penuh E-ULT v2:
 * - L-01: Nama profil satu kata terpotong menjadi kosong di header & perlindungan XSS
 * - L-02: GuestFilter melindungi rute root '/' bagi pengguna internal yang sudah login
 * - L-03: Opsi --hapus --ya tanpa --semua pada TinjauArsipHilang menghapus seluruh kandidat (>20)
 * - L-04: Pemetaan unit kerja grup tampil di hakaksesuser/index.php dan hakaksesunit/index.php
 * - L-05: Eliminasi query N+1 ke r_surat pada eult_tombol_aksi() via join suratNomor
 * - L-06: Konsolidasi pecahan/duplikasi blok <style> pada template surat & detail view
 * - L-07: ModelMaster::getByLastId() mengembalikan false saat tidak ada baris yang cocok
 * - L-08: Verifikasi sertifikat SSL eksplisit pada OsmClient
 */
final class RemediasiRendahAudit2026Test extends CIUnitTestCase
{
    use FeatureTestTrait;

    private BaseConnection $koneksi;
    private Enkripsi $enkripsi;
    private string $layananValidId = '9';

    protected function setUp(): void
    {
        parent::setUp();
        Services::reset();

        $this->koneksi = \Config\Database::connect('default');
        $this->koneksi->resetTransStatus();
        $this->enkripsi = new Enkripsi();

        $model   = new ModelMaster();
        $layanan = $model->getLayanan(true);
        if (is_array($layanan) && $layanan !== []) {
            $this->layananValidId = (string) $layanan[0]['layananId'];
        }
    }

    private function sesiAdmin(string $username = 'admin_uji_l'): array
    {
        return [
            'susrNama'           => $username,
            'susrSgroupNama'     => 'ADMIN',
            'susrSgroupNama_ori' => 'ADMIN',
            'susrProfil'         => 'Admin Uji Rendah',
            'susrCategoryId'     => '99',
        ];
    }

    private function pastikanIzinModul(string $grup, string $modul): void
    {
        $adaModul = $this->koneksi->table('s_user_modul_ref')
            ->where('susrmodulNama', $modul)
            ->countAllResults() > 0;

        if (! $adaModul) {
            $this->koneksi->table('s_user_modul_ref')->insert([
                'susrmodulNama'            => $modul,
                'susrmodulNamaDisplay'     => ucfirst($modul),
                'susrmodulSusrmdgroupNama' => 'admin',
                'susrmodulIsLogin'         => '1',
                'susrmodulUrut'            => 9,
            ]);
        }

        $ada = $this->koneksi->table('s_user_group_modul')
            ->where('sgroupmodulSgroupNama', $grup)
            ->where('sgroupmodulSusrmodulNama', $modul)
            ->countAllResults() > 0;

        if (! $ada) {
            $this->koneksi->table('s_user_group_modul')->insert([
                'sgroupmodulSgroupNama'    => $grup,
                'sgroupmodulSusrmodulNama' => $modul,
                'sgroupmodulSusrmodulRead' => 1,
            ]);
        }
    }

    /**
     * [L-01] Nama profil satu kata tidak boleh hilang menjadi kosong di header,
     * nama multi-kata tetap menampilkan kata pertama, dan output di-escape aman.
     */
    public function testL01ProfilSatuKataTampilUtuhDanAmanDiHeader(): void
    {
        $htmlSatuKata = view('layouts/header', [
            'susrProfil'     => 'Administrator',
            'susrSgroupNama' => 'ADMIN',
        ]);

        self::assertMatchesRegularExpression(
            '/kt-header__topbar-username[^>]*>\s*Administrator\s*</',
            $htmlSatuKata,
            'L-01: Nama profil satu kata tanpa spasi harus tampil utuh di topbar header.'
        );

        $htmlMultiKata = view('layouts/header', [
            'susrProfil'     => 'Budi Santoso',
            'susrSgroupNama' => 'OPERATOR',
        ]);

        self::assertMatchesRegularExpression(
            '/kt-header__topbar-username[^>]*>\s*Budi\s*</',
            $htmlMultiKata,
            'L-01: Nama profil multi-kata tetap menampilkan kata pertama di topbar header.'
        );

        $htmlKarakterKhusus = view('layouts/header', [
            'susrProfil'     => 'Admin<script>x</script>',
            'susrSgroupNama' => 'GRP<b>1</b>',
        ]);

        self::assertStringNotContainsString(
            '<script>x</script>',
            $htmlKarakterKhusus,
            'L-01: Nama profil di header wajib di-escape terhadap injeksi HTML.'
        );
        self::assertStringNotContainsString(
            'GRP<b>1</b>',
            $htmlKarakterKhusus,
            'L-01: Nama grup di header wajib di-escape terhadap injeksi HTML.'
        );
    }

    /**
     * [L-02] GuestFilter wajib melindungi rute '/' maupun '/login' agar pengguna
     * internal yang sudah login langsung diarahkan ke dashboard '/home'.
     */
    public function testL02GuestFilterMelindungiRuteRootBagiPenggunaLogin(): void
    {
        $konfigurasiFilter = new Filters();
        $ruteGuest         = $konfigurasiFilter->filters['guest']['before'] ?? [];

        self::assertContains(
            '/',
            $ruteGuest,
            'L-02: Konfigurasi filter guest harus menyertakan rute root \'/\'.'
        );

        $responsLogin = $this->withSession(['logged_in' => $this->sesiAdmin()])->get('/');
        $responsLogin->assertRedirectTo(site_url('home'));

        Services::reset();
        $responsTamu = $this->get('/');
        $responsTamu->assertOK();
    }

    /**
     * [L-03] Menjalankan perintah eult:arsip dengan --hapus --ya (tanpa --semua)
     * wajib menghapus seluruh kandidat yatim, bukan berhenti diam-diam di 20 baris.
     */
    public function testL03TinjauArsipHilangHapusYaMenghapusSeluruhKandidatLebihDari20(): void
    {
        $this->koneksi->transBegin();
        CITestStreamFilter::registration();
        CITestStreamFilter::addOutputFilter();
        CITestStreamFilter::addErrorFilter();
        CITestStreamFilter::$buffer = '';

        try {
            $jumlahUji = 23;
            for ($i = 1; $i <= $jumlahUji; $i++) {
                $idTiket = sprintf('L03U-%04d-001', $i);
                $this->koneksi->table('d_ticketing')->insert([
                    'ticketTrackingId' => $idTiket,
                    'ticketName'       => 'Pemohon Uji L03 ' . $i,
                    'ticketEmail'      => 'l03_' . $i . '@example.test',
                    'ticketNoHp'       => '08123456789',
                    'ticketCategories' => $this->layananValidId,
                    'ticketPriority'   => 1,
                    'ticketSubject'    => 'Subjek Yatim L03 ' . $i,
                    'ticketMessage'    => 'Pesan yatim tanpa arsip L03',
                    'ticketCreated'    => '2099-12-15 10:00:' . sprintf('%02d', $i),
                    'ticketStatus'     => 1,
                    'ticketArchiveId'  => 'ARSIP_HILANG_L03_' . $i,
                    'ticketIdentitas'  => '220000' . sprintf('%02d', $i),
                    'ticketCustomer'   => 'MAHASISWA',
                ]);
            }

            $perintah = new TinjauArsipHilang(Services::logger(), Services::commands());
            $perintah->run([
                'sejak=2099-12-01',
                'hapus' => null,
                'ya'    => null,
            ]);

            $tersisa = $this->koneksi->table('d_ticketing')
                ->like('ticketTrackingId', 'L03U-', 'after')
                ->countAllResults();

            self::assertSame(
                0,
                $tersisa,
                'L-03: Opsi --hapus --ya wajib menghapus seluruh 23 kandidat yatim, bukan hanya 20 baris teratas.'
            );
        } finally {
            CITestStreamFilter::removeOutputFilter();
            CITestStreamFilter::removeErrorFilter();
            $this->koneksi->transRollback();
            $this->koneksi->resetTransStatus();
        }
    }

    /**
     * [L-04] Unit kerja yang dipetakan di s_user_group_unit wajib tampil pada
     * halaman Hak Akses User dan dropdown Hak Akses Unit meskipun kolom sgroupUnit kosong.
     */
    public function testL04UnitKerjaTerpetakanTampilPadaHakaksesuserDanHakaksesunit(): void
    {
        $this->koneksi->transBegin();

        try {
            $this->pastikanIzinModul('ADMIN', 'hakaksesuser');
            $this->pastikanIzinModul('ADMIN', 'hakaksesunit');

            $unit = $this->koneksi->table('s_unit')
                ->select('unitId, unitNama')
                ->where('unitDisplay', 1)
                ->orderBy('unitId', 'ASC')
                ->get()
                ->getRowArray();

            self::assertIsArray($unit, 'Tabel s_unit harus memiliki minimal 1 unit aktif.');

            $namaGrup = 'GRUP_UJI_L04';
            $this->koneksi->table('s_user_group')->insert([
                'sgroupNama'       => $namaGrup,
                'sgroupKeterangan' => 'Grup Uji L04',
                'sgroupUnit'       => '',
            ]);

            $this->koneksi->table('s_user_group_unit')->insert([
                'sgroupunitSgroupNama' => $namaGrup,
                'sgroupunitUnitId'     => $unit['unitId'],
                'sgroupunitUnitRead'   => 1,
                'sgroupunitIsHome'     => 1,
            ]);

            $resUser = $this->withSession(['logged_in' => $this->sesiAdmin()])->get('hakaksesuser');
            $resUser->assertOK();
            $htmlUser = (string) $resUser->response()->getBody();

            self::assertMatchesRegularExpression(
                '/<td>\s*' . preg_quote($namaGrup, '/') . '\s*<\/td>\s*<td>\s*' . preg_quote(esc((string) $unit['unitNama']), '/') . '\s*<\/td>/s',
                $htmlUser,
                'L-04: Halaman Hak Akses User harus menampilkan nama unit kerja yang terpetakan di s_user_group_unit.'
            );

            Services::reset();
            $resUnit = $this->withSession(['logged_in' => $this->sesiAdmin()])->get('hakaksesunit');
            $resUnit->assertOK();
            $htmlUnit = (string) $resUnit->response()->getBody();

            self::assertStringNotContainsString(
                'value="' . $namaGrup . '|"',
                $htmlUnit,
                'L-04: Option Hak Akses Unit tidak boleh menyisakan pemisah pipa kosong dari kolom sgroupUnit.'
            );
            self::assertStringContainsString(
                'value="' . $namaGrup . '"',
                $htmlUnit,
                'L-04: Option Hak Akses Unit harus menggunakan nama grup yang bersih.'
            );
        } finally {
            $this->koneksi->transRollback();
            $this->koneksi->resetTransStatus();
        }
    }

    /**
     * [L-05] ModelTicketing::dataById() dan disposisiAll() wajib menyertakan suratNomor,
     * serta eult_tombol_aksi() tidak boleh menjalankan query SQL tambahan saat suratNomor disediakan.
     */
    public function testL05EultTombolAksiTidakMenjalankanQueryNPlus1KeRSurat(): void
    {
        helper(['eult_aksi', 'url']);
        $this->koneksi->transBegin();

        try {
            $idTiket = 'L05U-ABCD-001';
            $noSurat = 'UNMUL/ULT/L05/2026';

            $unit = $this->koneksi->table('s_unit')
                ->select('unitId')
                ->orderBy('unitId', 'ASC')
                ->get()
                ->getRowArray();
            $unitId = (int) ($unit['unitId'] ?? 1);

            $this->koneksi->table('d_ticketing')->insert([
                'ticketTrackingId' => $idTiket,
                'ticketName'       => 'Pemohon L05',
                'ticketEmail'      => 'l05@example.test',
                'ticketNoHp'       => '08123456789',
                'ticketCategories' => $this->layananValidId,
                'ticketPriority'   => 1,
                'ticketSubject'    => 'Subjek L05',
                'ticketMessage'    => 'Pesan L05',
                'ticketCreated'    => '2026-11-01 10:00:00',
                'ticketStatus'     => 7,
                'ticketAssign'     => $unitId,
                'ticketIsVerified' => 1,
                'ticketIdentitas'  => '22000099',
                'ticketCustomer'   => 'MAHASISWA',
            ]);

            $this->koneksi->table('r_surat')->insert([
                'suratTrackingId' => $idTiket,
                'suratNomor'      => $noSurat,
            ]);

            $this->koneksi->table('s_user_group')->insert([
                'sgroupNama'       => 'GRUP_UJI_L05',
                'sgroupKeterangan' => 'Grup Uji L05',
                'sgroupUnit'       => '',
            ]);
            $this->koneksi->table('s_user_group_unit')->insert([
                'sgroupunitSgroupNama' => 'GRUP_UJI_L05',
                'sgroupunitUnitId'     => $unitId,
                'sgroupunitUnitRead'   => 1,
                'sgroupunitIsHome'     => 1,
            ]);
            $this->koneksi->table('d_disposisi')->insert([
                'disposisiTicketId' => $idTiket,
                'disposisiTanggal'  => '2026-11-01 10:05:00',
                'disposisiUnit'     => $unitId,
                'disposisiStatus'   => 7,
                'disposisiPriority' => 1,
            ]);

            $modelTiket = new ModelTicketing();
            $barisAdmin = $modelTiket->dataById(['ticketTrackingId' => $idTiket]);
            self::assertIsArray($barisAdmin);
            self::assertSame(
                $noSurat,
                $barisAdmin[0]['suratNomor'] ?? null,
                'L-05: ModelTicketing::dataById() wajib mengembalikan kolom suratNomor tanpa query terpisah.'
            );

            $barisUnit = $modelTiket->disposisiAll([
                'ticketTrackingId'     => $idTiket,
                'sgroupunitSgroupNama' => 'GRUP_UJI_L05',
            ]);
            self::assertIsArray($barisUnit);
            self::assertSame(
                $noSurat,
                $barisUnit[0]['suratNomor'] ?? null,
                'L-05: ModelTicketing::disposisiAll() wajib mengembalikan kolom suratNomor tanpa query terpisah.'
            );

            // Jalankan query penanda untuk memastikan eult_tombol_aksi tidak mengeksekusi query baru
            $this->koneksi->query('SELECT 1 AS penanda_tanpa_query_n1');
            $querySebelum = (string) $this->koneksi->getLastQuery();

            ob_start();
            eult_tombol_aksi([
                'key'             => $this->enkripsi->encode($idTiket),
                'urut'            => 1,
                'jenis'           => 'BOTTOMUP',
                'layanan'         => $this->layananValidId,
                'suratCreated'    => true,
                'suratNomor'      => $noSurat,
                'status'          => 7,
                'isVerified'      => 1,
                'disposisiIsTrue' => true,
                'isSehari'        => 1,
                'ishome'          => true,
                'user_group'      => 'ADMIN',
            ]);
            $htmlAksi     = (string) ob_get_clean();
            $querySesudah = (string) $this->koneksi->getLastQuery();

            self::assertStringContainsString(
                'data-val="' . esc($noSurat, 'attr') . '"',
                $htmlAksi,
                'L-05: Tombol validasi harus tetap memuat nomor surat pada atribut data-val.'
            );
            self::assertSame(
                $querySebelum,
                $querySesudah,
                'L-05: eult_tombol_aksi() tidak boleh menjalankan query SQL ke r_surat bila suratNomor sudah dikirim.'
            );

            $isiResponseView = (string) file_get_contents(APPPATH . 'Views/pages/ticketing/response.php');
            self::assertStringContainsString(
                "'suratNomor'",
                $isiResponseView,
                'L-05: View ticketing/response.php harus meneruskan suratNomor dari hasil query utama.'
            );
        } finally {
            $this->koneksi->transRollback();
            $this->koneksi->resetTransStatus();
        }
    }

    /**
     * [L-06] Template view tidak boleh memiliki pecahan/duplikasi blok <style>
     * berturut-turut maupun tag <style></style> kosong.
     */
    public function testL06TemplateViewTidakMemilikiDuplikasiAtauBlokStyleKosong(): void
    {
        $berkasView = [
            'app/Views/layouts/template_surat_create.php',
            'app/Views/layouts/template_surat_validated.php',
            'app/Views/pages/ticketing/detail.php',
        ];

        foreach ($berkasView as $relatif) {
            $isi        = (string) file_get_contents(ROOTPATH . $relatif);
            $jumlahTag  = preg_match_all('/<style\b[^>]*>/i', $isi);
            $adaKosong  = preg_match('/<style\b[^>]*>\s*<\/style>/i', $isi);

            self::assertLessThanOrEqual(
                1,
                (int) $jumlahTag,
                sprintf('L-06: Berkas %s memiliki %d blok <style>; harus dikonsolidasikan menjadi maksimal 1 blok.', $relatif, (int) $jumlahTag)
            );
            self::assertSame(
                0,
                (int) $adaKosong,
                sprintf('L-06: Berkas %s tidak boleh memiliki blok <style></style> kosong.', $relatif)
            );
        }
    }

    /**
     * [L-07] ModelMaster::getByLastId() wajib mengembalikan false (bukan array
     * truthy [$kolom => null]) ketika tidak ada baris yang cocok dengan kondisi.
     */
    public function testL07GetByLastIdMengembalikanFalseSaatTidakAdaBarisCocok(): void
    {
        $master = new ModelMaster();

        $kosong = $master->getByLastId('d_archive', 'archiveId', [
            'archiveTrackingId' => 'TIKET-TIDAK-PERNAH-ADA-L07-999999',
        ]);

        self::assertFalse(
            $kosong,
            'L-07: getByLastId() wajib mengembalikan false bila MAX(kolom) bernilai NULL karena tidak ada baris yang cocok.'
        );

        $this->koneksi->transBegin();

        try {
            $idTiket = 'L07U-ABCD-001';
            $idArsip = 'L07UABCD0010001';

            $this->koneksi->table('d_ticketing')->insert([
                'ticketTrackingId' => $idTiket,
                'ticketName'       => 'Pemohon L07',
                'ticketEmail'      => 'l07@example.test',
                'ticketNoHp'       => '08123456789',
                'ticketCategories' => $this->layananValidId,
                'ticketPriority'   => 1,
                'ticketSubject'    => 'Subjek L07',
                'ticketMessage'    => 'Pesan L07',
                'ticketCreated'    => '2026-11-01 11:00:00',
                'ticketStatus'     => 1,
                'ticketIdentitas'  => '22000077',
                'ticketCustomer'   => 'MAHASISWA',
            ]);

            $this->koneksi->table('d_archive')->insert([
                'archiveId'         => $idArsip,
                'archiveTrackingId' => $idTiket,
                'archiveFile'       => 'uji_l07.pdf',
                'archiveJenis'      => 'SYARAT',
            ]);

            $ada = $master->getByLastId('d_archive', 'archiveId', [
                'archiveTrackingId' => $idTiket,
            ]);

            self::assertIsArray($ada);
            self::assertSame($idArsip, $ada['archiveId'] ?? null);
        } finally {
            $this->koneksi->transRollback();
            $this->koneksi->resetTransStatus();
        }
    }

    /**
     * [L-08] OsmClient wajib mengaktifkan verifikasi sertifikat SSL ('verify' => true)
     * secara eksplisit pada instance CURLRequest default.
     */
    public function testL08OsmClientMengaktifkanVerifikasiSslSecaraEksplisit(): void
    {
        $klien = new OsmClient();

        self::assertTrue(
            method_exists($klien, 'apakahVerifikasiSslAktif') && $klien->apakahVerifikasiSslAktif(),
            'L-08: OsmClient harus mengaktifkan verifikasi SSL secara default.'
        );

        $propHttp = new ReflectionProperty(OsmClient::class, 'http');
        $http     = $propHttp->getValue($klien);

        $propOpsi    = new ReflectionProperty($http, 'defaultOptions');
        $opsiDefault = $propOpsi->getValue($http);

        self::assertIsArray($opsiDefault);
        self::assertArrayHasKey(
            'verify',
            $opsiDefault,
            'L-08: Konfigurasi CURLRequest pada OsmClient wajib menyertakan opsi \'verify\' secara eksplisit.'
        );
        self::assertTrue(
            $opsiDefault['verify'],
            'L-08: Opsi \'verify\' pada OsmClient harus bernilai true untuk mencegah serangan MitM.'
        );
    }
}
