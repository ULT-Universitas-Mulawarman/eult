<?php

/**
 * Helper captcha EULT.
 * Porting dari CI3 application/helpers/get_captcha_helper.php.
 * Nilai captcha disimpan di session (pengganti cookie mentah CI3),
 * perbandingan tetap case-insensitive seperti check_captcha CI3.
 */

if (! function_exists('eult_captcha_generate')) {
    /**
     * Membuat kode captcha baru dan menyimpannya ke session.
     */
    function eult_captcha_generate(int $panjang = 4): string
    {
        // Alfabet TANPA karakter ambigu (O/0, I/1, S/5, B/8, Z/2, G/6):
        // pasangan itu penyebab utama captcha terbaca salah oleh pengguna.
        $alfabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ3479';
        $angka   = range(0, strlen($alfabet) - 1);
        $hasil   = '';
        shuffle($angka);
        for ($x = 0; $x < $panjang; $x++) {
            $hasil .= substr($alfabet, $angka[$x], 1);
        }

        session()->set('captcha', $hasil);

        return $hasil;
    }
}

if (! function_exists('eult_captcha_check')) {
    /**
     * Memeriksa input captcha (case-insensitive).
     * Fallback cookie 'captcha_code' warisan CI3 SUDAH DIHAPUS TOTAL
     * (tanpa periode transisi) — validasi murni berasal dari session.
     */
    function eult_captcha_check(string $input): bool
    {
        $tersimpan = session()->get('captcha');

        if (! is_string($tersimpan) || $tersimpan === '') {
            return false;
        }

        return strtoupper($input) === strtoupper($tersimpan);
    }
}

if (! function_exists('eult_captcha_image')) {
    /**
     * Merender teks captcha menjadi gambar PNG terdistorsi (binary string).
     *
     * Memakai GD sebagai jalur utama (extension_loaded('gd')), dengan
     * fallback ke Imagick bila GD tidak tersedia di server. Distorsi
     * berupa garis acak + noise titik acak — cukup untuk mengurangi
     * kemudahan OCR trivial tanpa kompleksitas berlebih.
     *
     * Nilai captcha itu sendiri (session) TIDAK berubah cara
     * penyimpanan/pembandingannya — fungsi ini murni transformasi
     * render teks -> gambar untuk dikirim ke klien.
     */
    function eult_captcha_image(string $teks): string
    {
        // Canvas diperbesar dari 140x50 agar glyph cukup besar terbaca
        // di layar desktop maupun mobile tanpa perlu memperbesar halaman.
        $lebar  = 220;
        $tinggi = 80;

        if (extension_loaded('gd')) {
            return eult_captcha_image_gd($teks, $lebar, $tinggi);
        }

        if (extension_loaded('imagick')) {
            return eult_captcha_image_imagick($teks, $lebar, $tinggi);
        }

        throw new \RuntimeException('Tidak ada ekstensi PHP (gd/imagick) yang tersedia untuk merender captcha sebagai gambar.');
    }
}

if (! function_exists('eult_captcha_image_gd')) {
    /**
     * Implementasi render captcha via GD.
     *
     * @internal Dipanggil oleh eult_captcha_image(), tidak dipanggil langsung dari luar helper ini.
     */
    function eult_captcha_image_gd(string $teks, int $lebar, int $tinggi): string
    {
        $gambar = imagecreatetruecolor($lebar, $tinggi);

        $latar = imagecolorallocate($gambar, 255, 255, 255);
        imagefill($gambar, 0, 0, $latar);

        // Garis acak sebagai distorsi latar (dikurangi agar tidak
        // memotong keterbacaan huruf).
        for ($i = 0; $i < 4; $i++) {
            $warnaGaris = imagecolorallocate($gambar, random_int(140, 200), random_int(140, 200), random_int(140, 200));
            imageline($gambar, random_int(0, $lebar), random_int(0, $tinggi), random_int(0, $lebar), random_int(0, $tinggi), $warnaGaris);
        }

        // Noise titik acak (dikurangi dari 250 agar huruf tetap dominan).
        for ($i = 0; $i < 120; $i++) {
            $warnaNoise = imagecolorallocate($gambar, random_int(170, 225), random_int(170, 225), random_int(170, 225));
            imagesetpixel($gambar, random_int(0, $lebar - 1), random_int(0, $tinggi - 1), $warnaNoise);
        }

        $panjangTeks      = strlen($teks);
        $lebarPerKarakter = (int) floor($lebar / max(1, $panjangTeks));
        $fontTtf          = function_exists('imagettftext') ? eult_captcha_cari_font_imagick() : null;

        for ($i = 0; $i < $panjangTeks; $i++) {
            // Warna gelap bervariasi per karakter, kontras tetap tinggi.
            $warnaTeks = imagecolorallocate($gambar, random_int(10, 60), random_int(10, 60), random_int(60, 120));

            if ($fontTtf !== null) {
                $x = 14 + ($i * $lebarPerKarakter) + random_int(-2, 2);
                $y = random_int(52, 60);
                imagettftext($gambar, 34, random_int(-12, 12), $x, $y, $warnaTeks, $fontTtf, $teks[$i]);
            } else {
                $x = 14 + ($i * $lebarPerKarakter) + random_int(-2, 2);
                $y = random_int(28, 40);
                imagestring($gambar, 5, $x, $y, $teks[$i], $warnaTeks);
            }
        }

        ob_start();
        imagepng($gambar);
        $binerPng = (string) ob_get_clean();

        imagedestroy($gambar);

        return $binerPng;
    }
}

if (! function_exists('eult_captcha_image_imagick')) {
    /**
     * Implementasi render captcha via Imagick (fallback bila GD tidak tersedia).
     *
     * @internal Dipanggil oleh eult_captcha_image(), tidak dipanggil langsung dari luar helper ini.
     */
    function eult_captcha_image_imagick(string $teks, int $lebar, int $tinggi): string
    {
        $gambar = new \Imagick();
        $gambar->newImage($lebar, $tinggi, new \ImagickPixel('white'));
        $gambar->setImageFormat('png');

        $gambarGambar = new \ImagickDraw();

        // Garis acak sebagai distorsi latar
        for ($i = 0; $i < 6; $i++) {
            $gambarGambar->setStrokeColor(new \ImagickPixel(sprintf('rgb(%d,%d,%d)', random_int(120, 200), random_int(120, 200), random_int(120, 200))));
            $gambarGambar->line(random_int(0, $lebar), random_int(0, $tinggi), random_int(0, $lebar), random_int(0, $tinggi));
        }

        // Noise titik acak
        for ($i = 0; $i < 250; $i++) {
            $gambarGambar->setFillColor(new \ImagickPixel(sprintf('rgb(%d,%d,%d)', random_int(150, 220), random_int(150, 220), random_int(150, 220))));
            $gambarGambar->point(random_int(0, $lebar - 1), random_int(0, $tinggi - 1));
        }

        $fontTerdeteksi = eult_captcha_cari_font_imagick();
        if ($fontTerdeteksi !== null) {
            $gambarGambar->setFont($fontTerdeteksi);
        }

        $gambarGambar->setFillColor(new \ImagickPixel('rgb(30,30,30)'));
        $gambarGambar->setFontSize(44);
        $gambarGambar->annotation(14, 56, $teks);

        $gambar->drawImage($gambarGambar);

        $binerPng = $gambar->getImageBlob();

        $gambar->clear();
        $gambar->destroy();

        return $binerPng;
    }
}

if (! function_exists('eult_captcha_cari_font_imagick')) {
    /**
     * Mencari path font TTF yang tersedia di server untuk dipakai Imagick.
     * ImagickDraw::annotation() memerlukan font ter-resolve secara eksplisit
     * di beberapa environment (tidak selalu ada default font sistem yang
     * otomatis terdeteksi Freetype) — fungsi ini mencoba beberapa lokasi
     * umum font TTF di server Linux/macOS, mengembalikan null bila tidak
     * satu pun ditemukan (pemanggil tetap mencoba annotation() tanpa
     * setFont(), yang berfungsi normal di server yang punya default font).
     *
     * @internal Dipanggil oleh eult_captcha_image_imagick().
     */
    function eult_captcha_cari_font_imagick(): ?string
    {
        $kandidat = [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
            '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
        ];

        foreach ($kandidat as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
