<?php

/**
 * Helper role pemohon EULT.
 *
 * Aplikasi membedakan tiga jenis pemohon berdasarkan hasil verifikasi OSM:
 *
 *   internal  -> NIM/NIP ditemukan di OSM (mahasiswa atau pegawai)
 *   umum      -> nomor identitas TIDAK ditemukan di OSM (pemohon luar/NIK)
 *
 * Perbedaan ini menentukan DAFTAR LAYANAN yang boleh diajukan. Pemohon umum
 * dibatasi ke layanan publik (lihat ModelMaster::layananUmumIds()); pemohon
 * internal boleh mengajukan semua layanan.
 *
 * Role disimpan di session saat getIdentitas() memverifikasi nomor identitas,
 * lalu dibaca ulang oleh getLayanan() (untuk menyaring dropdown) dan oleh
 * savetiket()/save() (untuk menegakkan aturan di server).
 */

if (! function_exists('eult_pemohon_role_simpan')) {
    /**
     * Simpan hasil verifikasi identitas ke session.
     *
     * Peta identitas -> role disimpan (bukan satu nilai tunggal) supaya
     * savetiket() bisa memeriksa role untuk identitas yang BENAR-BENAR
     * dikirim, bukan identitas terakhir yang dicek di form.
     *
     * @param string $role 'internal' atau 'umum'
     */
    function eult_pemohon_role_simpan(string $identitas, string $role): void
    {
        if ($identitas === '') {
            return;
        }

        $sesi = session();
        $peta = $sesi->get('eult_pemohon_role');
        $peta = is_array($peta) ? $peta : [];

        $peta[$identitas] = $role === 'internal' ? 'internal' : 'umum';

        // Batasi ukuran session: hanya beberapa identitas terakhir yang
        // relevan (pengguna biasanya mengisi satu form pada satu waktu).
        if (count($peta) > 5) {
            $peta = array_slice($peta, -5, null, true);
        }

        $sesi->set('eult_pemohon_role', $peta);
        $sesi->set('eult_pemohon_role_terakhir', $peta[$identitas]);
    }
}

if (! function_exists('eult_pemohon_role')) {
    /**
     * Role yang tercatat untuk satu nomor identitas, atau null bila belum
     * pernah diverifikasi pada session ini.
     */
    function eult_pemohon_role(string $identitas): ?string
    {
        $peta = session()->get('eult_pemohon_role');

        if (! is_array($peta) || ! isset($peta[$identitas])) {
            return null;
        }

        return $peta[$identitas] === 'internal' ? 'internal' : 'umum';
    }
}

if (! function_exists('eult_pemohon_role_terakhir')) {
    /**
     * Role terakhir yang diverifikasi pada session ini.
     *
     * Dipakai getLayanan(): endpoint itu hanya menerima penanda "identitas
     * valid" dari klien, bukan nomor identitasnya, sehingga role dibaca dari
     * session.
     */
    function eult_pemohon_role_terakhir(): ?string
    {
        $role = session()->get('eult_pemohon_role_terakhir');

        if (! is_string($role) || $role === '') {
            return null;
        }

        return $role === 'internal' ? 'internal' : 'umum';
    }
}

if (! function_exists('eult_pemohon_umum')) {
    /**
     * Apakah pemohon tergolong umum.
     *
     * null (belum diverifikasi) diperlakukan sebagai umum — pilihan aman:
     * tanpa verifikasi, pemohon tidak boleh mengakses seluruh layanan.
     */
    function eult_pemohon_umum(?string $role = null): bool
    {
        $role ??= eult_pemohon_role_terakhir();

        return $role !== 'internal';
    }
}
