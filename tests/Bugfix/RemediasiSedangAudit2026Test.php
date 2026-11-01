<?php

namespace Tests\Bugfix;

use App\Controllers\Cektiket;
use App\Controllers\Otentifikasi;
use App\Libraries\Enkripsi;
use App\Libraries\OsmClient;
use App\Libraries\PengirimEmail;
use App\Models\ModelMaster;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Filters;
use Config\Services;
use Config\Throttle;

/**
 * Pengujian TDD (RED -> GREEN) untuk 14 Temuan Sedang Audit Penuh E-ULT v2:
 * - M-01: Pemotongan digit urutan pertama pada eult_auto_increment()
 * - M-02: Presedensi operator || vs && dan konsistensi role di eult_aksi_helper.php
 * - M-03: Duplicate event listener binding dan bug $this.text di ticketing.js
 * - M-04: Pill filter cepat status tiket di ticketing/index.php
 * - M-05: Handler .btn-ajax-terima di ticketing/detail.php tidak memeriksa res.status === 'error'
 * - M-06: Sinkronisasi CSRF di detail_user.php & redirect ramah non-AJAX di saveReplies()
 * - M-07: Enkripsi archiveId pada seluruh tautan lampiran disposisi_history.php (termasuk archive_first_url)
 * - M-08: Pemulihan tombol submit pada error AJAX di login.js, form-submit-general.js, & dashboard.js
 * - M-09: Validasi field wajib, sinkronisasi nama OSM, rotasi captcha & cegah bocor ID di Login::savetiket()
 * - M-10: Fallback password lokal saat respons OSM tidak cocok ID di Otentifikasi::cekDatabase()
 * - M-11: Cek status selesai (5), throttle, dan pencegahan spam email ulang di Cektiket::rating()
 * - M-12: Filter unit kerja pada KPI & tiket_urgent serta tren mingguan nyata di Home::index()
 * - M-13: Password awal, sinkronisasi grup, validasi unik rename, & cegah hapus diri sendiri di Pengguna
 * - M-14: Pembersihan lengkap r_surat/d_rating/d_worker/file pada Ticketing::delete() & transaksi Hakakses*::save()
 */
final class RemediasiSedangAudit2026Test extends CIUnitTestCase
{
    use FeatureTestTrait;

    private BaseConnection $koneksi;
    private Enkripsi $enkripsi;
    private string $layananValidId = '9';

    protected function setUp(): void
    {
        parent::setUp();
        Services::reset();

        $this->koneksi  = \Config\Database::connect('default');
        $this->koneksi->resetTransStatus();
        $this->enkripsi = new Enkripsi();

        $model   = new ModelMaster();
        $layanan = $model->getLayanan(true);
        if (is_array($layanan) && $layanan !== []) {
            $this->layananValidId = (string) $layanan[0]['layananId'];
        }
    }

    private function sesiAdmin(string $username = 'admin_uji_m'): array
    {
        return [
            'susrNama'           => $username,
            'susrSgroupNama'     => 'ADMIN',
            'susrSgroupNama_ori' => 'ADMIN',
            'susrProfil'         => 'Admin Uji Sedang',
            'susrCategoryId'     => '99',
        ];
    }

    private function pastikanIzinModul(string $grup, string $modul): void
    {
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

    private function denganTokenCsrf(array $post): array
    {
        $permintaan = $this->setupRequest('post', 'login/savetiket');
        Services::injectMock('request', $permintaan);
        Services::resetSingle('security');

        $post[csrf_token()] = csrf_hash();

        return $post;
    }

    // =========================================================================
    // [M-01] Pemotongan Digit Pertama Nomor Urut pada eult_auto_increment()
    // =========================================================================

    public function testM01EultAutoIncrementMembacaSeluruhEmpatDigitNomorUrutArsip(): void
    {
        $idTiket = 'ZZM1-TEST-001';
        $prefix  = 'ZZM1TEST001'; // 11 karakter

        $this->koneksi->transBegin();

        try {
            $this->koneksi->table('d_ticketing')->insert([
                'ticketTrackingId' => $idTiket,
                'ticketName'       => 'Pemohon M01',
                'ticketEmail'      => 'm01@example.invalid',
                'ticketCategories' => $this->layananValidId,
                'ticketPriority'   => '1',
                'ticketStatus'     => 1,
                'ticketCreated'    => '2026-10-20 10:00:00',
            ]);

            $this->koneksi->table('d_archive')->insert([
                'archiveId'         => $prefix . '1000',
                'archiveTrackingId' => $idTiket,
                'archiveFile'       => 'TIKET_ZZM1TEST001_1000.pdf',
                'archiveJenis'      => 'TIKET',
            ]);

            $berikutnya = eult_auto_increment('d_archive', 'archiveId', $prefix, ['archiveTrackingId' => $idTiket]);
            self::assertSame(
                $prefix . '1001',
                $berikutnya,
                'eult_auto_increment() wajib membaca penuh 4 digit urutan setelah prefix 11 karakter (1000 -> 1001, bukan 0001).'
            );
        } finally {
            $this->koneksi->transRollback();
            $this->koneksi->resetTransStatus();
        }
    }

    // =========================================================================
    // [M-02] Presedensi Operator || vs && & Role di eult_aksi_helper.php
    // =========================================================================

    public function testM02EultAksiHelperPresedensiOperatorDanKonsistensiPeranProduksiVerifikator(): void
    {
        $kunci = $this->enkripsi->encode('ZZM2-AKSI-001');

        // 1. Role DISPOSISI pada layanan sehari (isSehari = 1, bukan 2) TIDAK boleh melihat tombol Paraf & Disposisi
        session()->set('logged_in', [
            'susrNama'       => 'disp_m02',
            'susrSgroupNama' => 'DISPOSISI_UNIT',
            'susrProfil'     => 'Disposisi Uji',
        ]);

        ob_start();
        eult_tombol_aksi([
            'key'             => $kunci,
            'urut'            => 1,
            'jenis'           => 'TOPUP',
            'layanan'         => '10',
            'suratCreated'    => true,
            'status'          => 3,
            'isVerified'      => 0,
            'disposisiIsTrue' => false,
            'isSehari'        => 1,
            'ishome'          => true,
        ]);
        $htmlDisposisiSehari = (string) ob_get_clean();

        self::assertStringNotContainsString(
            'ticketing/accept',
            $htmlDisposisiSehari,
            'Role DISPOSISI tidak boleh melihat tombol Paraf dan Disposisi ketika isSehari != 2.'
        );

        // 2. Role VERIFIKATOR harus dikenali untuk tombol Verifikasi (ticketing/terima)
        session()->set('logged_in', [
            'susrNama'       => 'verif_m02',
            'susrSgroupNama' => 'VERIFIKATOR_UNIT',
            'susrProfil'     => 'Verifikator Uji',
        ]);

        ob_start();
        eult_tombol_aksi([
            'key'             => $kunci,
            'urut'            => 2,
            'jenis'           => 'BOTTOMUP',
            'layanan'         => '10',
            'suratCreated'    => true,
            'status'          => 3,
            'isVerified'      => 0,
            'disposisiIsTrue' => false,
            'isSehari'        => 2,
            'ishome'          => true,
        ]);
        $htmlVerifikator = (string) ob_get_clean();

        self::assertStringContainsString(
            'ticketing/terima',
            $htmlVerifikator,
            'Role VERIFIKATOR wajib menampilkan tombol Verifikasi pada tiket BOTTOMUP yang belum diverifikasi.'
        );

        // 3. Role PRODUKSI harus dikenali untuk tombol Buat Surat (ticketing/create_surat)
        session()->set('logged_in', [
            'susrNama'       => 'prod_m02',
            'susrSgroupNama' => 'PRODUKSI_UNIT',
            'susrProfil'     => 'Produksi Uji',
        ]);

        ob_start();
        eult_tombol_aksi([
            'key'             => $kunci,
            'urut'            => 3,
            'jenis'           => 'BOTTOMUP',
            'layanan'         => '10',
            'suratCreated'    => true,
            'status'          => 3,
            'isVerified'      => 1,
            'disposisiIsTrue' => false,
            'isSehari'        => 2,
            'ishome'          => true,
        ]);
        $htmlProduksi = (string) ob_get_clean();

        self::assertStringContainsString(
            'ticketing/create_surat',
            $htmlProduksi,
            'Role PRODUKSI wajib menampilkan tombol Buat Surat pada tiket yang sudah diverifikasi.'
        );
    }

    // =========================================================================
    // [M-03] Duplicate Event Listener Binding & Bug $this.text di ticketing.js
    // =========================================================================

    public function testM03TicketingJsBebasDuplicateBindingDanBugPropertiTextTombol(): void
    {
        $js = (string) file_get_contents(FCPATH . 'assets/js/pages/custom/pages/ticketing/ticketing.js');

        self::assertStringNotContainsString(
            '$this.text =',
            $js,
            'ticketing.js tidak boleh menetapkan properti non-standar $this.text pada elemen button.'
        );

        foreach (['mdNoSurat', 'mdPesanValidasi', 'mdPesanTolak', 'mdEktm'] as $modalVar) {
            self::assertStringContainsString(
                "{$modalVar}.off('show.bs.modal').on('show.bs.modal'",
                $js,
                "Modal {$modalVar} wajib memakai .off('show.bs.modal') sebelum .on('show.bs.modal')."
            );
        }

        foreach (['btnFinish', 'btnEktm', 'btnFinish3', 'btnTolak'] as $btnVar) {
            self::assertStringContainsString(
                "{$btnVar}.off('click').on('click'",
                $js,
                "Tombol modal {$btnVar} wajib memakai .off('click') sebelum .on('click') agar tidak menggandakan AJAX."
            );
        }
    }

    // =========================================================================
    // [M-04] Tombol Pill Filter Cepat Status di ticketing/index.php
    // =========================================================================

    public function testM04TicketingIndexPillFilterStatusMenggunakanStatusIdValidDanMemicuBtnShow(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/pages/ticketing/index.php');

        self::assertMatchesRegularExpression(
            '/data-status="3"\s+data-filter="disposisi"/',
            $view,
            'Pill Disposisi wajib mengacu ke statusId=3 (Sedang Proses).'
        );
        self::assertMatchesRegularExpression(
            '/data-status="7"\s+data-filter="verifikasi"/',
            $view,
            'Pill Verifikasi wajib mengacu ke statusId=7 (Menunggu Verifikasi).'
        );
        self::assertStringContainsString(
            "$('#btn_show').trigger('click')",
            $view,
            'Klik pill filter status wajib memicu tombol #btn_show untuk memuat data terfilter.'
        );
    }

    // =========================================================================
    // [M-05] Handler .btn-ajax-terima di ticketing/detail.php
    // =========================================================================

    public function testM05TicketingDetailBtnAjaxTerimaMemeriksaStatusErrorDariResponsJson(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/pages/ticketing/detail.php');

        $posBtn = strpos($view, "$('.btn-ajax-terima')");
        self::assertNotFalse($posBtn);

        $potonganHandler = substr($view, $posBtn, 1800);
        self::assertStringContainsString(
            "res.status === 'error'",
            $potonganHandler,
            'Handler .btn-ajax-terima wajib memeriksa res.status === \'error\' sebelum menampilkan Berhasil Diverifikasi.'
        );
    }

    // =========================================================================
    // [M-06] Sinkronisasi CSRF detail_user.php & Redirect Non-AJAX saveReplies
    // =========================================================================

    public function testM06SinkronisasiCsrfDetailUserDanRedirectRamahSaatSaveRepliesNonAjaxKosong(): void
    {
        $viewPublik = (string) file_get_contents(APPPATH . 'Views/pages/ticketing/detail_user.php');
        self::assertStringContainsString(
            'input[name="<?= esc(csrf_token(), \'attr\') ?>"]',
            $viewPublik,
            'detail_user.php wajib ikut menyinkronkan hidden input CSRF form balasan saat token baru diterima.'
        );

        $idTiket = 'ZZM6-CHAT-001';
        $this->koneksi->transBegin();

        try {
            $this->pastikanIzinModul('ADMIN', 'ticketing');
            $this->koneksi->table('d_ticketing')->insert([
                'ticketTrackingId' => $idTiket,
                'ticketName'       => 'Pemohon M06',
                'ticketEmail'      => 'm06@example.invalid',
                'ticketCategories' => $this->layananValidId,
                'ticketPriority'   => '1',
                'ticketStatus'     => 3,
                'ticketCreated'    => '2026-10-20 10:00:00',
            ]);

            $hasil = $this->withSession(['logged_in' => $this->sesiAdmin()])
                ->withHeaders(['Accept' => 'text/html,application/xhtml+xml'])
                ->withBodyFormat('form')
                ->post('ticketing/save_replies', [
                    csrf_token()      => csrf_hash(),
                    'repliesTicketId' => $idTiket,
                    'repliesMessage'  => '',
                ]);

            self::assertTrue(
                $hasil->isRedirect(),
                'Pengiriman form chat native (non-AJAX) dengan pesan kosong wajib me-redirect kembali, bukan menampilkan JSON mentah.'
            );
        } finally {
            $this->koneksi->transRollback();
            $this->koneksi->resetTransStatus();
        }
    }

    // =========================================================================
    // [M-07] Enkripsi archiveId pada Tautan Lampiran disposisi_history.php
    // =========================================================================

    public function testM07TautanLampiranUtamaDanDisposisiDiModalMenggunakanArchiveIdTerenkripsi(): void
    {
        $idTiket  = 'ZZM7-PDF-001';
        $idArsip  = 'ZZM7PDF0010001';
        $namaFile = 'TIKET_RAHASIA_M07_20261020.pdf';
        $lokasi   = WRITEPATH . 'uploads/ticketing/' . $namaFile;

        file_put_contents($lokasi, '%PDF-1.4 UJI M07 ARSIP');
        $this->koneksi->transBegin();

        try {
            $this->pastikanIzinModul('ADMIN', 'ticketing');
            $this->koneksi->table('d_ticketing')->insert([
                'ticketTrackingId' => $idTiket,
                'ticketName'       => 'Pemohon M07',
                'ticketEmail'      => 'm07@example.invalid',
                'ticketCategories' => $this->layananValidId,
                'ticketPriority'   => '1',
                'ticketStatus'     => 1,
                'ticketArchiveId'  => $idArsip,
                'ticketCreated'    => '2026-10-20 10:00:00',
            ]);
            $this->koneksi->table('d_archive')->insert([
                'archiveId'         => $idArsip,
                'archiveTrackingId' => $idTiket,
                'archiveFile'       => $namaFile,
                'archiveJenis'      => 'TIKET',
            ]);

            $kunci = $this->enkripsi->encode($idTiket);
            $hasil = $this->withSession(['logged_in' => $this->sesiAdmin()])
                ->get('ticketing/assign/' . $kunci);

            $hasil->assertStatus(200);
            $json = json_decode($hasil->getJSON(), true);
            $html = (string) ($json['response'] ?? '');

            self::assertStringNotContainsString(
                $namaFile,
                $html,
                'Modal assign (disposisi_history.php) tidak boleh mengekspos nama berkas fisik mentah pada archive_first_url.'
            );
            self::assertStringContainsString('ticketing/loadpdf/', $html);
        } finally {
            $this->koneksi->transRollback();
            $this->koneksi->resetTransStatus();
            @unlink($lokasi);
        }
    }

    // =========================================================================
    // [M-08] Pemulihan Tombol Submit pada Error AJAX di 3 Berkas JS
    // =========================================================================

    public function testM08SeluruhAjaxSubmitMemulihkanTombolPadaHandlerError(): void
    {
        $loginJs     = (string) file_get_contents(FCPATH . 'assets/js/pages/custom/pages/user/login.js');
        $generalJs   = (string) file_get_contents(FCPATH . 'assets/js/pages/custom/pages/form-submit-general.js');
        $dashboardJs = (string) file_get_contents(FCPATH . 'assets/js/pages/custom/pages/dashboard.js');

        self::assertGreaterThanOrEqual(
            3,
            substr_count($loginJs, 'error:'),
            'Ketiga form ajaxSubmit di login.js wajib memiliki handler error: untuk membuka kunci tombol submit.'
        );
        self::assertGreaterThanOrEqual(
            1,
            substr_count($generalJs, 'error:'),
            'form-submit-general.js wajib memiliki handler error: untuk memulihkan tombol #btn_save.'
        );
        self::assertGreaterThanOrEqual(
            1,
            substr_count($dashboardJs, 'error:'),
            'dashboard.js wajib memiliki handler error: untuk memulihkan tombol #btn_save.'
        );
    }

    // =========================================================================
    // [M-09] Validasi Field, Sinkronisasi Nama, & Anti-Kebocoran tiketSerupa
    // =========================================================================

    public function testM09LoginSavetiketValidasiFieldWajibSinkronisasiNamaOsmDanCegahKebocoranTiketSerupa(): void
    {
        $idTiketAsli = 'ZZM9-ORIG-001';
        $this->koneksi->transBegin();

        try {
            // 1. Tanpa ticketName harus ditolak di validasi awal
            $resTanpaNama = $this->withSession(['captcha' => 'M9A1'])
                ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
                ->post('login/savetiket', $this->denganTokenCsrf([
                    'captcha'          => 'M9A1',
                    'ticketCategories' => $this->layananValidId,
                    'ticketEmail'      => 'm09@example.invalid',
                    'ticketNoHp'       => '081234567890',
                    'ticketSubject'    => 'Subjek Uji M09',
                    'ticketMessage'    => 'Pesan Uji M09',
                    'ticketPriority'   => '1',
                ]));

            $bodyTanpaNama = (string) $resTanpaNama->getBody();
            self::assertStringContainsString('"danger"', $bodyTanpaNama);
            self::assertStringNotContainsString('Berkas persyaratan wajib dilampirkan', $bodyTanpaNama, 'Request tanpa ticketName wajib berhenti di validasi aturan form sebelum cek berkas.');

            // 2. Tiket serupa milik mahasiswa asli TIDAK boleh bocor ke email berbeda
            $this->koneksi->table('d_ticketing')->insert([
                'ticketTrackingId' => $idTiketAsli,
                'ticketIdentitas'  => '1801015099',
                'ticketName'       => 'Mahasiswa Pemilik Asli',
                'ticketEmail'      => 'pemilik.asli@unmul.ac.id',
                'ticketNoHp'       => '081234560000',
                'ticketCategories' => $this->layananValidId,
                'ticketPriority'   => '1',
                'ticketSubject'    => 'Permohonan Transkrip M09',
                'ticketMessage'    => 'Isi pesan identik M09 untuk uji tiketSerupa',
                'ticketStatus'     => 1,
                'ticketCreated'    => date('Y-m-d H:i:s'),
            ]);

            $resEmailBeda = $this->withSession(['captcha' => 'M9B2'])
                ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
                ->post('login/savetiket', $this->denganTokenCsrf([
                    'captcha'          => 'M9B2',
                    'ticketIdentitas'  => '1801015099',
                    'ticketName'       => 'Penyerang Tebak NIM',
                    'ticketCategories' => $this->layananValidId,
                    'ticketEmail'      => 'penyerang.asing@example.invalid',
                    'ticketNoHp'       => '089999999999',
                    'ticketSubject'    => 'Permohonan Transkrip M09',
                    'ticketMessage'    => 'Isi pesan identik M09 untuk uji tiketSerupa',
                    'ticketPriority'   => '1',
                ]));

            $bodyEmailBeda = (string) $resEmailBeda->getBody();
            self::assertStringNotContainsString(
                $idTiketAsli,
                $bodyEmailBeda,
                'Login::savetiket() DILARANG membocorkan ticketTrackingId tiketSerupa jika ticketEmail tidak cocok dengan pemilik tiket.'
            );

            // 3. Jika email cocok dengan pemilik asli, tiketSerupa boleh dikembalikan TETAPI captcha wajib dirotasi
            $resEmailCocok = $this->withSession(['captcha' => 'M9C3'])
                ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
                ->post('login/savetiket', $this->denganTokenCsrf([
                    'captcha'          => 'M9C3',
                    'ticketIdentitas'  => '1801015099',
                    'ticketName'       => 'Mahasiswa Pemilik Asli',
                    'ticketCategories' => $this->layananValidId,
                    'ticketEmail'      => 'pemilik.asli@unmul.ac.id',
                    'ticketNoHp'       => '081234560000',
                    'ticketSubject'    => 'Permohonan Transkrip M09',
                    'ticketMessage'    => 'Isi pesan identik M09 untuk uji tiketSerupa',
                    'ticketPriority'   => '1',
                ]));

            $bodyEmailCocok = (string) $resEmailCocok->getBody();
            self::assertStringContainsString($idTiketAsli, $bodyEmailCocok);
            self::assertNotSame(
                'M9C3',
                session()->get('captcha'),
                'Cabang tiketSerupa wajib memanggil rotasi captcha agar captcha lama tidak dapat dipakai ulang.'
            );
        } finally {
            $this->koneksi->transRollback();
            $this->koneksi->resetTransStatus();
        }
    }

    // =========================================================================
    // [M-10] Fallback Password Lokal di Otentifikasi::cekDatabase()
    // =========================================================================

    public function testM10OtentifikasiCekDatabaseTetapFallbackKePasswordLokalSaatOsmIdTidakCocok(): void
    {
        $username = 'lokal_uji_m10';
        $sandi    = 'SandiLokalAman#2026';

        $this->koneksi->transBegin();

        try {
            $grup = $this->koneksi->table('s_user_group')->select('sgroupNama')->limit(1)->get()->getRowArray();
            $namaGrup = (string) ($grup['sgroupNama'] ?? 'ADMIN');

            $this->koneksi->table('s_user')->insert([
                'susrNama'       => $username,
                'susrPassword'   => password_hash($sandi, PASSWORD_BCRYPT),
                'susrSgroupNama' => $namaGrup,
                'susrProfil'     => 'Akun Lokal M10',
            ]);

            $mockOsm = new class () extends OsmClient {
                public function postLogin(string $username, string $password): object|string|false
                {
                    // Simulasikan OSM mengembalikan objek data tetapi id null / tidak cocok dengan susrNama
                    return (object) ['data' => (object) ['id' => null]];
                }
            };

            $request = Services::request();
            if ($request instanceof IncomingRequest) {
                $request->setMethod('POST');
                $request->setGlobal('post', ['username' => $username, 'password' => $sandi]);
            }

            $ctrl = new Otentifikasi();
            $ctrl->initController($request, Services::response(), Services::logger());

            $refOsm = new \ReflectionProperty(Otentifikasi::class, 'osm');
            $refOsm->setValue($ctrl, $mockOsm);

            self::assertTrue(
                $ctrl->cekDatabase($sandi),
                'Otentifikasi::cekDatabase() wajib fallback ke password_verify() lokal apabila respons OSM tidak mencocokkan ID pengguna.'
            );
        } finally {
            $this->koneksi->transRollback();
            $this->koneksi->resetTransStatus();
        }
    }

    // =========================================================================
    // [M-11] Cektiket::rating() Cek Status Selesai, Throttle, & Anti-Spam Email
    // =========================================================================

    public function testM11CektiketRatingMenolakTiketBelumSelesaiTerdaftarThrottleDanMencegahSpamEmailUlang(): void
    {
        $konfigFilter   = new Filters();
        $konfigThrottle = new Throttle();

        self::assertArrayHasKey(
            'throttle:cektiket/rating',
            $konfigFilter->filters,
            'Config\\Filters wajib mendaftarkan throttle:cektiket/rating.'
        );
        self::assertArrayHasKey(
            'cektiket/rating',
            $konfigThrottle->routes,
            'Config\\Throttle wajib memiliki konfigurasi limit untuk cektiket/rating.'
        );

        $idTiket = 'ZZM11-RATE-001';
        $this->koneksi->transBegin();

        try {
            $this->koneksi->table('d_ticketing')->insert([
                'ticketTrackingId' => $idTiket,
                'ticketName'       => 'Pemohon Rating M11',
                'ticketEmail'      => 'm11-rating@example.invalid',
                'ticketCategories' => $this->layananValidId,
                'ticketPriority'   => '1',
                'ticketStatus'     => 1, // Masih Baru (belum selesai)
                'ticketCreated'    => '2026-10-20 10:00:00',
            ]);
            $kunci = $this->enkripsi->encode($idTiket);

            $spyEmail = new class () extends PengirimEmail {
                public int $jumlahPanggilan = 0;

                public function selesai(string $email, string $subjek, mixed $param, string|false $lampiran = false): bool
                {
                    $this->jumlahPanggilan++;

                    return true;
                }
            };

            $jalankanRating = function (string $nilai) use ($kunci, $spyEmail): void {
                $req = Services::request();
                if ($req instanceof IncomingRequest) {
                    $req->setMethod('POST');
                    $req->setGlobal('post', ['rating' => $nilai, 'ratingKeterangan' => 'Uji M11']);
                }
                $ctrl = new Cektiket();
                $ctrl->initController($req, Services::response(), Services::logger());

                $ref = new \ReflectionProperty(Cektiket::class, 'email');
                $ref->setValue($ctrl, $spyEmail);

                try {
                    $ctrl->rating($kunci);
                } catch (\App\Exceptions\ResponsAwalException $e) {
                    // Respons JSON normal dari eult_message_kirim
                }
            };

            // 1. Saat status tiket = 1 (belum selesai), rating harus ditolak
            $jalankanRating('5');
            $barisAwal = $this->koneksi->table('d_rating')->where('ratingTicketId', $idTiket)->get()->getRowArray();
            self::assertNull($barisAwal, 'Tiket yang belum selesai (status != 5) tidak boleh menerima rating.');
            self::assertSame(0, $spyEmail->jumlahPanggilan);

            // 2. Ubah status tiket = 5 (Selesai), rating pertama harus tersimpan dan mengirim 1 email
            $this->koneksi->table('d_ticketing')->where('ticketTrackingId', $idTiket)->update(['ticketStatus' => 5]);
            $jalankanRating('5');
            $barisPertama = $this->koneksi->table('d_rating')->where('ratingTicketId', $idTiket)->get()->getRowArray();
            self::assertNotNull($barisPertama);
            self::assertSame(1, $spyEmail->jumlahPanggilan, 'Pengisian rating pertama kali wajib mengirim 1 email.');

            // 3. Pengiriman rating kedua (update) harus memperbarui nilai tanpa mengirim email lagi
            $jalankanRating('4');
            $barisKedua = $this->koneksi->table('d_rating')->where('ratingTicketId', $idTiket)->get()->getRowArray();
            self::assertSame(4.0, (float) $barisKedua['ratingNilai']);
            self::assertSame(1, $spyEmail->jumlahPanggilan, 'Pembaruan rating berulang DILARANG memicu pengiriman email ulang (mail bombing).');
        } finally {
            $this->koneksi->transRollback();
            $this->koneksi->resetTransStatus();
        }
    }

    // =========================================================================
    // [M-12] Isolasi Unit Kerja KPI & Tiket Urgent di Home::index()
    // =========================================================================

    public function testM12HomeIndexMenyaringKpiDanTiketUrgentBerdasarkanUnitPenggunaSertaMenghitungTrenMingguan(): void
    {
        $idTiketUnitLain = 'ZZM12-URG-999';
        $grupStaf        = 'STAF_UJI_M12';

        $this->koneksi->transBegin();

        try {
            $this->koneksi->table('s_unit')->insert(['unitKode' => '9701', 'unitNama' => 'Unit A M12']);
            $unitA = (string) $this->koneksi->insertID();

            $this->koneksi->table('s_unit')->insert(['unitKode' => '9702', 'unitNama' => 'Unit B Rahasia M12']);
            $unitB = (string) $this->koneksi->insertID();

            $this->koneksi->table('s_user_group')->insert([
                'sgroupNama'       => $grupStaf,
                'sgroupKeterangan' => 'Staf Unit A M12',
            ]);
            $this->koneksi->table('s_user_group_unit')->insert([
                'sgroupunitSgroupNama' => $grupStaf,
                'sgroupunitUnitId'     => $unitA,
                'sgroupunitUnitRead'   => 1,
                'sgroupunitIsHome'     => 1,
            ]);

            // Masukkan tiket urgent terbaru milik Unit B (unit lain!)
            $this->koneksi->table('d_ticketing')->insert([
                'ticketTrackingId' => $idTiketUnitLain,
                'ticketName'       => 'Pemohon Unit B',
                'ticketEmail'      => 'unitb@example.invalid',
                'ticketCategories' => $this->layananValidId,
                'ticketPriority'   => '1',
                'ticketStatus'     => 1,
                'ticketAssign'     => $unitB,
                'ticketCreated'    => '2099-12-30 10:00:00',
            ]);

            $hasil = $this->withSession([
                'logged_in' => [
                    'susrNama'           => 'staf_m12',
                    'susrSgroupNama'     => $grupStaf,
                    'susrSgroupNama_ori' => $grupStaf,
                    'susrProfil'         => 'Staf Unit A',
                    'susrCategoryId'     => '',
                ],
            ])->get('home');

            $hasil->assertStatus(200);
            self::assertStringNotContainsString(
                $idTiketUnitLain,
                $hasil->getBody(),
                'Dashboard Home::index() untuk staf non-ADMIN/non-OPERATOR tidak boleh membocorkan tiket urgent milik unit kerja lain.'
            );
        } finally {
            $this->koneksi->transRollback();
            $this->koneksi->resetTransStatus();
        }
    }

    // =========================================================================
    // [M-13] Empat Perbaikan CRUD Pengguna (Password, Grup, Rename, Self-Delete)
    // =========================================================================

    public function testM13PenggunaSaveMenyetelPasswordAwalSinkronisasiGrupCegahDuplikatRenameDanTolakHapusDiriSendiri(): void
    {
        $userBaru = 'user_m13_baru';
        $userLain = 'user_m13_eksisting';
        $adminUji = 'admin_m13_login';

        $this->koneksi->transBegin();

        try {
            $this->pastikanIzinModul('ADMIN', 'pengguna');
            $duaGrup = $this->koneksi->table('s_user_group')->limit(2)->get()->getResultArray();
            self::assertGreaterThanOrEqual(2, count($duaGrup));
            $grup1 = (string) $duaGrup[0]['sgroupNama'];
            $grup2 = (string) $duaGrup[1]['sgroupNama'];

            // Siapkan admin yang sedang login & satu user eksisting
            $this->koneksi->table('s_user')->insertBatch([
                [
                    'susrNama'       => $adminUji,
                    'susrPassword'   => password_hash('Admin123!', PASSWORD_BCRYPT),
                    'susrSgroupNama' => 'ADMIN',
                    'susrProfil'     => 'Admin Aktif M13',
                ],
                [
                    'susrNama'       => $userLain,
                    'susrPassword'   => password_hash('User123!', PASSWORD_BCRYPT),
                    'susrSgroupNama' => $grup1,
                    'susrProfil'     => 'User Eksisting M13',
                ],
            ]);

            // 1. Create pengguna baru harus otomatis mengisi hash password & s_user_group_user
            $resCreate = $this->withSession(['logged_in' => $this->sesiAdmin($adminUji)])
                ->withBodyFormat('form')
                ->post('pengguna/save', [
                    csrf_token()     => csrf_hash(),
                    'susrNamaOld'    => '',
                    'susrNama'       => $userBaru,
                    'susrSgroupNama' => $grup1,
                    'susrProfil'     => 'Pegawai Baru M13',
                ]);
            $jsonCreate = json_decode($resCreate->getJSON(), true);
            self::assertSame('success', $jsonCreate['status'] ?? null);

            $barisBaru = $this->koneksi->table('s_user')->where('susrNama', $userBaru)->get()->getRowArray();
            self::assertNotNull($barisBaru);
            self::assertNotEmpty($barisBaru['susrPassword'] ?? '', 'Pengguna baru wajib mendapat hash password awal agar tidak kosong.');
            self::assertStringStartsWith('$2y$', (string) $barisBaru['susrPassword']);

            // 2. Update grup pengguna harus ikut menyinkronkan tabel s_user_group_user
            $resUpdateGrup = $this->withSession(['logged_in' => $this->sesiAdmin($adminUji)])
                ->withBodyFormat('form')
                ->post('pengguna/save', [
                    csrf_token()     => csrf_hash(),
                    'susrNamaOld'    => $userBaru,
                    'susrNama'       => $userBaru,
                    'susrSgroupNama' => $grup2,
                    'susrProfil'     => 'Pegawai Baru M13 Diubah',
                ]);
            $jsonUpdateGrup = json_decode($resUpdateGrup->getJSON(), true);
            self::assertSame('success', $jsonUpdateGrup['status'] ?? null);

            $adaGrup2 = $this->koneksi->table('s_user_group_user')
                ->where('sgroupSusrNama', $userBaru)
                ->where('sgroupSgroupNama', $grup2)
                ->countAllResults();
            self::assertSame(1, $adaGrup2, 'Perubahan grup pada Pengguna::save() wajib disinkronkan ke s_user_group_user.');

            // 3. Rename susrNama ke username milik orang lain harus ditolak dengan elegan
            $resRenameDuplikat = $this->withSession(['logged_in' => $this->sesiAdmin($adminUji)])
                ->withBodyFormat('form')
                ->post('pengguna/save', [
                    csrf_token()     => csrf_hash(),
                    'susrNamaOld'    => $userBaru,
                    'susrNama'       => $userLain,
                    'susrSgroupNama' => $grup2,
                    'susrProfil'     => 'Coba Tabrak Username',
                ]);
            $jsonRename = json_decode($resRenameDuplikat->getJSON(), true);
            self::assertSame('error', $jsonRename['status'] ?? null, 'Rename username ke akun lain yang sudah ada wajib ditolak validasi.');

            // 4. Menghapus akun diri sendiri yang sedang login harus ditolak
            $kunciDiriSendiri = $this->enkripsi->encode($adminUji);
            $resSelfDelete = $this->withSession(['logged_in' => $this->sesiAdmin($adminUji)])
                ->withBodyFormat('form')
                ->post('pengguna/delete/' . $kunciDiriSendiri, [
                    csrf_token() => csrf_hash(),
                ]);
            $jsonSelfDelete = json_decode($resSelfDelete->getJSON(), true);
            self::assertSame('error', $jsonSelfDelete['status'] ?? null, 'Pengguna tidak boleh menghapus akunnya sendiri yang sedang aktif login.');

            $masihAdaAdmin = $this->koneksi->table('s_user')->where('susrNama', $adminUji)->countAllResults();
            self::assertSame(1, $masihAdaAdmin);
        } finally {
            $this->koneksi->transRollback();
            $this->koneksi->resetTransStatus();
        }
    }

    // =========================================================================
    // [M-14] Pembersihan Lengkap Ticketing::delete() & Transaksi Hakakses*
    // =========================================================================

    public function testM14TicketingDeleteMembersihkanTabelTerkaitDanFileFisikSertaHakaksesSaveMemakaiTransaksi(): void
    {
        $idTiket      = 'ZZM14-DEL-001';
        $namaFileChat = 'CHAT_' . $idTiket . '_20261020100000.pdf';
        $pathChat     = WRITEPATH . 'uploads/chat/' . $namaFileChat;
        $pathQr1      = WRITEPATH . 'uploads/qrcode/' . $idTiket . '.png';
        $pathQr2      = WRITEPATH . 'uploads/qrcode/TTD_' . $idTiket . '.png';

        file_put_contents($pathChat, '%PDF-1.4 CHAT M14');
        file_put_contents($pathQr1, 'PNG-QR1-M14');
        file_put_contents($pathQr2, 'PNG-QR2-M14');

        $this->koneksi->transBegin();

        try {
            $this->pastikanIzinModul('ADMIN', 'ticketing');
            $userAda = $this->koneksi->table('s_user')->select('susrNama')->limit(1)->get()->getRowArray();
            $namaWorker = (string) ($userAda['susrNama'] ?? 'admin');

            $this->koneksi->table('d_ticketing')->insert([
                'ticketTrackingId' => $idTiket,
                'ticketName'       => 'Pemohon Hapus M14',
                'ticketEmail'      => 'm14@example.invalid',
                'ticketCategories' => $this->layananValidId,
                'ticketPriority'   => '1',
                'ticketStatus'     => 1,
                'ticketCreated'    => '2026-10-20 10:00:00',
            ]);
            $this->koneksi->table('r_surat')->insert([
                'suratTrackingId' => $idTiket,
                'suratNomor'      => '001/UN17/M14/2026',
            ]);
            $this->koneksi->table('d_rating')->insert([
                'ratingTicketId' => $idTiket,
                'ratingNilai'    => 5,
            ]);
            $this->koneksi->table('d_worker')->insert([
                'workerTrackingId' => $idTiket,
                'workerUser'       => $namaWorker,
            ]);
            $this->koneksi->table('d_replies')->insert([
                'repliesTicketId' => $idTiket,
                'repliesMessage'  => 'Lampiran chat untuk dihapus',
                'repliesDate'     => '2026-10-20 10:05:00',
                'repliesStatus'   => 'USER',
                'repliesBy'       => 'Pemohon',
                'repliesFile'     => $namaFileChat,
                'repliesRead'     => 0,
            ]);

            $kunci = $this->enkripsi->encode($idTiket);
            $hasil = $this->withSession(['logged_in' => $this->sesiAdmin()])
                ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
                ->withBodyFormat('form')
                ->post('ticketing/delete/' . $kunci, [
                    csrf_token() => csrf_hash(),
                ]);

            $json = json_decode($hasil->getJSON(), true);
            self::assertSame('success', $json['status'] ?? null);

            self::assertSame(0, $this->koneksi->table('r_surat')->where('suratTrackingId', $idTiket)->countAllResults(), 'Baris r_surat wajib ikut dihapus.');
            self::assertSame(0, $this->koneksi->table('d_rating')->where('ratingTicketId', $idTiket)->countAllResults(), 'Baris d_rating wajib ikut dihapus.');
            self::assertSame(0, $this->koneksi->table('d_worker')->where('workerTrackingId', $idTiket)->countAllResults(), 'Baris d_worker wajib ikut dihapus.');
            self::assertFileDoesNotExist($pathChat, 'File fisik lampiran chat wajib ikut dihapus.');
            self::assertFileDoesNotExist($pathQr1, 'File fisik QR tanda terima wajib ikut dihapus.');
            self::assertFileDoesNotExist($pathQr2, 'File fisik QR tanda tangan wajib ikut dihapus.');

            foreach (['Hakaksesmodul.php', 'Hakaksesunit.php', 'Hakaksespengguna.php', 'Hakakseslayanan.php'] as $ctrlFile) {
                $isiCtrl = (string) file_get_contents(APPPATH . 'Controllers/' . $ctrlFile);
                self::assertMatchesRegularExpression(
                    '/trans(Start|Begin)\s*\(/',
                    $isiCtrl,
                    "{$ctrlFile}::save() wajib membungkus hapus + tambah di dalam transaksi database."
                );
            }
        } finally {
            $this->koneksi->transRollback();
            $this->koneksi->resetTransStatus();
            @unlink($pathChat);
            @unlink($pathQr1);
            @unlink($pathQr2);
        }
    }
}
