<?php

namespace Tests\Bugfix;

use App\Libraries\Enkripsi;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Pengujian TDD (RED -> GREEN) untuk 4 Temuan Kritis Audit Penuh E-ULT v2:
 * - C-01: Privilege escalation di Home::prosesubahhakakses() & hilangnya susrCategoryId
 * - C-02: Kerusakan relasi arsip, reset status/waktu, & penolakan edit di Ticketing::save()
 * - C-03: Type juggling boolean false dari Enkripsi::decode() pada Unit, Refkategori, Refsurat, Refsyarat, Pengguna
 * - C-04: Stored XSS pada panel petugas, halaman validitas publik (+ eksposur PII), helper, & template email
 */
final class RemediasiKritisAudit2026Test extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const KUNCI_RUSAK = 'KUNCI-RUSAK-TIDAK-TERDECODE-C03';
    private const PAYLOAD_XSS = '"><script>alert(1)</script>';

    private BaseConnection $koneksi;
    private Enkripsi $enkripsi;

    protected function setUp(): void
    {
        parent::setUp();
        Services::reset();

        $this->koneksi  = \Config\Database::connect('default');
        $this->enkripsi = new Enkripsi();

        self::assertFalse($this->enkripsi->decode(self::KUNCI_RUSAK));
    }

    private function sesiPetugasBiasa(string $username, string $grup = 'OPERATOR'): array
    {
        return [
            'susrNama'           => $username,
            'susrSgroupNama'     => $grup,
            'susrSgroupNama_ori' => $grup,
            'susrProfil'         => 'Petugas Uji C01',
            'susrCategoryId'     => '1',
        ];
    }

    private function sesiAdmin(): array
    {
        return [
            'susrNama'           => 'admin_uji_kritis',
            'susrSgroupNama'     => 'ADMIN',
            'susrSgroupNama_ori' => 'ADMIN',
            'susrProfil'         => 'Admin Uji Kritis',
            'susrCategoryId'     => '99',
        ];
    }

    // =========================================================================
    // [C-01] Privilege Escalation di Home::prosesubahhakakses()
    // =========================================================================

    public function testC01ProsesUbahHakAksesMenolakEscalationKeGrupYangTidakDimiliki(): void
    {
        $username = 'usr_c01_' . substr(md5((string) microtime(true)), 0, 8);
        $this->koneksi->transBegin();

        try {
            // Ambil 1 grup non-ADMIN yang ada di s_user_group
            $grupNonAdmin = $this->koneksi->table('s_user_group')
                ->where('sgroupNama !=', 'ADMIN')
                ->limit(1)
                ->get()->getRowArray();
            self::assertNotNull($grupNonAdmin, 'Harus ada minimal 1 grup non-ADMIN di s_user_group.');
            $namaGrupAwal = (string) $grupNonAdmin['sgroupNama'];

            $this->koneksi->table('s_user')->insert([
                'susrNama'       => $username,
                'susrPassword'   => password_hash('SandiAman1234', PASSWORD_DEFAULT),
                'susrSgroupNama' => $namaGrupAwal,
                'susrProfil'     => 'Petugas Uji C01',
            ]);
            $this->koneksi->table('s_user_group_user')->insert([
                'sgroupSusrNama'   => $username,
                'sgroupSgroupNama' => $namaGrupAwal,
            ]);

            $hasil = $this->withSession(['logged_in' => $this->sesiPetugasBiasa($username, $namaGrupAwal)])
                ->withBodyFormat('form')
                ->post('home/prosesubahhakakses', [
                    csrf_token() => csrf_hash(),
                    'hakakses'   => 'ADMIN',
                ]);

            $json = json_decode($hasil->getJSON(), true);
            self::assertSame('error', $json['status'] ?? null, 'Permintaan pindah ke grup ADMIN yang tidak dimiliki wajib ditolak.');
            self::assertNotSame('ADMIN', $_SESSION['logged_in']['susrSgroupNama'] ?? null, 'Sesi pengguna tidak boleh berubah menjadi ADMIN.');
        } finally {
            $this->koneksi->transRollback();
        }
    }

    public function testC01ProsesUbahHakAksesMempertahankanSusrCategoryIdSaatPindahKeGrupSah(): void
    {
        $username = 'usr_c01b_' . substr(md5((string) microtime(true)), 0, 7);
        $this->koneksi->transBegin();

        try {
            $duaGrup = $this->koneksi->table('s_user_group')
                ->limit(2)
                ->get()->getResultArray();
            self::assertGreaterThanOrEqual(2, count($duaGrup), 'Butuh minimal 2 grup di s_user_group.');

            $grup1 = (string) $duaGrup[0]['sgroupNama'];
            $grup2 = (string) $duaGrup[1]['sgroupNama'];
            $cat2  = (string) ($duaGrup[1]['sgroupCategoryId'] ?? '');

            $this->koneksi->table('s_user')->insert([
                'susrNama'       => $username,
                'susrPassword'   => password_hash('SandiAman1234', PASSWORD_DEFAULT),
                'susrSgroupNama' => $grup1,
                'susrProfil'     => 'Petugas Multi Role',
            ]);
            $this->koneksi->table('s_user_group_user')->insertBatch([
                ['sgroupSusrNama' => $username, 'sgroupSgroupNama' => $grup1],
                ['sgroupSusrNama' => $username, 'sgroupSgroupNama' => $grup2],
            ]);

            $hasil = $this->withSession(['logged_in' => $this->sesiPetugasBiasa($username, $grup1)])
                ->withBodyFormat('form')
                ->post('home/prosesubahhakakses', [
                    csrf_token() => csrf_hash(),
                    'hakakses'   => $grup2,
                ]);

            $json = json_decode($hasil->getJSON(), true);
            self::assertSame('success', $json['status'] ?? null, 'Pindah ke grup yang dimiliki secara sah harus berhasil.');
            self::assertSame($grup2, $_SESSION['logged_in']['susrSgroupNama'] ?? null);
            self::assertArrayHasKey('susrCategoryId', $_SESSION['logged_in'] ?? [], 'susrCategoryId wajib tetap ada di sesi setelah pindah hak akses.');
            self::assertSame($cat2, (string) ($_SESSION['logged_in']['susrCategoryId'] ?? ''));
        } finally {
            $this->koneksi->transRollback();
        }
    }

    // =========================================================================
    // [C-02] Korupsi Data & Penolakan Edit di Ticketing::save() + Helper Role
    // =========================================================================

    public function testC02EultPemohonUmumTidakTertipuRoleTerakhirSaatIdentitasBelumTerverifikasi(): void
    {
        $_SESSION = [];
        eult_pemohon_role_simpan('1801015001', 'internal');

        $roleNikBaru = eult_pemohon_role('6472032410880003');
        self::assertNull($roleNikBaru);
        self::assertTrue(
            eult_pemohon_umum($roleNikBaru),
            'Identitas baru yang belum diverifikasi (null) WAJIB tetap dianggap umum meskipun sebelumnya ada NIM internal di session.'
        );
    }

    public function testC02TicketingSaveSaatEditTanpaUploadBaruMempertahankanArsipStatusDanWaktuAsli(): void
    {
        $this->koneksi->transBegin();

        try {
            $model = new \App\Models\ModelMaster();
            $semuaLayanan = $model->getLayanan(false);
            self::assertIsArray($semuaLayanan);

            // Cari layanan internal (yang TIDAK boleh untuk umum)
            $layananInternalId = null;
            foreach ($semuaLayanan as $l) {
                $idL = (string) $l['layananId'];
                if (! $model->layananBolehUntukUmum($idL)) {
                    $layananInternalId = $idL;
                    break;
                }
            }
            self::assertNotNull($layananInternalId, 'Harus ada minimal 1 layanan internal untuk pengujian C-02.');

            $idTiket    = 'ZZC2-TEST-999';
            $arsipAsli  = 'ZZC2TEST9990001';
            $waktuAsli  = '2025-05-10 08:30:00';
            $statusAsli = 3; // Accepted / Dalam Proses

            $this->koneksi->table('d_ticketing')->insert([
                'ticketTrackingId' => $idTiket,
                'ticketIdentitas'  => '1801015001',
                'ticketName'       => 'Mahasiswa Uji Asli',
                'ticketCategories' => $layananInternalId,
                'ticketEmail'      => 'mhs@unmul.ac.id',
                'ticketNoHp'       => '081234567890',
                'ticketSubject'    => 'Subjek Lama',
                'ticketPriority'   => '1',
                'ticketMessage'    => 'Pesan Lama',
                'ticketCreated'    => $waktuAsli,
                'ticketStatus'     => $statusAsli,
                'ticketCreatedBy'  => 'Pemohon Asli',
                'ticketArchiveId'  => $arsipAsli,
            ]);

            $this->koneksi->table('d_archive')->insert([
                'archiveId'         => $arsipAsli,
                'archiveTrackingId' => $idTiket,
                'archiveFile'       => 'TIKET_ZZC2TEST999_lama.pdf',
                'archiveJenis'      => 'TIKET',
            ]);

            // Edit tiket tanpa klik "Cek Identitas" ulang di sesi petugas dan tanpa unggah PDF baru
            $hasil = $this->withSession(['logged_in' => $this->sesiAdmin()])
                ->withBodyFormat('form')
                ->post('ticketing/save', [
                    csrf_token()       => csrf_hash(),
                    'ticketIdOld'      => $idTiket,
                    'ticketIdentitas'  => '1801015001',
                    'ticketName'       => 'Mahasiswa Uji Diperbarui',
                    'ticketCategories' => $layananInternalId,
                    'ticketEmail'      => 'mhs@unmul.ac.id',
                    'ticketNoHp'       => '081234567890',
                    'ticketPriority'   => '1',
                    'ticketSubject'    => 'Subjek Diperbarui Petugas',
                    'ticketMessage'    => 'Pesan Diperbarui Petugas',
                ]);

            $json = json_decode($hasil->getJSON(), true);
            self::assertSame('success', $json['status'] ?? null, 'Edit tiket mahasiswa tanpa klik Cek Identitas ulang harus berhasil: ' . ($json['message'] ?? ''));

            $barisBaru = $this->koneksi->table('d_ticketing')->where('ticketTrackingId', $idTiket)->get()->getRowArray();
            self::assertNotNull($barisBaru);
            self::assertSame('Subjek Diperbarui Petugas', $barisBaru['ticketSubject']);
            self::assertSame($arsipAsli, $barisBaru['ticketArchiveId'], 'ticketArchiveId tidak boleh ditimpa ke ID arsip fiktif saat tidak ada upload baru.');
            self::assertSame($statusAsli, (int) $barisBaru['ticketStatus'], 'ticketStatus tidak boleh di-reset kembali ke 1 saat edit.');
            self::assertSame($waktuAsli, $barisBaru['ticketCreated'], 'ticketCreated asli tidak boleh ditimpa saat edit.');
            self::assertSame('Pemohon Asli', $barisBaru['ticketCreatedBy'], 'ticketCreatedBy asli tidak boleh ditimpa saat edit.');
        } finally {
            $this->koneksi->transRollback();
        }
    }

    // =========================================================================
    // [C-03] Type Juggling decode() === false pada DELETE & UPDATE Master
    // =========================================================================

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function deleteMasterProvider(): array
    {
        return [
            'unit (s_unit)'                  => ['unit', 's_unit', 'unit/delete/' . self::KUNCI_RUSAK],
            'refkategori (r_category_sub)'   => ['refkategori', 'r_category_sub', 'refkategori/delete/' . self::KUNCI_RUSAK],
            'refsurat (r_category)'          => ['refsurat', 'r_category', 'refsurat/delete/' . self::KUNCI_RUSAK],
            'refsyarat (r_berkas_layanan)'   => ['refsyarat', 'r_berkas_layanan', 'refsyarat/delete/' . self::KUNCI_RUSAK],
        ];
    }

    /**
     * @dataProvider deleteMasterProvider
     */
    public function testC03DeleteMasterMenolakKunciGagalDecodeTanpaMenyentuhTabel(string $modul, string $tabel, string $url): void
    {
        $this->koneksi->transBegin();

        try {
            // Pastikan ADMIN memiliki izin baca modul di transaksi uji ini agar melewati AuthFilter
            $izinAda = $this->koneksi->table('s_user_group_modul')
                ->where('sgroupmodulSgroupNama', 'ADMIN')
                ->where('sgroupmodulSusrmodulNama', $modul)
                ->countAllResults() > 0;

            if (! $izinAda) {
                $this->koneksi->table('s_user_group_modul')->insert([
                    'sgroupmodulSgroupNama'    => 'ADMIN',
                    'sgroupmodulSusrmodulNama' => $modul,
                    'sgroupmodulSusrmodulRead' => 1,
                ]);
            }

            $jumlahSebelum = $this->koneksi->table($tabel)->countAllResults();

            $hasil = $this->withSession(['logged_in' => $this->sesiAdmin()])
                ->withBodyFormat('form')
                ->post($url, [csrf_token() => csrf_hash()]);

            $json = json_decode($hasil->getJSON(), true);
            self::assertSame('error', $json['status'] ?? null, "Delete {$modul} dengan kunci gagal decode wajib ditolak.");
            self::assertStringContainsString('tidak valid', strtolower((string) ($json['message'] ?? '')));
            self::assertSame($jumlahSebelum, $this->koneksi->table($tabel)->countAllResults(), "Tabel {$tabel} tidak boleh berkurang.");
        } finally {
            $this->koneksi->transRollback();
        }
    }

    public function testC03UpdatePenggunaDanUnitDenganKunciRusakTidakMemuatBarisAcakVarcharNol(): void
    {
        $hasilPengguna = $this->withSession(['logged_in' => $this->sesiAdmin()])
            ->get('pengguna/update/' . self::KUNCI_RUSAK);

        // Bila s_user.susrNama bertipe VARCHAR, WHERE susrNama = FALSE (= 0) akan mencocokkan baris user acak!
        // Setelah diperbaiki, field hidden susrNamaOld harus kosong (value="").
        self::assertStringContainsString(
            'name="susrNamaOld" value=""',
            $hasilPengguna->getBody(),
            'Pengguna::update dengan kunci rusak wajib mengosongkan datas (tidak boleh mencocokkan WHERE susrNama = 0).'
        );

        $hasilUnit = $this->withSession(['logged_in' => $this->sesiAdmin()])
            ->get('unit/update/' . self::KUNCI_RUSAK);
        self::assertStringContainsString(
            'name="unitIdOld" value=""',
            $hasilUnit->getBody(),
            'Unit::update dengan kunci rusak wajib mengosongkan datas (tidak boleh mencocokkan WHERE unitId = 0).'
        );
    }

    // =========================================================================
    // [C-04] Stored XSS & Eksposur PII
    // =========================================================================

    public function testC04DisposisiHistoryMengescapeSeluruhFieldTiketDanPesanDisposisi(): void
    {
        $renderer = Services::renderer();
        $renderer->resetData();
        $html = $renderer->setData([
            'datas' => [
                'ticketCreated'   => '2026-10-01 09:00:00',
                'ticketIdentitas' => self::PAYLOAD_XSS,
                'ticketName'      => self::PAYLOAD_XSS,
                'ticketNoHp'      => self::PAYLOAD_XSS,
                'ticketEmail'     => self::PAYLOAD_XSS,
                'sCatNama'        => 'Sub Layanan',
                'categoryNama'    => 'Kategori',
                'ticketSubject'   => self::PAYLOAD_XSS,
                'ticketMessage'   => self::PAYLOAD_XSS,
            ],
            'archive_first_url' => false,
            'disposisi' => [
                [
                    'disposisiTanggal'    => '2026-10-01 10:00:00',
                    'disposisiUser'       => 'petugas1',
                    'disposisiUserProfil' => self::PAYLOAD_XSS,
                    'dunitNama'           => 'Unit Uji',
                    'disposisiMessage'    => self::PAYLOAD_XSS,
                    'statusNama'          => 'Assigned',
                    'priorityName'        => 'Normal',
                    'archiveId'           => 'ARSIP001',
                    'archiveFile'         => 'dok.pdf',
                ],
            ],
            'loadpdf' => site_url('ticketing/loadpdf/'),
        ], 'raw')->render('pages/ticketing/disposisi_history');

        self::assertStringNotContainsString('<script>alert(1)</script>', $html, 'disposisi_history.php tidak boleh merender script mentah.');
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testC04ValiditasPublikMengescapeOutputDanMenyamarkanPiiEmailSertaNoHp(): void
    {
        $renderer = Services::renderer();
        $renderer->resetData();
        $html = $renderer->setData([
            'datas' => [
                'ticketIdentitas'  => self::PAYLOAD_XSS,
                'ticketName'       => self::PAYLOAD_XSS,
                'ticketEmail'      => 'rahasia.pemohon@unmul.ac.id',
                'ticketNoHp'       => '081299887766',
                'ticketTrackingId' => 'ABCD-EFGH-001',
                'ticketCreated'    => '2026-10-01 09:00:00',
                'sCatNama'         => 'Layanan Uji',
                'categoryNama'     => 'Kategori Uji',
                'statusNama'       => 'Selesai',
            ],
            'loadpdf_url' => site_url('validitas/loadpdf/kunci'),
        ], 'raw')->render('pages/validitas/index');

        self::assertStringNotContainsString('<script>alert(1)</script>', $html, 'validitas/index.php wajib meng-escape seluruh field tiket.');
        self::assertStringContainsString('ABCD-EFGH-001', $html, 'Nomor tiket tetap harus tampil untuk verifikasi.');
        self::assertStringNotContainsString('rahasia.pemohon@unmul.ac.id', $html, 'Email lengkap pemohon tidak boleh diekspos polos di halaman publik validitas.');
        self::assertStringNotContainsString('081299887766', $html, 'Nomor HP lengkap pemohon tidak boleh diekspos polos di halaman publik validitas.');
    }

    public function testC04FormTiketDanFormSuratMengescapeAtributInput(): void
    {
        $renderer = Services::renderer();

        $renderer->resetData();
        $htmlFormTiket = $renderer->setData([
            'page_judul'  => 'Form Tiket',
            'save_url'    => site_url('ticketing/save'),
            'datas'       => [
                'ticketTrackingId' => 'ABCD-EFGH-001',
                'ticketIdentitas'  => self::PAYLOAD_XSS,
                'ticketName'       => self::PAYLOAD_XSS,
                'ticketCategories' => '1',
                'ticketEmail'      => self::PAYLOAD_XSS,
                'ticketNoHp'       => self::PAYLOAD_XSS,
                'ticketPriority'   => '1',
                'ticketSubject'    => self::PAYLOAD_XSS,
                'ticketMessage'    => self::PAYLOAD_XSS,
            ],
            'layanan'     => [],
            'r_priority'  => [],
            'archive_url' => false,
        ], 'raw')->render('pages/ticketing/form');

        self::assertStringNotContainsString('<script>alert(1)</script>', $htmlFormTiket, 'ticketing/form.php wajib meng-escape nilai input.');

        $renderer->resetData();
        $htmlFormSurat = $renderer->setData([
            'page_judul'  => 'Form Surat',
            'save_url'    => site_url('ticketing/delivered'),
            'preview_url' => site_url('ticketing/preview/kunci'),
            'get_preview' => site_url('ticketing/get_preview'),
            'nomorTiket'  => self::PAYLOAD_XSS,
            'judulSurat'  => self::PAYLOAD_XSS,
            'isiSurat'    => self::PAYLOAD_XSS,
            'tujuanSurat' => self::PAYLOAD_XSS,
            'lampSurat'   => self::PAYLOAD_XSS,
            'datas'       => [
                'suratJenis'                 => self::PAYLOAD_XSS,
                'suratLampiran'              => self::PAYLOAD_XSS,
                'suratTujuan'                => self::PAYLOAD_XSS,
                'suratBody'                  => self::PAYLOAD_XSS,
                'suratTanggal'               => self::PAYLOAD_XSS,
                'suratPejabatNamaDraft'      => self::PAYLOAD_XSS,
                'suratPejabatNIPDraft'       => self::PAYLOAD_XSS,
                'suratPejabatJabatanAnDraft' => self::PAYLOAD_XSS,
                'suratPejabatJabatanDraft'   => self::PAYLOAD_XSS,
                'suratTembusan'              => self::PAYLOAD_XSS,
            ],
        ], 'raw')->render('pages/ticketing/form_surat');

        self::assertStringNotContainsString('<script>alert(1)</script>', $htmlFormSurat, 'ticketing/form_surat.php wajib meng-escape nilai input.');
    }

    public function testC04TemplateEmailLoginGetSyaratHelperAksiDanIkonSidebarAmanDariXss(): void
    {
        $renderer = Services::renderer();

        // 1. Template Email Create & Validated
        foreach (['layouts/template_surat_create', 'layouts/template_surat_validated'] as $tpl) {
            $renderer->resetData();
            $htmlEmail = $renderer->setData([
                'datas' => [
                    'ticketTrackingId' => 'ABCD-EFGH-001',
                    'ticketIdentitas'  => self::PAYLOAD_XSS,
                    'ticketName'       => self::PAYLOAD_XSS,
                    'ticketEmail'      => self::PAYLOAD_XSS,
                    'ticketNoHp'       => self::PAYLOAD_XSS,
                    'ticketSubject'    => self::PAYLOAD_XSS,
                    'ticketMessage'    => self::PAYLOAD_XSS,
                    'ticketCreated'    => '2026-10-01 10:00:00',
                    'sCatNama'         => self::PAYLOAD_XSS,
                    'categoryNama'     => self::PAYLOAD_XSS,
                    'priorityName'     => self::PAYLOAD_XSS,
                    'statusNama'       => 'Open',
                ],
                'url_detail' => 'https://eult.unmul.ac.id/cektiket/index/kunci',
            ], 'raw')->render($tpl);

            self::assertStringNotContainsString('<script>alert(1)</script>', $htmlEmail, "{$tpl} wajib meng-escape data pemohon.");
        }

        // 2. Login::getSyarat()
        $this->koneksi->transBegin();
        try {
            $this->koneksi->table('r_berkas_layanan')->insert([
                'berkasidLayanan'  => '999991',
                'berkasNama'       => self::PAYLOAD_XSS,
                'berkasKeterangan' => 'Uji XSS',
            ]);

            $hasilSyarat = $this->withBodyFormat('form')->post('login/getSyarat', [
                csrf_token() => csrf_hash(),
                'id'         => '999991',
            ]);
            self::assertStringNotContainsString('<script>alert(1)</script>', $hasilSyarat->getBody(), 'Login::getSyarat wajib meng-escape berkasNama.');
        } finally {
            $this->koneksi->transRollback();
        }

        // 3. Sanitasi susrmdgroupIcon di modulgroup/index.php (ikon valid lolos, XSS ditolak)
        $renderer->resetData();
        $htmlModulGroup = $renderer->setData([
            'page_judul' => 'Modul Group',
            'user_group' => ['susrSgroupNama' => 'ADMIN'],
            'create_url' => '#',
            'update_url' => '#',
            'delete_url' => '#',
            'datas'      => [
                [
                    'susrmdgroupNama'    => 'aman',
                    'susrmdgroupDisplay' => 'Grup Aman',
                    'susrmdgroupIcon'    => '<i class="la la-desktop"></i>',
                ],
                [
                    'susrmdgroupNama'    => 'jahat',
                    'susrmdgroupDisplay' => 'Grup Jahat',
                    'susrmdgroupIcon'    => '<img src=x onerror=alert(1)><script>alert(1)</script>',
                ],
            ],
        ], 'raw')->render('pages/modulgroup/index');

        self::assertStringContainsString('<i class="la la-desktop"></i>', $htmlModulGroup, 'Tag ikon <i> yang valid harus tetap tampil.');
        self::assertStringNotContainsString('<script>alert(1)</script>', $htmlModulGroup, 'Payload script pada susrmdgroupIcon harus diblokir/di-escape.');
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $htmlModulGroup, 'Tag HTML berbahaya pada susrmdgroupIcon harus diblokir.');
    }
}
