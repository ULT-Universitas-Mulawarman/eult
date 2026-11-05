<?php

use App\Libraries\KebijakanTiket;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Kebijakan aksi tiket per peran dan per unit (tanpa DB).
 *
 * Regresi: tombol "Verifikasi Berkas" (terima) di halaman detail tampil
 * untuk semua peran tanpa cek unit, dan method terima() tidak memeriksa
 * peran/unit — sehingga tiket yang bukan unitnya pun bisa diterima.
 * Aturan di sini mencerminkan getaction versi awal: OPERATOR tidak punya
 * hak terima, KASUBBAG/VERIFIKATOR hanya untuk tiket home unit-nya yang
 * berjenis BOTTOMUP dan belum terverifikasi.
 *
 * @internal
 */
final class KebijakanTiketTest extends CIUnitTestCase
{
    /**
     * Baris tiket uji (kunci sama seperti hasil ModelTicketing::byId()).
     *
     * @param array<string, mixed> $ubah
     *
     * @return array<string, mixed>
     */
    private function tiket(array $ubah = []): array
    {
        return array_merge([
            'ticketAssign'     => 'U01',
            'ticketIsVerified' => 0,
            'ticketStatus'     => 3,
            'ticketSuratCreated' => null,
            'sCatDisposisi'    => 'BOTTOMUP',
            'jenislayananId'   => 2,
            'disposisiIsTrue'  => 0,
        ], $ubah);
    }

    public function testTerimaHanyaKasubbagHomeBottomupBelumVerifikasi(): void
    {
        $tiket = $this->tiket();

        // Kasus yang dilaporkan: operator (bukan unit) tidak boleh terima.
        $this->assertFalse(KebijakanTiket::bolehTerima($tiket, 'OPERATOR AKADEMIK', ['U01']));
        $this->assertFalse(KebijakanTiket::bolehTerima($tiket, 'OPERATOR', []));

        // Kasubbag home unit + BOTTOMUP + belum verifikasi: boleh.
        $this->assertTrue(KebijakanTiket::bolehTerima($tiket, 'KASUBBAG AKADEMIK', ['U01']));
        $this->assertTrue(KebijakanTiket::bolehTerima($tiket, 'VERIFIKATOR AKADEMIK', ['U01']));

        // Bukan home unit: tolak walau peran benar.
        $this->assertFalse(KebijakanTiket::bolehTerima($tiket, 'KASUBBAG AKADEMIK', ['U99']));
        $this->assertFalse(KebijakanTiket::bolehTerima($tiket, 'KASUBBAG AKADEMIK', []));

        // Sudah terverifikasi / bukan BOTTOMUP: tolak.
        $this->assertFalse(KebijakanTiket::bolehTerima($this->tiket(['ticketIsVerified' => 1]), 'KASUBBAG AKADEMIK', ['U01']));
        $this->assertFalse(KebijakanTiket::bolehTerima($this->tiket(['sCatDisposisi' => 'TOPDOWN']), 'KASUBBAG AKADEMIK', ['U01']));

        // ADMIN bypass.
        $this->assertTrue(KebijakanTiket::bolehTerima($tiket, 'ADMIN', []));
    }

    public function testTolakMengikutiPeranDanUnit(): void
    {
        // Operator front-office hanya boleh tolak saat status 1 (triage).
        $this->assertTrue(KebijakanTiket::bolehTolak($this->tiket(['ticketStatus' => 1]), 'OPERATOR AKADEMIK', []));
        $this->assertFalse(KebijakanTiket::bolehTolak($this->tiket(['ticketStatus' => 3]), 'OPERATOR AKADEMIK', []));

        // Kasubbag: hanya home unit + (BOTTOMUP belum verifikasi / layanan sehari==2).
        $this->assertTrue(KebijakanTiket::bolehTolak($this->tiket(), 'KASUBBAG AKADEMIK', ['U01']));
        $this->assertFalse(KebijakanTiket::bolehTolak($this->tiket(), 'KASUBBAG AKADEMIK', ['U99']));

        $this->assertTrue(KebijakanTiket::bolehTolak($this->tiket(), 'ADMIN', []));
    }

    public function testAssignHanyaOperatorStatusSatuSehariDua(): void
    {
        $baru = $this->tiket(['ticketStatus' => 1, 'jenislayananId' => 2]);

        $this->assertTrue(KebijakanTiket::bolehAssign($baru, 'OPERATOR AKADEMIK'));
        $this->assertFalse(KebijakanTiket::bolehAssign($this->tiket(['ticketStatus' => 3]), 'OPERATOR AKADEMIK'));
        $this->assertFalse(KebijakanTiket::bolehAssign($baru, 'KASUBBAG AKADEMIK'));
        $this->assertTrue(KebijakanTiket::bolehAssign($baru, 'ADMIN'));
    }

    public function testAcceptHanyaDisposisiAtauVerifikatorTerverifikasi(): void
    {
        $siap = $this->tiket(['ticketIsVerified' => 1, 'ticketSuratCreated' => '2026-01-01 10:00:00']);

        $this->assertTrue(KebijakanTiket::bolehAccept($siap, 'DISPOSISI AKADEMIK'));
        $this->assertTrue(KebijakanTiket::bolehAccept($siap, 'KASUBBAG AKADEMIK'));
        // Belum terverifikasi / belum ada surat: verifikator belum boleh paraf.
        $this->assertFalse(KebijakanTiket::bolehAccept($this->tiket(), 'KASUBBAG AKADEMIK'));
        $this->assertFalse(KebijakanTiket::bolehAccept($siap, 'OPERATOR AKADEMIK'));
        $this->assertTrue(KebijakanTiket::bolehAccept($siap, 'ADMIN'));
    }

    public function testValidasiTtdHanyaHomeUnitYangSiap(): void
    {
        $siap = $this->tiket(['ticketIsVerified' => 1, 'ticketSuratCreated' => '2026-01-01 10:00:00']);

        $this->assertTrue(KebijakanTiket::bolehValidasi($siap, 'DISPOSISI AKADEMIK', ['U01']));
        $this->assertTrue(KebijakanTiket::bolehValidasi($siap, 'KASUBBAG AKADEMIK', ['U01']));
        // Bukan home unit / disposisi sudah ditutup: tolak.
        $this->assertFalse(KebijakanTiket::bolehValidasi($siap, 'KASUBBAG AKADEMIK', ['U99']));
        $this->assertFalse(KebijakanTiket::bolehValidasi($this->tiket(['disposisiIsTrue' => 1, 'ticketIsVerified' => 1, 'ticketSuratCreated' => '2026-01-01 10:00:00']), 'KASUBBAG AKADEMIK', ['U01']));
        $this->assertFalse(KebijakanTiket::bolehValidasi($siap, 'OPERATOR AKADEMIK', ['U01']));
        $this->assertTrue(KebijakanTiket::bolehValidasi($siap, 'ADMIN', []));
    }

    public function testSuratBuatDanEditHanyaProduksiTerverifikasi(): void
    {
        $terverifikasi = $this->tiket(['ticketIsVerified' => 1]);

        // Buat: produksi + terverifikasi + surat belum ada.
        $this->assertTrue(KebijakanTiket::bolehBuatSurat($terverifikasi, 'KASUBBAG AKADEMIK'));
        $this->assertTrue(KebijakanTiket::bolehBuatSurat($terverifikasi, 'PRODUKSI AKADEMIK'));
        $this->assertFalse(KebijakanTiket::bolehBuatSurat($this->tiket(), 'KASUBBAG AKADEMIK'));
        $this->assertFalse(KebijakanTiket::bolehBuatSurat($terverifikasi, 'OPERATOR AKADEMIK'));

        // Edit: produksi + terverifikasi + surat sudah ada + home unit.
        $adaSurat = $this->tiket(['ticketIsVerified' => 1, 'ticketSuratCreated' => '2026-01-01 10:00:00']);
        $this->assertTrue(KebijakanTiket::bolehEditSurat($adaSurat, 'KASUBBAG AKADEMIK', ['U01']));
        $this->assertFalse(KebijakanTiket::bolehEditSurat($adaSurat, 'KASUBBAG AKADEMIK', ['U99']));
        $this->assertFalse(KebijakanTiket::bolehEditSurat($terverifikasi, 'KASUBBAG AKADEMIK', ['U01']));

        $this->assertTrue(KebijakanTiket::bolehBuatSurat($terverifikasi, 'ADMIN'));
        $this->assertTrue(KebijakanTiket::bolehEditSurat($adaSurat, 'ADMIN', []));
    }

    public function testIsHomeMensyaratkanAssignDiHomeDanDisposisiTerbuka(): void
    {
        $this->assertTrue(KebijakanTiket::isHome('U01', ['U01'], 0));
        $this->assertTrue(KebijakanTiket::isHome('U01', ['U01'], null));
        $this->assertFalse(KebijakanTiket::isHome('U01', ['U01'], 1));
        $this->assertFalse(KebijakanTiket::isHome('U02', ['U01'], 0));
        $this->assertFalse(KebijakanTiket::isHome('U01', [], 0));
        $this->assertFalse(KebijakanTiket::isHome(null, ['U01'], 0));
    }

    public function testControllerMemakaiKebijakanUntukAksiMutasi(): void
    {
        $controller = file_get_contents(ROOTPATH . 'app/Controllers/Ticketing.php');
        $this->assertNotFalse($controller);

        // Setiap aksi mutasi wajib melewati kebijakan peran+unit, bukan
        // sekadar tiketTerotorisasi() yang meloloskan semua OPERATOR.
        foreach (['bolehTerima', 'bolehTolak', 'bolehAssign', 'bolehAccept', 'bolehValidasi', 'bolehBuatSurat', 'bolehEditSurat'] as $aturan) {
            $this->assertStringContainsString($aturan, (string) $controller, 'Ticketing.php harus memakai ' . $aturan);
        }
    }

    public function testDetailTidakLagiMenampilkanSemuaTombolKeSemuaPeran(): void
    {
        $detail = file_get_contents(ROOTPATH . 'app/Views/pages/ticketing/detail.php');
        $this->assertNotFalse($detail);

        // Tiap tombol di halaman detail wajib di balik flag kebijakannya
        // masing-masing (fail-closed bila flag tidak dikirim controller).
        foreach (['boleh_terima', 'boleh_assign', 'boleh_buat_surat', 'boleh_validasi'] as $flag) {
            $this->assertStringContainsString($flag, (string) $detail, 'detail.php harus memakai flag ' . $flag);
        }
    }
}
