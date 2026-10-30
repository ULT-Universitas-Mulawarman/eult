<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Pengunci perbaikan alur simpan tiket (2026-10-30).
 *
 * Latar belakang: tiket di-INSERT lebih dulu lalu berkas divalidasi. Bila
 * unggahan gagal, tiket tetap tersimpan tanpa arsip (4.156 baris di
 * produksi, 10,7%) dan pengguna mengulang → duplikat.
 *
 * Tes ini mengunci urutan yang benar:
 *   validasi berkas -> transaksi -> insert tiket + arsip -> commit
 *
 * @internal
 */
final class AlurSimpanTiketTest extends CIUnitTestCase
{
    private function baca(string $jalur): string
    {
        $isi = file_get_contents(ROOTPATH . $jalur);
        $this->assertNotFalse($isi, $jalur . ' harus terbaca.');

        return (string) $isi;
    }

    // ------------------------------------------------------------ P1: atomik ---

    public function testHelperUploadDipisahDuaFase(): void
    {
        // Fase 1 tidak boleh menulis, fase 2 tidak boleh memvalidasi.
        $this->assertTrue(function_exists('eult_upload_periksa'), 'eult_upload_periksa() harus ada (fase validasi).');
        $this->assertTrue(function_exists('eult_upload_simpan'), 'eult_upload_simpan() harus ada (fase simpan).');
        $this->assertTrue(function_exists('eult_upload_hapus'), 'eult_upload_hapus() harus ada (pembersihan saat rollback).');
    }

    public function testPeriksaTidakMenulisApaPun(): void
    {
        // Fungsi validasi tidak boleh menyentuh filesystem atau database.
        $isi = $this->baca('app/Helpers/eult_upload_helper.php');

        $awal  = strpos($isi, 'function eult_upload_periksa');
        $akhir = strpos($isi, 'function eult_upload_simpan');
        $this->assertNotFalse($awal);
        $this->assertNotFalse($akhir);

        $badan = substr($isi, $awal, $akhir - $awal);

        foreach (['->move(', 'mkdir(', 'unlink(', '->tambah(', '->insert('] as $terlarang) {
            $this->assertStringNotContainsString(
                $terlarang,
                $badan,
                'eult_upload_periksa() tidak boleh menulis (' . $terlarang . ') — ia harus aman dipanggil sebelum transaksi.',
            );
        }
    }

    public function testSimpanTidakMemvalidasi(): void
    {
        // Validasi sudah dilakukan fase 1; fase 2 hanya menyimpan.
        $isi = $this->baca('app/Helpers/eult_upload_helper.php');

        $awal  = strpos($isi, 'function eult_upload_simpan');
        $akhir = strpos($isi, 'function eult_upload_hapus');
        $this->assertNotFalse($awal);
        $this->assertNotFalse($akhir);

        $badan = substr($isi, $awal, $akhir - $awal);

        $this->assertStringNotContainsString('eult_upload_konten_valid', $badan, 'Fase simpan tidak boleh memvalidasi ulang isi berkas.');
        $this->assertStringNotContainsString('eult_upload_melebihi_batas', $badan, 'Fase simpan tidak boleh memvalidasi ukuran.');
    }

    public function testSimpanMenghapusBerkasBilaInsertArsipGagal(): void
    {
        $isi = $this->baca('app/Helpers/eult_upload_helper.php');

        $this->assertStringContainsString(
            '@unlink($tujuan . $namaBaru)',
            $isi,
            'Bila insert d_archive gagal, berkas yang sudah dipindah harus dihapus agar tidak yatim.',
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function controllerSimpanProvider(): array
    {
        return [
            'portal publik' => ['app/Controllers/Login.php'],
            'petugas'       => ['app/Controllers/Ticketing.php'],
        ];
    }

    /**
     * @dataProvider controllerSimpanProvider
     */
    public function testValidasiBerkasSebelumTransaksi(string $jalur): void
    {
        $isi = $this->baca($jalur);

        $posisiPeriksa = strpos($isi, 'eult_upload_periksa(');
        $posisiTrans   = strpos($isi, 'transBegin()');

        $this->assertNotFalse($posisiPeriksa, $jalur . ' harus memanggil eult_upload_periksa().');
        $this->assertNotFalse($posisiTrans, $jalur . ' harus membuka transaksi.');

        $this->assertLessThan(
            $posisiTrans,
            $posisiPeriksa,
            $jalur . ': berkas harus divalidasi SEBELUM transaksi dibuka.',
        );
    }

    /**
     * @dataProvider controllerSimpanProvider
     */
    public function testTiketDanArsipDalamSatuTransaksi(string $jalur): void
    {
        $isi = $this->baca($jalur);

        $posisiTrans  = strpos($isi, 'transBegin()');
        $posisiTiket  = strpos($isi, "tambah('d_ticketing'");
        $posisiArsip  = strpos($isi, 'eult_upload_simpan(');
        $posisiCommit = strpos($isi, 'transCommit()');

        $this->assertNotFalse($posisiTiket, $jalur . ' harus menyimpan tiket.');
        $this->assertNotFalse($posisiArsip, $jalur . ' harus menyimpan arsip.');
        $this->assertNotFalse($posisiCommit, $jalur . ' harus commit.');

        $this->assertLessThan($posisiTiket, $posisiTrans, $jalur . ': transaksi harus dibuka sebelum insert tiket.');
        $this->assertLessThan($posisiArsip, $posisiTiket, $jalur . ': arsip disimpan setelah tiket, masih di dalam transaksi.');
        $this->assertLessThan($posisiCommit, $posisiArsip, $jalur . ': commit setelah arsip tersimpan.');
    }

    /**
     * @dataProvider controllerSimpanProvider
     */
    public function testRollbackMenghapusBerkas(string $jalur): void
    {
        $isi = $this->baca($jalur);

        $this->assertStringContainsString('transRollback()', $isi, $jalur . ' harus punya jalur rollback.');
        $this->assertStringContainsString(
            'eult_upload_hapus(',
            $isi,
            $jalur . ': berkas harus dihapus saat rollback — pemindahan berkas tidak ikut ter-rollback database.',
        );
    }

    /**
     * @dataProvider controllerSimpanProvider
     */
    public function testTidakAdaUploadSetelahCommit(string $jalur): void
    {
        // Pola lama: insert tiket (commit) lalu upload. Harus hilang.
        $isi = $this->baca($jalur);

        $posisiCommit = strpos($isi, 'transCommit()');
        $posisiUpload = strpos($isi, 'eult_upload_ticket(');

        if ($posisiUpload === false) {
            $this->assertTrue(true, 'Tidak memakai pembungkus lama — baik.');

            return;
        }

        $this->assertLessThan(
            $posisiCommit,
            $posisiUpload,
            $jalur . ': masih memanggil eult_upload_ticket() setelah commit.',
        );
    }

    // ------------------------------------------------ P2: berkas wajib ---

    public function testBerkasWajibDiPortalPublik(): void
    {
        $isi = $this->baca('app/Controllers/Login.php');

        $this->assertStringContainsString(
            'eult_upload_periksa($berkas, [',
            $isi,
            'Login harus memeriksa berkas.',
        );

        // Parameter ketiga tidak boleh false: berkas wajib bagi pemohon publik.
        $this->assertDoesNotMatchRegularExpression(
            '/eult_upload_periksa\(\s*\$berkas,\s*\[[^\]]*\],\s*false\s*\)/s',
            $isi,
            'Portal publik: berkas WAJIB, jangan lewatkan argumen $wajib=false.',
        );
    }

    public function testViewMenandaiBerkasWajib(): void
    {
        $isi = $this->baca('app/Views/layouts/login.php');

        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="ticketArchiveId"[^>]*required/s',
            $isi,
            'Input berkas harus punya atribut required agar browser menolak submit kosong.',
        );
    }

    public function testAturanValidasiKlienUntukBerkas(): void
    {
        $isi = $this->baca('public/assets/js/pages/custom/pages/user/login.js');

        $this->assertStringContainsString('ticketArchiveId', $isi, 'Validasi klien harus menyebut ticketArchiveId.');
        $this->assertStringContainsString('extension: "pdf"', $isi, 'Klien harus membatasi ekstensi PDF.');
    }

    // ------------------------------------------- P3: pencegahan duplikat ---

    public function testTokenIdempotensiAda(): void
    {
        $this->assertTrue(function_exists('eult_submit_token'), 'eult_submit_token() harus ada.');
        $this->assertTrue(function_exists('eult_submit_token_cocok'), 'eult_submit_token_cocok() harus ada.');
    }

    public function testTokenDikirimDanDivalidasi(): void
    {
        $view = $this->baca('app/Views/layouts/login.php');
        $ctrl = $this->baca('app/Controllers/Login.php');

        $this->assertStringContainsString('name="submitToken"', $view, 'Form harus mengirim submitToken.');
        $this->assertStringContainsString('eult_submit_token_cocok(', $ctrl, 'Controller harus memvalidasi token.');
        $this->assertStringContainsString("'submit_token'", $ctrl, 'Respons harus mengirim token baru setelah rotasi.');
    }

    public function testDeteksiTiketSerupaAda(): void
    {
        $isi = $this->baca('app/Models/ModelTicketing.php');

        $this->assertStringContainsString('function tiketSerupa(', $isi, 'Model harus punya tiketSerupa().');
        $this->assertStringContainsString("hash('sha256'", $isi, 'Perbandingan pesan harus memakai hash, bukan kolom TEXT langsung.');
    }

    public function testTiketSerupaDipanggilSebelumInsert(): void
    {
        $isi = $this->baca('app/Controllers/Login.php');

        $posisiSerupa = strpos($isi, 'tiketSerupa(');
        $posisiInsert = strpos($isi, "tambah('d_ticketing'");

        $this->assertNotFalse($posisiSerupa, 'Login harus memanggil tiketSerupa().');
        $this->assertNotFalse($posisiInsert);

        $this->assertLessThan(
            $posisiInsert,
            $posisiSerupa,
            'Deteksi tiket serupa harus dilakukan SEBELUM insert.',
        );
    }

    // ------------------------------------------------- P4/P5: pemantauan ---

    public function testCommandTinjauArsipAda(): void
    {
        $this->assertTrue(
            class_exists(\App\Commands\TinjauArsipHilang::class),
            'Perintah eult:arsip harus ada untuk meninjau tiket tanpa arsip.',
        );
    }

    public function testCommandTidakMenghapusTanpaKonfirmasi(): void
    {
        $isi = $this->baca('app/Commands/TinjauArsipHilang.php');

        $this->assertStringContainsString("punyaOpsi(\$params, 'hapus')", $isi);
        $this->assertStringContainsString("punyaOpsi(\$params, 'ya')", $isi);
        $this->assertStringContainsString(
            'memerlukan --ya',
            $isi,
            'Penghapusan harus menolak berjalan tanpa --ya.',
        );
    }

    public function testKandidatHapusMenghormatiJejakPenanganan(): void
    {
        $isi = $this->baca('app/Commands/TinjauArsipHilang.php');

        foreach (['d_replies', 'd_disposisi', 'd_history'] as $tabel) {
            $this->assertStringContainsString(
                'FROM ' . $tabel,
                $isi,
                'Kandidat hapus harus mengecualikan tiket yang punya jejak di ' . $tabel . '.',
            );
        }
    }

    public function testWorkflowRegresiTerjadwal(): void
    {
        $jalur = ROOTPATH . '.github/workflows/regresi-data.yml';

        $this->assertFileExists($jalur);

        $isi = (string) file_get_contents($jalur);

        $this->assertStringContainsString('schedule:', $isi, 'Job regresi harus terjadwal.');
        $this->assertStringContainsString('eult:arsip', $isi, 'Job harus memakai perintah eult:arsip.');
        $this->assertStringContainsString('--dry-run', $isi, 'Job tidak boleh mengubah data.');
    }
}
