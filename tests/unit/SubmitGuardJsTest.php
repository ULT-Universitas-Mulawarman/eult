<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Pengunci perilaku JS: form yang menyimpan lewat AJAX WAJIB memblokir
 * submit native (Enter pada field teks), karena endpoint tujuan
 * mengembalikan JSON yang akan ditampilkan browser sebagai halaman.
 *
 * Akar masalah: tombol Save ber-type="submit" sementara handler hanya
 * terpasang pada event klik — Enter memicu submit form biasa dan pengguna
 * melihat JSON mentah. Guard `submit.eultSave` mengalihkannya ke jalur
 * klik tombol yang sama.
 */
final class SubmitGuardJsTest extends CIUnitTestCase
{
    private function bacaJs(string $pathRelatif): string
    {
        $isi = file_get_contents(FCPATH . $pathRelatif);
        self::assertNotFalse($isi, "Berkas JS {$pathRelatif} harus ada.");

        return (string) $isi;
    }

    public function testLoginJsMengamankanTigaFormPublik(): void
    {
        $js = $this->bacaJs('assets/js/pages/custom/pages/user/login.js');

        foreach (['kt_login_form', 'kt_track_form', 'kt_create_form'] as $form) {
            $this->assertStringContainsString("'#{$form}').off('submit.eultSave')", $js, "Form {$form} harus punya guard submit.");
        }

        // Guard harus mengalihkan ke jalur AJAX klik tombol, bukan sekadar
        // memblokir Enter tanpa umpan balik.
        $this->assertStringContainsString("$('#kt_signin_submit').trigger('click')", $js);
        $this->assertStringContainsString("$('#kt_track_submit').trigger('click')", $js);
        $this->assertStringContainsString("$('#kt_create_submit').trigger('click')", $js);
    }

    public function testTicketingJsMengamankanFormSimpanDanCari(): void
    {
        $js = $this->bacaJs('assets/js/pages/custom/pages/ticketing/ticketing.js');

        foreach (['formSimpan', 'formAssign', 'formDeliver', 'formShow'] as $var) {
            $this->assertStringContainsString("{$var}.off('submit.eultSave')", $js, "Handler {$var} harus punya guard submit.");
        }

        // form_tandatangan memakai variabel lokal `form`.
        $this->assertStringContainsString("form.off('submit.eultSave')", $js);
        $this->assertStringContainsString("bindModalForm", $js);
    }

    public function testRefkategoriJsMengamankanFormFormFragmen(): void
    {
        $js = $this->bacaJs('assets/js/pages/custom/pages/referensi/refkategori.js');

        $this->assertStringContainsString("$(formCrud).off('submit.eultSave')", $js);
        $this->assertStringContainsString("$('#btn_save').trigger('click')", $js);
    }
}
