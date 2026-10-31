<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Pengunci batas layanan per role pemohon (2026-10-31).
 *
 * Aturan bisnis: mahasiswa/pegawai yang terverifikasi OSM boleh mengajukan
 * SEMUA layanan; pemohon umum (nomor identitas tidak ditemukan di OSM)
 * dibatasi ke layanan publik.
 *
 * Sebelum perbaikan, filter ini ada di kode tetapi tidak pernah aktif:
 * pemanggil memakai `$id === 'true' ? getLayanan() : getLayanan($id)` dan
 * `$id` selalu bernilai string 'true', sehingga cabang filter tidak pernah
 * dieksekusi. Ketiga role melihat 60 layanan yang sama.
 *
 * @internal
 */
final class BatasLayananRoleTest extends CIUnitTestCase
{
    private function baca(string $jalur): string
    {
        $isi = file_get_contents(ROOTPATH . $jalur);
        $this->assertNotFalse($isi, $jalur . ' harus terbaca.');

        return (string) $isi;
    }

    // ---------------------------------------------------------- helper role ---

    public function testHelperRoleAda(): void
    {
        foreach (['eult_pemohon_role_simpan', 'eult_pemohon_role', 'eult_pemohon_role_terakhir', 'eult_pemohon_umum'] as $fn) {
            $this->assertTrue(function_exists($fn), $fn . '() harus tersedia.');
        }
    }

    public function testHelperRoleTerdaftarDiAutoload(): void
    {
        $isi = $this->baca('app/Config/Autoload.php');

        $this->assertStringContainsString(
            "'eult_pemohon'",
            $isi,
            'Helper role harus di-autoload agar tersedia di controller dan view.',
        );
    }

    public function testRoleBelumDiverifikasiDianggapUmum(): void
    {
        // null = belum pernah diverifikasi. Pilihan aman: batasi layanan.
        $this->assertTrue(
            eult_pemohon_umum(null),
            'Identitas yang belum diverifikasi harus diperlakukan sebagai umum.',
        );
        $this->assertFalse(eult_pemohon_umum('internal'), 'Role internal tidak dibatasi.');
        $this->assertTrue(eult_pemohon_umum('umum'), 'Role umum dibatasi.');
    }

    // -------------------------------------------------- daftar layanan publik ---

    public function testDaftarLayananUmumBisaDioverrideEnv(): void
    {
        $model = new \App\Models\ModelMaster();

        putenv('EULT_LAYANAN_UMUM=1,2,3');

        try {
            $this->assertSame([1, 2, 3], $model->layananUmumIds());
        } finally {
            putenv('EULT_LAYANAN_UMUM');
        }
    }

    public function testNamaLamaEultLayananKhususMasihDibaca(): void
    {
        // .env produksi mungkin masih memakai nama lama; jangan sampai
        // konfigurasi yang sudah ada jadi tidak terbaca.
        $model = new \App\Models\ModelMaster();

        putenv('EULT_LAYANAN_UMUM');
        putenv('EULT_LAYANAN_KHUSUS=7,8');

        try {
            $this->assertSame([7, 8], $model->layananUmumIds());
        } finally {
            putenv('EULT_LAYANAN_KHUSUS');
        }
    }

    public function testDaftarDefaultSesuaiAplikasiLama(): void
    {
        $model = new \App\Models\ModelMaster();

        putenv('EULT_LAYANAN_UMUM');
        putenv('EULT_LAYANAN_KHUSUS');

        try {
            $this->assertSame(
                [9, 12, 32, 45, 78, 88, 110, 27, 28, 29, 146, 150, 168],
                $model->layananUmumIds(),
                'Default harus sama dengan potongan kode CI3 yang dipakai sebelumnya.',
            );
        } finally {
            putenv('EULT_LAYANAN_UMUM');
            putenv('EULT_LAYANAN_KHUSUS');
        }
    }

    public function testDaftarKosongTidakMenghasilkanIdNol(): void
    {
        $model = new \App\Models\ModelMaster();

        putenv('EULT_LAYANAN_UMUM=,,0,');

        try {
            $this->assertSame([], $model->layananUmumIds(), 'Nilai kosong/0 harus dibuang, bukan jadi layananId 0.');
        } finally {
            putenv('EULT_LAYANAN_UMUM');
        }
    }

    // -------------------------------------------------------------- query ---

    public function testGetLayananMemakaiParameterBoolean(): void
    {
        $isi = $this->baca('app/Models/ModelMaster.php');

        $this->assertStringContainsString(
            'public function getLayanan(bool $hanyaUmum = false)',
            $isi,
            'Parameter harus boolean, bukan string $khusus yang rawan salah panggil.',
        );
    }

    public function testFilterUmumMencakupJenisInformasiDanDaftarEnv(): void
    {
        $isi = $this->baca('app/Models/ModelMaster.php');

        $this->assertStringContainsString("->where('jenislayananId', 3)", $isi, 'LAYANAN INFORMASI harus selalu terbuka untuk umum.');
        $this->assertStringContainsString('orWhereIn(\'layananId\', $this->layananUmumIds())', $isi);
    }

    public function testAdaPenegakanPerLayanan(): void
    {
        $isi = $this->baca('app/Models/ModelMaster.php');

        $this->assertStringContainsString(
            'public function layananBolehUntukUmum',
            $isi,
            'Harus ada pengecekan per-layanan untuk penegakan di server.',
        );
    }

    // ------------------------------------------------------- pemanggil kode ---

    public function testGetLayananTidakLagiMemakaiIdString(): void
    {
        // Pola lama: `$id === 'true' ? getLayanan() : getLayanan($id)` —
        // cabang kedua tidak pernah jalan karena $id selalu 'true'.
        foreach (['app/Controllers/Login.php', 'app/Controllers/Ticketing.php'] as $jalur) {
            $isi = $this->baca($jalur);

            $this->assertStringNotContainsString(
                "\$id === 'true' ?",
                $isi,
                $jalur . ' masih memakai pola lama yang membuat filter tidak pernah aktif.',
            );
            $this->assertStringContainsString(
                'eult_pemohon_umum()',
                $isi,
                $jalur . ' harus menentukan batas layanan dari role session.',
            );
        }
    }

    public function testRoleDisimpanSaatIdentitasDiverifikasi(): void
    {
        foreach (['app/Controllers/Login.php', 'app/Controllers/Ticketing.php'] as $jalur) {
            $isi = $this->baca($jalur);

            $this->assertStringContainsString(
                "eult_pemohon_role_simpan(\$id, 'internal')",
                $isi,
                $jalur . ': mahasiswa/pegawai terverifikasi harus ditandai internal.',
            );
            $this->assertStringContainsString(
                "eult_pemohon_role_simpan(\$id, 'umum')",
                $isi,
                $jalur . ': pemohon tanpa data OSM harus ditandai umum.',
            );
        }
    }

    public function testServerMenolakLayananDiLuarBatasUntukUmum(): void
    {
        // Menyaring dropdown saja tidak cukup — POST langsung harus ditolak.
        foreach (['app/Controllers/Login.php', 'app/Controllers/Ticketing.php'] as $jalur) {
            $isi = $this->baca($jalur);

            $this->assertStringContainsString(
                'layananBolehUntukUmum(',
                $isi,
                $jalur . ' harus menegakkan batas layanan di server, bukan hanya di UI.',
            );
        }
    }

    public function testPenegakanTerjadiSebelumInsert(): void
    {
        $isi = $this->baca('app/Controllers/Login.php');

        $posisiTolak  = strpos($isi, 'layananBolehUntukUmum(');
        $posisiInsert = strpos($isi, "tambah('d_ticketing'");

        $this->assertNotFalse($posisiTolak);
        $this->assertNotFalse($posisiInsert);

        $this->assertLessThan(
            $posisiInsert,
            $posisiTolak,
            'Penolakan layanan harus terjadi sebelum tiket di-INSERT.',
        );
    }
}
