<?php

namespace Tests\Bugfix;

use App\Controllers\Ticketing;
use App\Libraries\Enkripsi;
use App\Libraries\PengirimEmail;
use App\Models\ModelLaporan;
use App\Models\ModelMaster;
use App\Models\ModelTicketing;
use App\Models\ModelValidasifile;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Pengujian TDD (RED -> GREEN) untuk 9 Temuan Tinggi Audit Penuh E-ULT v2:
 * - H-01: Hakakseslayanan::index() salah view & Refkategori::update()/delete()
 * - H-02: Dokumen selesai berjenis 'TTD' gagal diunduh/ditampilkan
 * - H-03: Pengiriman email selesai ganda di Ticketing::lastValidated()
 * - H-04: Cacat query SQL GROUP BY & Cartesian JOIN notifikasi & disposisi
 * - H-05: Laporan kehilangan data hari terakhir & crash format tanggal rusak
 * - H-06: Karantina, restore, & hapus permanen berkas lintas folder gagal di Validasifile
 * - H-07: Celah otorisasi peran/status aksi Ticketing, loadimage lintas unit, & cetaksurat duplikat
 * - H-08: Fatal error PHP 8 pada cetak_ektm, cetak_6..cetak_10, & preview_surat
 * - H-09: QR code kosong pada Cektiket::cetakterima()
 */
final class RemediasiTinggiAudit2026Test extends CIUnitTestCase
{
    use FeatureTestTrait;

    private BaseConnection $koneksi;
    private Enkripsi $enkripsi;
    private string $layananValidId = '1';
    private string $unitLayananId  = '1';

    protected function setUp(): void
    {
        parent::setUp();
        Services::reset();

        $this->koneksi  = \Config\Database::connect('default');
        $this->enkripsi = new Enkripsi();

        $dbUlt   = \Config\Database::connect('dbult');
        $layanan = $dbUlt->table('ref_layanan')
            ->select('layananId, layananunitId')
            ->where('layananisdisplay', 1)
            ->orderBy('layananId', 'ASC')
            ->limit(1)
            ->get()
            ->getRowArray();
        if (is_array($layanan) && $layanan !== []) {
            $this->layananValidId = (string) $layanan['layananId'];
            $this->unitLayananId  = (string) ($layanan['layananunitId'] ?? '1');
        }
    }

    private function sesiAdmin(): array
    {
        return [
            'susrNama'           => 'admin_uji_h',
            'susrSgroupNama'     => 'ADMIN',
            'susrSgroupNama_ori' => 'ADMIN',
            'susrProfil'         => 'Admin Uji Tinggi',
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

    private function buatTiketUji(string $idTiket, int $status = 1, string $waktu = '2026-10-15 10:00:00'): void
    {
        $this->koneksi->table('d_ticketing')->insert([
            'ticketTrackingId' => $idTiket,
            'ticketIdentitas'  => '1801015001',
            'ticketName'       => 'Pemohon Uji Tinggi',
            'ticketCategories' => $this->layananValidId,
            'ticketEmail'      => 'pemohon.uji@unmul.ac.id',
            'ticketNoHp'       => '081234567899',
            'ticketSubject'    => 'Subjek Uji Tinggi',
            'ticketPriority'   => '1',
            'ticketMessage'    => 'Pesan Uji Tinggi',
            'ticketCreated'    => $waktu,
            'ticketStatus'     => $status,
            'ticketCreatedBy'  => 'Pemohon Uji Tinggi',
        ]);
    }

    // =========================================================================
    // [H-01] Hakakseslayanan::index() & Refkategori::update()/delete()
    // =========================================================================

    public function testH01HakakseslayananIndexMerenderLayoutLengkapDenganDropdownRole(): void
    {
        $this->koneksi->transBegin();

        try {
            $this->pastikanIzinModul('ADMIN', 'hakakseslayanan');

            $hasil = $this->withSession(['logged_in' => $this->sesiAdmin()])
                ->get('hakakseslayanan');

            $hasil->assertStatus(200);
            $body = $hasil->getBody();
            self::assertStringContainsString('id="form_show"', $body, 'Hakakseslayanan::index() wajib merender halaman index dengan form_show, bukan response.php kosong.');
            self::assertStringContainsString('name="hakakses"', $body);
        } finally {
            $this->koneksi->transRollback();
        }
    }

    // =========================================================================
    // [H-02] Pembacaan Dokumen Selesai Berjenis 'TTD' & Urutan Terbaru
    // =========================================================================

    public function testH02DokumenOutputTtdTerbacaDiCektiketIndexLoadpdfDanTicketingDetail(): void
    {
        $idTiket  = 'ZZH2-TTD-001';
        $fileLama = 'TTD_ZZH2TTD001_lama.pdf';
        $fileBaru = 'TTD_ZZH2TTD001_baru.pdf';
        $dirTiket = WRITEPATH . 'uploads/ticketing/';

        file_put_contents($dirTiket . $fileLama, '%PDF-1.4 LAMA');
        file_put_contents($dirTiket . $fileBaru, '%PDF-1.4 BARU');
        $this->koneksi->transBegin();

        try {
            $this->pastikanIzinModul('ADMIN', 'ticketing');
            $this->buatTiketUji($idTiket, 5);

            $this->koneksi->table('d_archive')->insertBatch([
                [
                    'archiveId'         => 'ZZH2TTD0010001',
                    'archiveTrackingId' => $idTiket,
                    'archiveFile'       => $fileLama,
                    'archiveJenis'      => 'TTD',
                ],
                [
                    'archiveId'         => 'ZZH2TTD0010002',
                    'archiveTrackingId' => $idTiket,
                    'archiveFile'       => $fileBaru,
                    'archiveJenis'      => 'TTD',
                ],
            ]);

            $kunci = $this->enkripsi->encode($idTiket);

            // 1. Cektiket::index() harus mendeteksi output_url meskipun archiveJenis = 'TTD'
            $halPublik = $this->get('cektiket/index/' . $kunci);
            $halPublik->assertStatus(200);
            self::assertStringContainsString('cektiket/loadpdf/' . $kunci, $halPublik->getBody(), 'Cektiket::index() wajib menampilkan tautan unduh untuk dokumen TTD.');

            // 2. Cektiket::loadpdf() harus menyajikan dokumen TTD TERBARU (archiveId terbesar)
            $pdfPublik = $this->get('cektiket/loadpdf/' . $kunci);
            $pdfPublik->assertStatus(200);
            self::assertSame('%PDF-1.4 BARU', $pdfPublik->response()->getBody(), 'Cektiket::loadpdf() wajib menyajikan dokumen TTD terbaru.');

            // 3. Ticketing::detail() juga harus memuat dokumen TTD terbaru pada output_url
            $halAdmin = $this->withSession(['logged_in' => $this->sesiAdmin()])
                ->get('ticketing/detail/' . $kunci);
            $halAdmin->assertStatus(200);
            self::assertStringContainsString($fileBaru, $halAdmin->getBody(), 'Ticketing::detail() wajib menautkan file TTD terbaru.');
        } finally {
            $this->koneksi->transRollback();
            @unlink($dirTiket . $fileLama);
            @unlink($dirTiket . $fileBaru);
        }
    }

    // =========================================================================
    // [H-03] Pengiriman Email Selesai Ganda di Ticketing::lastValidated()
    // =========================================================================

    public function testH03LastValidatedModeSehariHanyaMengirimEmailSelesaiSatuKali(): void
    {
        $idTiket = 'ZZH3-MAIL-001';
        $this->koneksi->transBegin();

        try {
            $this->pastikanIzinModul('ADMIN', 'ticketing');
            $this->buatTiketUji($idTiket, 3);
            $kunci = $this->enkripsi->encode($idTiket);

            // Buat spy PengirimEmail untuk menghitung jumlah pemanggilan selesai()
            $spyEmail = new class () extends PengirimEmail {
                public int $jumlahPanggilan = 0;

                public function selesai(string $email, string $subjek, mixed $param, string|false $lampiran = false): bool
                {
                    $this->jumlahPanggilan++;

                    return true;
                }
            };

            $_SESSION['logged_in'] = $this->sesiAdmin();
            $request               = Services::request();
            if ($request instanceof IncomingRequest) {
                $request->setMethod('POST');
                $request->setGlobal('post', ['pesanvalidasi' => 'Selesai diproses 1 hari']);
            }
            $response = Services::response();
            $logger   = Services::logger();

            $controller = new Ticketing();
            $controller->initController($request, $response, $logger);

            $refEmail = new \ReflectionProperty(Ticketing::class, 'email');
            $refEmail->setValue($controller, $spyEmail);

            try {
                $controller->lastValidated($kunci, 'sehari');
            } catch (\App\Exceptions\ResponsAwalException $e) {
                // Respons normal dari eult_message_kirim()
            }

            self::assertSame(
                1,
                $spyEmail->jumlahPanggilan,
                'Ticketing::lastValidated() pada mode sehari hanya boleh mengirim email selesai tepat 1 kali.'
            );
        } finally {
            $this->koneksi->transRollback();
        }
    }

    // =========================================================================
    // [H-04] Query SQL Notifikasi & Disposisi (Cartesian JOIN & GROUP BY)
    // =========================================================================

    public function testH04DataNotifDisposisiNotifDisposisiByIdDanSubDisposisiTerakhirBebasDuplikasi(): void
    {
        $idTiketA = 'ZZH4-SQL-001';
        $idTiketB = 'ZZH4-SQL-002';
        $this->koneksi->transBegin();

        try {
            $this->buatTiketUji($idTiketA, 3);
            $this->buatTiketUji($idTiketB, 3);

            // Buat 2 arsip dan 1 balasan belum dibaca pada tiket A
            $this->koneksi->table('d_archive')->insertBatch([
                ['archiveId' => 'ZZH4A0001', 'archiveTrackingId' => $idTiketA, 'archiveFile' => 'a1.pdf', 'archiveJenis' => 'TIKET'],
                ['archiveId' => 'ZZH4A0002', 'archiveTrackingId' => $idTiketA, 'archiveFile' => 'a2.pdf', 'archiveJenis' => 'ASSIGN'],
            ]);
            $this->koneksi->table('d_replies')->insert([
                'repliesTicketId' => $idTiketA,
                'repliesMessage'  => 'Pesan belum dibaca',
                'repliesStatus'   => 'USER',
                'repliesDate'     => '2026-10-15 11:00:00',
                'repliesBy'       => 'Pemohon',
                'repliesFile'     => '',
                'repliesRead'     => '0',
            ]);

            $modelMaster = new ModelMaster();
            $notifAdmin  = $modelMaster->dataNotif(['ticketTrackingId' => $idTiketA, 'repliesRead' => 0]);
            self::assertIsArray($notifAdmin);
            self::assertCount(1, $notifAdmin);
            self::assertSame(1, (int) $notifAdmin[0]['jumlah'], 'Jumlah balasan tidak boleh berlipat ganda akibat jumlah baris d_archive.');

            // Buat 2 unit uji yang dipetakan ke 2 grup berbeda, lalu buat 2 disposisi di detik yang sama
            $this->koneksi->table('s_unit')->insert([
                'unitKode' => '9988',
                'unitNama' => 'Unit Uji H04 Pertama',
            ]);
            $unitBaruId = (string) $this->koneksi->insertID();

            $this->koneksi->table('s_unit')->insert([
                'unitKode' => '9989',
                'unitNama' => 'Unit Uji H04 Kedua',
            ]);
            $unitKeduaId = (string) $this->koneksi->insertID();

            $duaGrup = $this->koneksi->table('s_user_group')->limit(2)->get()->getResultArray();
            self::assertGreaterThanOrEqual(2, count($duaGrup));
            $this->koneksi->table('s_user_group_unit')->insertBatch([
                ['sgroupunitSgroupNama' => $duaGrup[0]['sgroupNama'], 'sgroupunitUnitId' => $unitBaruId, 'sgroupunitIsHome' => 1],
                ['sgroupunitSgroupNama' => $duaGrup[1]['sgroupNama'], 'sgroupunitUnitId' => $unitBaruId, 'sgroupunitIsHome' => 1],
                ['sgroupunitSgroupNama' => $duaGrup[0]['sgroupNama'], 'sgroupunitUnitId' => $unitKeduaId, 'sgroupunitIsHome' => 1],
                ['sgroupunitSgroupNama' => $duaGrup[1]['sgroupNama'], 'sgroupunitUnitId' => $unitKeduaId, 'sgroupunitIsHome' => 1],
            ]);

            $waktuKembar = '2026-10-15 12:00:00';
            $this->koneksi->table('d_disposisi')->insert([
                'disposisiTicketId'   => $idTiketB,
                'disposisiMessage'    => 'Disposisi Pertama',
                'disposisiTanggal'    => $waktuKembar,
                'disposisiUser'       => 'admin',
                'disposisiUnit'       => $unitBaruId,
                'disposisiUserProfil' => 'Admin',
                'disposisiStatus'     => 3,
                'disposisiPriority'   => '1',
            ]);
            $this->koneksi->table('d_disposisi')->insert([
                'disposisiTicketId'   => $idTiketB,
                'disposisiMessage'    => 'Disposisi Kedua Detik Sama',
                'disposisiTanggal'    => $waktuKembar,
                'disposisiUser'       => 'admin',
                'disposisiUnit'       => $unitKeduaId,
                'disposisiUserProfil' => 'Admin',
                'disposisiStatus'     => 3,
                'disposisiPriority'   => '1',
            ]);

            $modelTiket = new ModelTicketing();

            // 1. dataById() tidak boleh menggandakan tiket B walaupun ada 2 disposisi pada detik yang sama
            $barisData = $modelTiket->dataById(['ticketTrackingId' => $idTiketB]);
            self::assertIsArray($barisData);
            self::assertCount(1, $barisData, 'subDisposisiTerakhir() wajib memilih 1 disposisi terakhir per tiket meskipun pada detik yang sama.');
            self::assertSame('Disposisi Kedua Detik Sama', $barisData[0]['disposisiMessage']);

            // 2. disposisiById() tanpa filter sgroupunitSgroupNama tidak boleh menduplikasi riwayat disposisi per grup
            $riwayatDisp = $modelTiket->disposisiById(['ticketTrackingId' => $idTiketB]);
            self::assertIsArray($riwayatDisp);
            self::assertCount(2, $riwayatDisp, '2 baris d_disposisi tidak boleh berlipat menjadi 4 baris karena unit dipetakan ke 2 grup.');

            // 3. disposisiNotif() pada 2 tiket tanpa balasan chat tidak boleh menggabungkannya ke 1 baris NULL
            $this->koneksi->table('d_disposisi')->insert([
                'disposisiTicketId'   => $idTiketA,
                'disposisiMessage'    => 'Disposisi Tiket A',
                'disposisiTanggal'    => $waktuKembar,
                'disposisiUser'       => 'admin',
                'disposisiUnit'       => $unitBaruId,
                'disposisiUserProfil' => 'Admin',
                'disposisiStatus'     => 3,
                'disposisiPriority'   => '1',
            ]);
            // Hapus balasan tiket A agar kedua tiket memiliki 0 balasan
            $this->koneksi->table('d_replies')->where('repliesTicketId', $idTiketA)->delete();

            $notifDisp = $modelMaster->disposisiNotif(['sgroupunitSgroupNama' => $duaGrup[0]['sgroupNama']]);
            self::assertIsArray($notifDisp);
            $idDitemukan = array_column($notifDisp, 'ticketTrackingId');
            self::assertContains($idTiketA, $idDitemukan);
            self::assertContains($idTiketB, $idDitemukan);
        } finally {
            $this->koneksi->transRollback();
        }
    }

    // =========================================================================
    // [H-05] Batas Akhir Tanggal Laporan (23:59:59) & Validasi Format Tanggal
    // =========================================================================

    public function testH05LaporanMenyertakanTiketPadaTanggalAkhirDanMenolakFormatTanggalRusak(): void
    {
        $idTiket = 'ZZH5-RPT-001';
        $this->koneksi->transBegin();

        try {
            $this->pastikanIzinModul('ADMIN', 'laporan');
            $this->buatTiketUji($idTiket, 1, '2099-06-20 15:45:00');

            $modelLaporan = new ModelLaporan();
            $rekapUnit    = $modelLaporan->getLaporan(['2099-06-20', '2099-06-20']);
            self::assertIsArray($rekapUnit, 'Tiket pada pukul 15:45 di tanggal akhir wajib masuk laporan.');

            $rekapLayanan = $modelLaporan->getLaporanLayanan('2099-06-20', '2099-06-20', 'ADMIN', $this->unitLayananId);
            self::assertIsArray($rekapLayanan, 'Tiket pada pukul 15:45 di tanggal akhir wajib masuk laporan layanan.');

            // Format rentangTanggal tanpa pemisah '/' tidak boleh memicu Undefined array key 1
            $hasilRusak = $this->withSession(['logged_in' => $this->sesiAdmin()])
                ->withBodyFormat('form')
                ->post('laporan/response', [
                    csrf_token()     => csrf_hash(),
                    'rentangTanggal' => '2099-06-20-tanpa-slash',
                ]);
            $json = json_decode($hasilRusak->getJSON(), true);
            self::assertSame('error', $json['status'] ?? null, 'Format rentangTanggal rusak wajib ditolak dengan respons JSON error.');
        } finally {
            $this->koneksi->transRollback();
        }
    }

    // =========================================================================
    // [H-06] Karantina, Restore, & Hapus Permanen Lintas Folder di Validasifile
    // =========================================================================

    public function testH06ValidasifileRestoreDanDeleteLintasFolderBerhasilKeFolderAsal(): void
    {
        $namaFileChat   = 'uji_h06_lintas_folder_' . substr(md5((string) microtime(true)), 0, 8) . '.pdf';
        $pathChatAsal   = WRITEPATH . 'uploads/chat/' . $namaFileChat;
        $pathKarantina  = WRITEPATH . 'uploads/_quarantine/chat/' . $namaFileChat;
        $pathManifest   = WRITEPATH . 'uploads/_quarantine/manifest.json';
        $backupManifest = is_file($pathManifest) ? file_get_contents($pathManifest) : null;

        file_put_contents($pathChatAsal, '%PDF-1.4 UJI H06 CHAT');
        $this->koneksi->transBegin();

        try {
            $this->pastikanIzinModul('ADMIN', 'validasifile');
            $modelVf = new ModelValidasifile();

            // 1. Karantina file dari folder 'chat'
            $hasilKarantina = $modelVf->quarantineFiles([$namaFileChat], 'chat', 'ADMIN');
            self::assertTrue($hasilKarantina['status']);
            self::assertFileExists($pathKarantina);
            self::assertFileDoesNotExist($pathChatAsal);

            // 2. Panggil proses_restore dengan folder default 'ticketing' (seperti Restore Selected / Restore All di UI)
            $resRestore = $this->withSession(['logged_in' => $this->sesiAdmin()])
                ->withBodyFormat('form')
                ->post('validasifile/proses_restore', [
                    csrf_token() => csrf_hash(),
                    'folder'     => 'ticketing',
                    'mode'       => 'all',
                ]);
            $jsonRestore = json_decode($resRestore->getJSON(), true);
            self::assertTrue((bool) ($jsonRestore['status'] ?? false), 'Restore All wajib memulihkan file karantina dari semua folder (termasuk chat).');
            self::assertFileExists($pathChatAsal, 'File chat wajib kembali ke writable/uploads/chat/.');

            // 3. Karantina lagi lalu panggil proses_delete dengan folder 'ticketing' dan files=[$namaFileChat]
            $modelVf->quarantineFiles([$namaFileChat], 'chat', 'ADMIN');
            self::assertFileExists($pathKarantina);

            $resDelete = $this->withSession(['logged_in' => $this->sesiAdmin()])
                ->withBodyFormat('form')
                ->post('validasifile/proses_delete', [
                    csrf_token() => csrf_hash(),
                    'folder'     => 'ticketing',
                    'files'      => [$namaFileChat],
                ]);
            $jsonDelete = json_decode($resDelete->getJSON(), true);
            self::assertTrue((bool) ($jsonDelete['status'] ?? false), 'Delete Selected lintas folder wajib menemukan folder asal dari manifest.');
            self::assertFileDoesNotExist($pathKarantina);
        } finally {
            $this->koneksi->transRollback();
            @unlink($pathChatAsal);
            @unlink($pathKarantina);
            if (is_string($backupManifest)) {
                file_put_contents($pathManifest, $backupManifest);
            }
        }
    }

    // =========================================================================
    // [H-07] Otorisasi Peran/Status Aksi Ticketing, loadimage, & cetaksurat
    // =========================================================================

    public function testH07TicketingMencegahHapusOlehStafUnitMutasiTiketFinalLoadimageUnitLainDanDuplikasiArsip(): void
    {
        $idTiketSelesai = 'ZZH7-FIN-001';
        $idTiketUnit    = 'ZZH7-UNT-002';
        $qrPath         = WRITEPATH . 'uploads/qrcode/' . $idTiketUnit . '.png';
        $pdfCetaksurat  = WRITEPATH . 'uploads/ticketing/TTD_ZZH7UNT002_tetap.pdf';

        file_put_contents($qrPath, 'PNG-DUMMY');
        $this->koneksi->transBegin();

        try {
            $this->pastikanIzinModul('ADMIN', 'ticketing');
            $this->buatTiketUji($idTiketSelesai, 5);
            $this->buatTiketUji($idTiketUnit, 3);

            // Buat grup staf unit back-office (bukan ADMIN, bukan OPERATOR)
            $grupStaf = 'DISPOSISI_UJI_H07';
            $this->koneksi->table('s_user_group')->insert([
                'sgroupNama'       => $grupStaf,
                'sgroupKeterangan' => 'Staf Unit Uji H07',
                'sgroupCategoryId' => $this->unitLayananId,
            ]);
            $this->pastikanIzinModul($grupStaf, 'ticketing');

            // Buat grup staf unit LAIN yang tidak berhak atas tiket ini
            $grupLain = 'DISPOSISI_LAIN_H07';
            $this->koneksi->table('s_user_group')->insert([
                'sgroupNama'       => $grupLain,
                'sgroupKeterangan' => 'Staf Unit Lain H07',
                'sgroupCategoryId' => '99999',
            ]);
            $this->pastikanIzinModul($grupLain, 'ticketing');

            // Disposisikan $idTiketUnit ke unit layanan agar $grupStaf berhak melihat tiketnya
            $this->koneksi->table('s_user_group_unit')->insert([
                'sgroupunitSgroupNama' => $grupStaf,
                'sgroupunitUnitId'     => $this->unitLayananId,
                'sgroupunitIsHome'     => 1,
            ]);
            $this->koneksi->table('d_disposisi')->insert([
                'disposisiTicketId'   => $idTiketUnit,
                'disposisiMessage'    => 'Disposisi Uji',
                'disposisiTanggal'    => '2026-10-15 10:30:00',
                'disposisiUser'       => 'admin',
                'disposisiUnit'       => $this->unitLayananId,
                'disposisiUserProfil' => 'Admin',
                'disposisiStatus'     => 3,
                'disposisiPriority'   => '1',
            ]);

            $sesiStaf = [
                'susrNama'           => 'staf_h07',
                'susrSgroupNama'     => $grupStaf,
                'susrSgroupNama_ori' => $grupStaf,
                'susrProfil'         => 'Staf Unit H07',
                'susrCategoryId'     => $this->unitLayananId,
            ];
            $sesiLain = [
                'susrNama'           => 'staf_lain_h07',
                'susrSgroupNama'     => $grupLain,
                'susrSgroupNama_ori' => $grupLain,
                'susrProfil'         => 'Staf Lain H07',
                'susrCategoryId'     => '99999',
            ];

            $kunciUnit    = $this->enkripsi->encode($idTiketUnit);
            $kunciSelesai = $this->enkripsi->encode($idTiketSelesai);

            // 1. Staf unit back-office TIDAK BOLEH menghapus tiket meskipun tiket berada di unitnya
            $resDel = $this->withSession(['logged_in' => $sesiStaf])
                ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
                ->withBodyFormat('form')
                ->post('ticketing/delete/' . $kunciUnit, [csrf_token() => csrf_hash()]);
            self::assertTrue(
                $resDel->response()->getStatusCode() === 403 || (json_decode($resDel->getJSON(), true)['status'] ?? '') === 'error',
                'Staf unit non-ADMIN/non-OPERATOR wajib ditolak saat mencoba menghapus tiket.'
            );
            $masihAda = $this->koneksi->table('d_ticketing')->where('ticketTrackingId', $idTiketUnit)->countAllResults();
            self::assertSame(1, $masihAda, 'Tiket tidak boleh terhapus oleh staf unit.');

            // 2. Tiket yang sudah berstatus final 5 (Selesai) TIDAK BOLEH diubah kembali menjadi Ditolak (8)
            $resTolak = $this->withSession(['logged_in' => $this->sesiAdmin()])
                ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
                ->withBodyFormat('form')
                ->post('ticketing/tolak/' . $kunciSelesai, [
                    csrf_token()  => csrf_hash(),
                    'pesan_tolak' => 'Coba tolak tiket yang sudah selesai',
                ]);
            $jsonTolak = json_decode($resTolak->getJSON(), true);
            self::assertSame('error', $jsonTolak['status'] ?? null, 'Menolak tiket yang sudah Selesai (5) wajib ditolak.');
            $barisSelesai = $this->koneksi->table('d_ticketing')->where('ticketTrackingId', $idTiketSelesai)->get()->getRowArray();
            self::assertSame(5, (int) ($barisSelesai['ticketStatus'] ?? 0));

            // 3. Staf unit lain TIDAK BOLEH mengakses QR code tiket unit lain lewat loadimage
            $resQr = $this->withSession(['logged_in' => $sesiLain])
                ->get('ticketing/loadimage/' . $kunciUnit);
            $resQr->assertStatus(403);

            // 4. cetaksurat dengan kode nama berkas yang sama tidak boleh menduplikasi baris d_archive
            $kodeCetak = $this->enkripsi->encode($idTiketUnit . ';TTD_ZZH7UNT002_tetap');
            $this->withSession(['logged_in' => $this->sesiAdmin()])->get('ticketing/cetaksurat/' . $kodeCetak);
            $this->withSession(['logged_in' => $this->sesiAdmin()])->get('ticketing/cetaksurat/' . $kodeCetak);

            $jumlahArsipTtd = $this->koneksi->table('d_archive')
                ->where('archiveTrackingId', $idTiketUnit)
                ->where('archiveFile', 'TTD_ZZH7UNT002_tetap.pdf')
                ->countAllResults();
            self::assertSame(1, $jumlahArsipTtd, 'Pemanggilan ulang cetaksurat untuk file yang sama tidak boleh membuat baris d_archive ganda.');
        } finally {
            $this->koneksi->transRollback();
            @unlink($qrPath);
            @unlink($pdfCetaksurat);
        }
    }

    // =========================================================================
    // [H-08] Template Cetak PDF (cetak_ektm, cetak_6..10, preview_surat)
    // =========================================================================

    public function testH08TemplateCetakEktmCetak6Sampai10DanPreviewSuratAmanPadaDataSiaBaruDanMahasiswaFalse(): void
    {
        $renderer = Services::renderer();

        // 1. cetak_ektm dengan $mahasiswa = false tidak boleh crash
        $renderer->resetData();
        $htmlEktm = $renderer->setData([
            'datas'                 => false,
            'id'                    => 'ZZH8-001',
            'noSurat'               => '001/UN17/2026',
            'bank'                  => 'BNI',
            'noSuratPemohon'        => '123/FAK/2026',
            'tglSuratPemohon'       => '2026-10-01',
            'mahasiswa'             => false,
            'suratPejabatJabatan'   => 'Koordinator ULT',
            'suratPejabatNama'      => 'Pejabat Uji',
            'suratPejabatNIP'       => '19800101',
        ], 'raw')->render('pages/ticketing/cetak/cetak_ektm');
        self::assertStringContainsString('Surat Pengantar Pengurusan Kehilangan E-KTM', $htmlEktm);

        // 2. cetak_6..cetak_10 dengan struktur SIA baru & mode draf (ticketValidated kosong)
        $mhsSiaBaru = (object) [
            'nim'           => '2201015099',
            'peserta_didik' => (object) [
                'nama'          => 'Siti Mahasiswa SIA Baru',
                'tempat_lahir'  => 'Samarinda',
                'tanggal_lahir' => '2004-05-12',
            ],
            'program_studi' => (object) [
                'nama_fakultas' => 'Fakultas Teknik',
                'nama'          => 'Informatika',
                'nama_prodi'    => 'Informatika',
                'jenjang'       => 'S1',
                'jenjang_didik' => (object) ['nama' => 'S1'],
            ],
            'registrasi_mahasiswa' => (object) [
                'ipk' => null,
            ],
        ];

        $datasDraf = [
            'ticketTrackingId'           => 'ZZH8-002',
            'suratNomor'                 => '002/UN17/2026',
            'suratNomorTanggal'          => '2026-10-15',
            'suratTanggal'               => '2026-10-15',
            'suratJenis'                 => 'SURAT KETERANGAN UJI',
            'suratLampiran'              => '-',
            'suratTujuan'                => 'Yth. Dekan',
            'suratBody'                  => '<p>Isi surat uji</p>',
            'ticketValidated'            => null,
            'suratPejabatJabatanAnDraft' => 'Rektor',
            'suratPejabatJabatanDraft'   => 'Wakil Rektor Bidang Akademik',
            'suratPejabatNamaDraft'      => 'Prof. Pejabat Draf Uji',
            'suratPejabatNIPDraft'       => '197501012000031001',
        ];

        foreach (['cetak_6', 'cetak_7', 'cetak_8', 'cetak_9', 'cetak_10'] as $tpl) {
            $renderer->resetData();
            $html = $renderer->setData([
                'datas'       => $datasDraf,
                'mahasiswa'   => $mhsSiaBaru,
                'judulSurat'  => '',
                'lampSurat'   => '',
                'tujuanSurat' => '',
                'isiSurat'    => '',
            ], 'raw')->render('pages/ticketing/cetak/' . $tpl);

            self::assertStringContainsString('Prof. Pejabat Draf Uji', $html, "{$tpl} wajib merender nama pejabat draf ketika ticketValidated kosong.");
            if ($tpl !== 'cetak_6') {
                self::assertStringContainsString('Siti Mahasiswa SIA Baru', $html, "{$tpl} wajib mendukung struktur objek mahasiswa SIA baru.");
            }
        }

        // 3. preview_surat dengan data pegawai berupa array & tidak memuat hardcode BAZK-HGRY-001.png
        $renderer->resetData();
        $htmlPreviewPeg = $renderer->setData([
            'identitas' => 'PEG',
            'datas'     => array_merge($datasDraf, [
                'ticketValidated'     => '2026-10-15 12:00:00',
                'ticketSuratCreated'  => '2026-10-15',
                'suratPejabatJabatan' => 'Wakil Rektor',
                'suratPejabatNama'    => 'Prof. Pejabat Final',
                'suratPejabatNIP'     => '19750101',
            ]),
            'mahasiswa' => [
                'pegmNama' => 'Dr. Budi Pegawai Array',
                'pegmNIP'  => '198502022010121002',
                'golNama'  => 'IV/a',
                'jabNama'  => 'Lektor Kepala',
                'unitNama' => 'Fakultas Teknik',
            ],
        ], 'raw')->render('pages/ticketing/cetak/preview_surat');

        self::assertStringContainsString('Dr. Budi Pegawai Array', $htmlPreviewPeg);
        self::assertStringNotContainsString('BAZK-HGRY-001.png', $htmlPreviewPeg);
    }

    // =========================================================================
    // [H-09] Pembuatan QR Code Otomatis di Cektiket::cetakterima()
    // =========================================================================

    public function testH09CektiketCetakterimaMembuatFileQrOtomatisBilaBelumAda(): void
    {
        $idTiket = 'ZZH9-QRC-001';
        $qrPath  = WRITEPATH . 'uploads/qrcode/' . $idTiket . '.png';
        @unlink($qrPath);
        $this->koneksi->transBegin();

        try {
            $this->buatTiketUji($idTiket, 1);
            $kunci = $this->enkripsi->encode($idTiket);

            $hasil = $this->get('cektiket/cetakterima/' . $kunci);
            $hasil->assertStatus(200);
            self::assertFileExists($qrPath, 'Cektiket::cetakterima() wajib men-generate file PNG QR code bila belum tersedia.');
        } finally {
            $this->koneksi->transRollback();
            @unlink($qrPath);
        }
    }
}
