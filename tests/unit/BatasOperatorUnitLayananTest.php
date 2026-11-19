<?php

use App\Models\ModelMaster;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Kontrak pembatasan OPERATOR_* berbasis unit layanan (s_user_group.sgroupCategoryId -> ref_unit.unitId).
 *
 * Aturan bisnis:
 * - ADMIN dan grup non-OPERATOR (KASUBBAG_*, DISPOSISI_*, PRODUKSI_*) menghasilkan null
 *   pada unitLayananOperator() karena mereka diatur oleh jalur otorisasi masing-masing.
 * - OPERATOR_CALLCENTER (sgroupCategoryId = 99 / "Semua Layanan", atau '' / '0') adalah
 *   operator global -> unitLayananOperator() mengembalikan null.
 * - OPERATOR_<UNIT> (mis. OPERATOR_KEUANGAN = 21, OPERATOR_AKADEMIK = 22,
 *   OPERATOR_KEMAHASISWAAN = 23) terikat ke satu unit layanan -> unitLayananOperator()
 *   mengembalikan string unitId tersebut.
 *
 * @internal
 */
final class BatasOperatorUnitLayananTest extends CIUnitTestCase
{
    private function baca(string $jalur): string
    {
        $isi = file_get_contents(ROOTPATH . $jalur);
        $this->assertNotFalse($isi, $jalur . ' harus terbaca.');

        return (string) $isi;
    }

    public function testMethodUnitLayananOperatorTersediaDiModelMaster(): void
    {
        $this->assertTrue(
            method_exists(ModelMaster::class, 'unitLayananOperator'),
            'ModelMaster::unitLayananOperator() wajib tersedia sebagai sumber tunggal resolusi unit layanan operator.'
        );
    }

    public function testUnitLayananOperatorMengembalikanNullUntukAdminDanNonOperator(): void
    {
        $model = new ModelMaster();
        $this->assertTrue(method_exists($model, 'unitLayananOperator'));

        $this->assertNull($model->unitLayananOperator('ADMIN'));
        $this->assertNull($model->unitLayananOperator('ADMIN', '21'));
        $this->assertNull($model->unitLayananOperator('KASUBBAG_AE', '22'));
        $this->assertNull($model->unitLayananOperator('DISPOSISI_BAGAKADEMIK', '22'));
        $this->assertNull($model->unitLayananOperator('PRODUKSI_AE', '22'));
        $this->assertNull($model->unitLayananOperator(''));
    }

    public function testUnitLayananOperatorMengembalikanNullUntukOperatorGlobalCallCenter(): void
    {
        $model = new ModelMaster();
        $this->assertTrue(method_exists($model, 'unitLayananOperator'));

        // OPERATOR_CALLCENTER memiliki sgroupCategoryId = 99 (Semua Layanan)
        $this->assertNull($model->unitLayananOperator('OPERATOR_CALLCENTER'));
        $this->assertNull($model->unitLayananOperator('OPERATOR_CALLCENTER', '99'));

        // Fallback sesi bernilai '', '0', atau '99' pada grup operator yang tidak ada di DB
        $this->assertNull($model->unitLayananOperator('OPERATOR_UJI_GLOBAL', ''));
        $this->assertNull($model->unitLayananOperator('OPERATOR_UJI_GLOBAL', '0'));
        $this->assertNull($model->unitLayananOperator('OPERATOR_UJI_GLOBAL', '99'));
    }

    public function testUnitLayananOperatorMengembalikanUnitIdUntukOperatorUnit(): void
    {
        $model = new ModelMaster();
        $this->assertTrue(method_exists($model, 'unitLayananOperator'));

        $this->assertSame('21', $model->unitLayananOperator('OPERATOR_KEUANGAN'));
        $this->assertSame('22', $model->unitLayananOperator('OPERATOR_AKADEMIK'));
        $this->assertSame('23', $model->unitLayananOperator('OPERATOR_KEMAHASISWAAN'));

        // Fallback ke $kategoriSesi bila grup operator belum terdaftar di tabel s_user_group
        $this->assertSame('21', $model->unitLayananOperator('OPERATOR_UJI_FALLBACK', '21'));
    }

    public function testOtentifikasiBangunSesiMengisiSusrCategoryIdDariGrup(): void
    {
        $isi = $this->baca('app/Controllers/Otentifikasi.php');

        $this->assertTrue(
            str_contains($isi, 'sgroupCategoryId'),
            'Otentifikasi::bangunSesi() wajib mengisi susrCategoryId dari s_user_group bila s_user.susrCategoryId kosong.'
        );
    }

    public function testTicketingControllerMemakaiUnitLayananOperator(): void
    {
        $isi = $this->baca('app/Controllers/Ticketing.php');

        $this->assertTrue(
            str_contains($isi, 'unitLayananOperator('),
            'Ticketing controller wajib membatasi index, response, bolehAksesTiket, dan export memakai unitLayananOperator().'
        );
        $this->assertTrue(
            str_contains($isi, 'unit_operator_id'),
            'Ticketing::index() wajib meneruskan unit_operator_id ke view.'
        );
    }

    public function testTicketingViewMenanganiUnitOperatorId(): void
    {
        $isi = $this->baca('app/Views/pages/ticketing/index.php');

        $this->assertTrue(
            str_contains($isi, 'unit_operator_id'),
            'View pages/ticketing/index.php wajib mengunci/memilih otomatis filter unit layanan saat unit_operator_id terisi.'
        );
    }

    public function testBaseControllerHomeDanLaporanLayananMemakaiUnitLayananOperator(): void
    {
        foreach ([
            'app/Controllers/BaseController.php',
            'app/Controllers/Home.php',
            'app/Controllers/Laporanlayanan.php',
        ] as $jalur) {
            $isi = $this->baca($jalur);

            $this->assertTrue(
                str_contains($isi, 'unitLayananOperator('),
                $jalur . ' wajib membatasi cakupan data operator unit memakai unitLayananOperator().'
            );
        }
    }
}
