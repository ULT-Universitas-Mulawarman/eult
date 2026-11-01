<?php

if (! function_exists('eult_versi_aset')) {
    /**
     * Versi cache-buster aset berbasis waktu modifikasi file: nilai hanya
     * berubah saat filenya berubah sehingga HTTP cache tetap bekerja.
     * (Pendekatan lama date('YmdHis') berubah tiap detik dan membuat
     * browser tidak pernah menyimpan aset.)
     *
     * @param string $aset Jalur relatif terhadap public/ (FCPATH),
     *                     mis. 'assets/js/pages/eult-csrf.js'.
     */
    function eult_versi_aset(string $aset): string
    {
        $waktu = @filemtime(FCPATH . $aset);

        return $waktu !== false ? (string) $waktu : '1';
    }
}

if (! function_exists('eult_ikon_aman')) {
    /**
     * Merender tag ikon HTML hanya jika sesuai pola whitelist <i class="..."></i>,
     * selain itu seluruh string di-escape agar aman dari Stored XSS.
     */
    function eult_ikon_aman(?string $ikon): string
    {
        $mentah = trim((string) $ikon);
        if ($mentah === '') {
            return '';
        }

        if (preg_match('/^<i\s+class="[a-zA-Z0-9_\-\s]+"><\/i>$/', $mentah) === 1) {
            return $mentah;
        }

        return esc($mentah);
    }
}

if (! function_exists('eult_samarkan_email')) {
    /**
     * Menyamarkan alamat email untuk tampilan publik agar PII tidak terekspos utuh.
     */
    function eult_samarkan_email(?string $email): string
    {
        $teks = trim((string) $email);
        if ($teks === '') {
            return '';
        }

        $posisiAt = strpos($teks, '@');
        if ($posisiAt === false) {
            $panjang = strlen($teks);
            return $panjang <= 2 ? str_repeat('*', $panjang) : substr($teks, 0, 2) . '***';
        }

        $lokal  = substr($teks, 0, $posisiAt);
        $domain = substr($teks, $posisiAt + 1);
        $tampil = strlen($lokal) <= 2 ? substr($lokal, 0, 1) : substr($lokal, 0, 2);

        return $tampil . '***@' . $domain;
    }
}

if (! function_exists('eult_samarkan_telepon')) {
    /**
     * Menyamarkan nomor telepon untuk tampilan publik agar PII tidak terekspos utuh.
     */
    function eult_samarkan_telepon(?string $telepon): string
    {
        $teks = trim((string) $telepon);
        if ($teks === '') {
            return '';
        }

        $panjang = strlen($teks);
        if ($panjang <= 6) {
            return substr($teks, 0, 2) . str_repeat('*', max(1, $panjang - 2));
        }

        $awal  = substr($teks, 0, 4);
        $akhir = substr($teks, -2);
        $bintang = str_repeat('*', max(4, $panjang - 6));

        return $awal . $bintang . $akhir;
    }
}
