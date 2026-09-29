<?php

use App\Libraries\Enkripsi;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Smoke test migrasi CI3 → CI4: SELURUH halaman admin harus benar-benar
 * ter-render terhadap database nyata.
 *
 * Alasan test ini ada: parity nama method controller TIDAK membuktikan
 * halaman benar-benar tampil. Satu view yang memanggil helper atau indeks
 * array yang tidak ada cukup untuk mematikan halaman dengan fatal error,
 * dan itu hanya terlihat bila route-nya benar-benar dieksekusi. Test ini
 * men-dispatch setiap route GET admin dengan sesi ADMIN terhadap data
 * nyata `db_newtiket`, lalu memastikan tidak ada respons 5xx maupun jejak
 * fatal/exception pada body.
 *
 * Status yang diharapkan TIDAK di-hardcode untuk soal hak akses: nilainya
 * diturunkan dari tabel `s_user_group_modul` (sumber yang dipakai
 * `AuthFilter`), sehingga test sekaligus memverifikasi kontrak otorisasi
 * "modul tanpa hak baca => 403, modul dengan hak baca => 200". Pada data
 * saat ini ADMIN memang tidak diberi hak baca untuk `refkategori`,
 * `hakakseslayanan`, dan `hakaksesuser` — perilaku ini setia dengan CI3
 * (`MY_Controller::get_master()` menampilkan `layouts/error_page`).
 *
 * Test ini berada di suite `Database` (butuh MySQL db_newtiket + db_ult),
 * bukan `Unit`, mengikuti pemisahan infrastruktur di phpunit.dist.xml.
 */
final class SemuaHalamanAdminTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    /** Penanda kegagalan level PHP yang tidak boleh muncul pada body. */
    private const JEJAK_FATAL = [
        'Fatal error',
        'Allowed memory size',
        'Uncaught ',
        'Call to a member function',
        'Call to undefined',
        'Attempt to read property',
        'Trying to access array offset',
        'Undefined variable',
        'Undefined array key',
        'syntax error, unexpected',
        'SQLSTATE[',
    ];

    private BaseConnection $koneksi;

    private Enkripsi $enkripsi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->koneksi  = \Config\Database::connect('default');
        $this->enkripsi = new Enkripsi();

        self::assertSame('db_newtiket', $this->koneksi->database, 'Smoke test ini WAJIB memakai database nyata db_newtiket.');
    }

    private function sesiAdmin(): array
    {
        return ['logged_in' => [
            'susrNama'           => 'staf_uji_halaman',
            'susrSgroupNama'     => 'ADMIN',
            'susrSgroupNama_ori' => 'ADMIN',
            'susrProfil'         => 'Staf Uji Halaman',
        ]];
    }

    /**
     * Kunci terenkripsi untuk sebuah nilai dari tabel referensi nyata.
     */
    private function kunciDari(string $tabel, string $kolom): string
    {
        $baris = $this->koneksi->table($tabel)->select($kolom)->orderBy($kolom)->limit(1)->get()->getRowArray();

        self::assertNotNull($baris, "Data referensi {$tabel}.{$kolom} dibutuhkan agar smoke test bermakna.");

        return (string) $this->enkripsi->encode((string) $baris[$kolom]);
    }

    /** Hak baca modul untuk grup ADMIN — sumber yang sama dengan AuthFilter. */
    private function bolehBaca(string $modul): bool
    {
        return $this->koneksi->table('s_user_group_modul')
            ->where('sgroupmodulSgroupNama', 'ADMIN')
            ->where('sgroupmodulSusrmodulNama', $modul)
            ->where('sgroupmodulSusrmodulRead', '1')
            ->countAllResults() > 0;
    }

    /**
     * Status yang diharapkan untuk satu route: `home` selalu boleh
     * (AuthFilter mengecualikannya), modul lain mengikuti hak baca.
     */
    private function statusDiharapkan(string $url): int
    {
        $modul = strtolower(explode('/', ltrim($url, '/'))[0]);

        if ($modul === 'home') {
            return 200;
        }

        return $this->bolehBaca($modul) ? 200 : 403;
    }

    /**
     * Menjalankan satu route dan mengembalikan pesan kegagalan
     * (string kosong bila halaman sehat).
     *
     * @param int|null $statusDiminta Status pasti yang diharapkan; null berarti
     *                                diturunkan dari hak akses modul.
     * @param bool     $bebasStatus   True untuk halaman alur yang boleh
     *                                mengalihkan/menolak sesuai aturan bisnis —
     *                                yang penting tidak pernah mati dengan galat.
     */
    private function periksa(string $url, ?int $statusDiminta = null, bool $bebasStatus = false): string
    {
        $hasil   = $this->withSession($this->sesiAdmin())->get($url);
        $status  = $hasil->response()->getStatusCode();
        $body    = $hasil->getBody();
        $masalah = [];

        if ($status >= 500) {
            $masalah[] = "status HTTP {$status}";
        } elseif (! $bebasStatus) {
            $diharapkan = $statusDiminta ?? $this->statusDiharapkan($url);

            if ($status !== $diharapkan) {
                $masalah[] = "status HTTP {$status} (diharapkan {$diharapkan})";
            }
        }

        foreach (self::JEJAK_FATAL as $jejak) {
            if (stripos($body, $jejak) !== false) {
                $masalah[] = "jejak \"{$jejak}\"";
                break;
            }
        }

        if ($masalah === []) {
            return '';
        }

        return sprintf('GET %s => %s (ukuran %d byte)', $url, implode(' + ', $masalah), strlen($body));
    }

    /**
     * @param list<string> $daftar
     */
    private function periksaSemua(array $daftar, string $catatan, bool $bebasStatus = false): void
    {
        $gagal = [];

        foreach ($daftar as $url) {
            $pesan = $this->periksa($url, null, $bebasStatus);

            if ($pesan !== '') {
                $gagal[] = $pesan;
            }
        }

        self::assertSame([], $gagal, $catatan . "\n" . implode("\n", $gagal));
    }

    public function testSemuaHalamanDaftarAdminTerbukaTanpaGalat(): void
    {
        $this->periksaSemua([
            'home',
            'home/index',
            'home/ubahpass',
            'home/ubahhakakses',
            'ticketing',
            'validasifile',
            'laporan',
            'laporanlayanan',
            'refkategori',
            'refsurat',
            'refsyarat',
            'unit',
            'modul',
            'modulgroup',
            'hakakses',
            'hakaksesmodul',
            'hakaksesunit',
            'hakakseslayanan',
            'hakaksespengguna',
            'hakaksesuser',
            'pengguna',
        ], 'Seluruh halaman daftar admin harus ter-render tanpa galat.');
    }

    public function testSemuaHalamanFormCreateAdminTerbukaTanpaGalat(): void
    {
        $this->periksaSemua([
            'refkategori/create',
            'refsurat/create',
            'refsyarat/create',
            'unit/create',
            'modul/create',
            'modulgroup/create',
            'hakakses/create',
            'pengguna/create',
        ], 'Seluruh halaman form tambah admin harus ter-render tanpa galat.');
    }

    public function testSemuaHalamanFormUpdateAdminTerbukaTanpaGalat(): void
    {
        $this->periksaSemua([
            'refkategori/update/' . $this->kunciDari('r_category', 'categoryId'),
            'refsurat/update/' . $this->kunciDari('r_category', 'categoryId'),
            'refsyarat/update/' . $this->kunciDari('r_berkas_layanan', 'berkasId'),
            'unit/update/' . $this->kunciDari('s_unit', 'unitId'),
            'modul/update/' . $this->kunciDari('s_user_modul_ref', 'susrmodulNama'),
            'modulgroup/update/' . $this->kunciDari('s_user_modul_group_ref', 'susrmdgroupNama'),
            'hakakses/update/' . $this->kunciDari('s_user_group', 'sgroupNama'),
            'pengguna/update/' . $this->kunciDari('s_user', 'susrNama'),
        ], 'Seluruh halaman form ubah admin harus ter-render tanpa galat.');
    }

    public function testHalamanWorkflowTiketAdminTerbukaTanpaGalat(): void
    {
        $tiket = $this->kunciDari('d_ticketing', 'ticketTrackingId');

        $this->periksaSemua([
            'ticketing/detail/' . $tiket,
            'ticketing/create_surat/' . $tiket,
            'ticketing/edit_surat/' . $tiket,
            'ticketing/edit_surat_ktm/' . $tiket,
            'ticketing/assign/' . $tiket,
            'ticketing/accept/' . $tiket,
            'ticketing/validasi/' . $tiket,
            'ticketing/cetakterima/' . $tiket,
            'ticketing/export/' . $tiket,
        ], 'Halaman alur tiket admin harus ter-render tanpa galat.', true);
    }

    /**
     * `ticketing/create` hanya melayani AJAX (form dimuat ke modal) dan
     * menolak request biasa dengan 400 — perilaku ini disengaja.
     */
    public function testFormTiketCreateHanyaMelayaniAjax(): void
    {
        $biasa = $this->withSession($this->sesiAdmin())->get('ticketing/create');
        self::assertSame(400, $biasa->response()->getStatusCode(), 'Request non-AJAX SHALL ditolak.');

        $ajax = $this->withSession($this->sesiAdmin())
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get('ticketing/create');

        self::assertSame(200, $ajax->response()->getStatusCode());
        self::assertStringContainsString('ticketCategories', $ajax->getBody(), 'Form tiket SHALL benar-benar ter-render.');
    }

    /**
     * Halaman publik yang menerima kunci: kunci SAH harus tersaji,
     * sedangkan kunci tak terdecode harus ditolak dengan rapi — bukan
     * berakhir fatal kehabisan memori (lihat
     * tests/Bugfix/KunciTidakValidTidakMenyentuhDataTest.php).
     */
    public function testHalamanValiditasPublikTetapSehat(): void
    {
        $tiket = $this->kunciDari('d_ticketing', 'ticketTrackingId');

        $sah = $this->get('validitas/' . $tiket);
        $sah->assertStatus(200);
        $this->assertStringNotContainsString('Fatal error', $sah->getBody());

        $palsu = $this->get('validitas/kunci-tidak-terdecode');
        $palsu->assertStatus(404);
        $this->assertStringNotContainsString('Fatal error', $palsu->getBody());
    }

    /** Berkas PDF tanda tangan pada writable/uploads/ticketing. */
    private function daftarBerkasTtd(): array
    {
        return glob(WRITEPATH . 'uploads/ticketing/TTD_*.pdf') ?: [];
    }

    /**
     * `ticketing/cetaksurat` meng-generate surat final: menulis PDF ke
     * writable/uploads/ticketing DAN mencatat baris `d_archive`. Karena
     * endpoint ini MENGUBAH state, test membungkusnya dalam transaksi yang
     * di-rollback dan menghapus berkas yang dihasilkan — supaya suite ini
     * tidak meninggalkan sisa pada database/disk.
     */
    public function testCetaksuratMenghasilkanSuratTanpaMeninggalkanSisa(): void
    {
        $tiket       = $this->kunciDari('d_ticketing', 'ticketTrackingId');
        $sebelum     = $this->daftarBerkasTtd();
        $arsipSebelum = $this->koneksi->table('d_archive')->countAllResults();

        $this->koneksi->transBegin();

        try {
            $hasil = $this->withSession($this->sesiAdmin())->get('ticketing/cetaksurat/' . $tiket);
            self::assertLessThan(500, $hasil->response()->getStatusCode());
            $this->assertStringNotContainsString('Fatal error', $hasil->getBody());
        } finally {
            $this->koneksi->transRollback();

            foreach (array_diff($this->daftarBerkasTtd(), $sebelum) as $berkas) {
                @unlink($berkas);
            }
        }

        self::assertSame([], array_diff($this->daftarBerkasTtd(), $sebelum), 'Berkas PDF hasil test SHALL dibersihkan kembali.');
        self::assertSame(
            $arsipSebelum,
            $this->koneksi->table('d_archive')->countAllResults(),
            'Baris d_archive hasil test SHALL tidak tertinggal.'
        );
    }
}
