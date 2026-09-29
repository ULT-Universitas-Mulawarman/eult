<?php

namespace Tests\Bugfix;

use App\Libraries\Enkripsi;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Regresi migrasi CI3 → CI4: kunci terenkripsi yang GAGAL di-decode.
 *
 * `Enkripsi::decode()` mengembalikan `false` (bukan `''`) saat kunci tidak
 * valid. Nilai `false` yang diteruskan apa adanya ke `where([...])` query
 * builder CI4 menghasilkan fragmen `WHERE kolom = 0` — pada kolom varchar
 * MySQL membandingkan string sebagai angka (0), sehingga SELURUH baris
 * cocok. Dua dampak nyata dari satu akar yang sama:
 *
 * 1. `Validitas::index()` (rute PUBLIK `validitas/(:any)`, tanpa filter
 *    auth) memanggil `ModelTicketing::byId(false)` — query ber-JOIN atas
 *    ~38.441 baris d_ticketing sehingga request biasa (mis. tautan QR
 *    lama setelah rotasi kunci, atau sekadar kunci salah ketik)
 *    berakhir FATAL "Allowed memory size ... exhausted" (HTTP 200 +
 *    jejak fatal), bukan halaman "tidak ditemukan". Di CI3 perilaku
 *    setara hanya mengembalikan satu baris acak tanpa mematikan proses.
 * 2. Aksi hapus/ubah berbasis kunci (`Hakakses`, `Modul`, `Modulgroup`)
 *    menyusun `WHERE kolom = 0` pada kolom varchar, yaitu kondisi yang
 *    cocok dengan seluruh baris tabel. Saat ini penghapusan tertahan
 *    FK (error 1451) sehingga tidak ada data hilang, namun controller
 *    tetap TIDAK BOLEH menyusun perintah hapus atas kunci yang gagal
 *    di-decode — pertahanan berlapis, mengikuti konvensi yang sudah
 *    dipakai `Pengguna::delete()`.
 *
 * Test ini tersambung ke MySQL nyata `db_newtiket` (bukan mock) karena
 * seluruh kelas bug ini hanya muncul pada perbandingan varchar MySQL
 * yang sebenarnya.
 */
final class KunciTidakValidTidakMenyentuhDataTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const KUNCI_SAMPAH = 'KUNCI-TIDAK-VALID-UNTUK-UJI-MIGRASI';

    private Enkripsi $enkripsi;

    private BaseConnection $koneksi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enkripsi = new Enkripsi();
        $this->koneksi  = \Config\Database::connect('default');

        self::assertSame(
            'db_newtiket',
            $this->koneksi->database,
            'Test ini WAJIB tersambung ke database nyata db_newtiket agar perbandingan varchar MySQL yang diuji benar-benar terjadi.'
        );

        self::assertFalse(
            $this->enkripsi->decode(self::KUNCI_SAMPAH),
            'Prasyarat test: kunci sampah ini memang tidak dapat di-decode.'
        );
    }

    private function sesiAdmin(): array
    {
        return [
            'susrNama'           => 'staf_uji_kunci',
            'susrSgroupNama'     => 'ADMIN',
            'susrSgroupNama_ori' => 'ADMIN',
            'susrProfil'         => 'Staf Uji Kunci',
        ];
    }

    /**
     * Akar masalah dinamis: `false` cocok dengan semua baris pada kolom
     * varchar. Bila ini gagal, asumsi prasyarat seluruh test di file ini
     * tidak lagi berlaku dan perbaikannya harus ditinjau ulang.
     */
    public function testPrasyaratNilaiFalsePadaKolomVarcharCocokSeluruhBaris(): void
    {
        $total = $this->koneksi->table('d_ticketing')->countAllResults();
        $cocok = $this->koneksi->table('d_ticketing')->where(['ticketTrackingId' => false])->countAllResults();

        self::assertGreaterThan(1000, $total, 'Butuh data d_ticketing yang cukup agar counterexample bermakna.');
        self::assertSame($total, $cocok, 'Nilai false harus tetap memperlihatkan sifat "cocok semua baris" — bila SQL mode/tipe kolom berubah, seluruh analisis perlu ditinjau ulang.');

        // Bandingkan dengan bentuk yang AMAN: string kosong tidak cocok apa pun.
        self::assertSame(0, $this->koneksi->table('d_ticketing')->where(['ticketTrackingId' => ''])->countAllResults());
    }

    /**
     * Regresi utama: rute publik validitas dengan kunci tak terdecode
     * SHALL menghasilkan halaman "tidak ditemukan" — BUKAN fatal kehabisan
     * memori akibat query byId() atas seluruh tabel, dan BUKAN halaman
     * berisi data tiket acak milik orang lain.
     */
    public function testValiditasKunciTidakValidTidakMembocorkanTiketDanTidakKehabisanMemori(): void
    {
        $tiketNyata = (string) ($this->koneksi->table('d_ticketing')
            ->select('ticketTrackingId')
            ->orderBy('ticketTrackingId')
            ->limit(1)
            ->get()->getRowArray()['ticketTrackingId'] ?? '');

        self::assertNotSame('', $tiketNyata, 'Butuh minimal satu tiket nyata sebagai kontrol negatif.');

        $hasil = $this->get('validitas/' . self::KUNCI_SAMPAH);

        $hasil->assertStatus(404);
        $this->assertStringNotContainsString(
            $tiketNyata,
            $hasil->getBody(),
            'Halaman validitas dengan kunci tak terdecode TIDAK BOLEH menampilkan tiket milik orang lain.'
        );
        $this->assertStringNotContainsString(
            'Allowed memory size',
            $hasil->getBody(),
            'Rute publik ini TIDAK BOLEH berakhir fatal kehabisan memori.'
        );
    }

    /**
     * Kontrol positif (anti over-blocking): kunci SAH tetap menyajikan
     * halaman validitas tiket yang bersangkutan.
     */
    public function testValiditasKunciSahTetapMenyajikanTiketYangBenar(): void
    {
        $tiket = $this->koneksi->table('d_ticketing')
            ->select('ticketTrackingId')
            ->orderBy('ticketTrackingId')
            ->limit(1)
            ->get()->getRowArray();

        self::assertNotNull($tiket, 'Butuh minimal satu tiket nyata untuk kontrol positif.');

        $kunci = (string) $this->enkripsi->encode((string) $tiket['ticketTrackingId']);
        $hasil = $this->get('validitas/' . $kunci);

        $hasil->assertStatus(200);
        $this->assertStringContainsString((string) $tiket['ticketTrackingId'], $hasil->getBody());
    }

    /**
     * Aksi hapus berbasis kunci varchar tidak boleh menyusun perintah
     * hapus ketika kunci gagal di-decode.
     *
     * @dataProvider aksiHapusProvider
     */
    public function testAksiHapusKunciTidakValidTidakMenghapusApaPun(string $tabel, string $kolom, string $url): void
    {
        $totalSebelum = $this->koneksi->table($tabel)->countAllResults();

        $hasil = $this->withSession(['logged_in' => $this->sesiAdmin()])
            ->withBodyFormat('form')
            ->post($url, [csrf_token() => csrf_hash()]);

        self::assertSame('error', json_decode($hasil->getJSON(), true)['status'] ?? null, 'Kunci tak valid SHALL ditolak sebagai error.');
        self::assertStringContainsString('tidak valid', strtolower((string) (json_decode($hasil->getJSON(), true)['message'] ?? '')));

        self::assertSame(
            $totalSebelum,
            $this->koneksi->table($tabel)->countAllResults(),
            "Tidak boleh ada baris {$tabel} yang terhapus untuk kunci tak valid."
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function aksiHapusProvider(): array
    {
        return [
            'hakakses (s_user_group)'            => ['s_user_group', 'sgroupNama', 'hakakses/delete/' . self::KUNCI_SAMPAH],
            'modul (s_user_modul_ref)'           => ['s_user_modul_ref', 'susrmodulNama', 'modul/delete/' . self::KUNCI_SAMPAH],
            'modulgroup (s_user_modul_group_ref)' => ['s_user_modul_group_ref', 'susrmdgroupNama', 'modulgroup/delete/' . self::KUNCI_SAMPAH],
            'pengguna (s_user)'                  => ['s_user', 'susrNama', 'pengguna/delete/' . self::KUNCI_SAMPAH],
        ];
    }
}
