<?php

/**
 * Helper upload EULT.
 * Porting dari CI3 application/helpers/uploadfile_helper.php dan
 * uploadcustom_helper.php. CI3 memakai library upload internal;
 * di CI4 dipakai $request->getFile() + validasi manual agar
 * perilaku (tipe, ukuran, nama file) tetap sama.
 */

if (! function_exists('eult_upload_max_kb')) {
    /**
     * Batas ukuran berkas unggahan dalam KB (satuan yang dipakai konfigurasi
     * upload CI3). Satu sumber kebenaran: sebelumnya angka 15*1024 / 20*1024
     * ditulis ulang di enam tempat dan tidak konsisten dengan sisi klien.
     *
     * Default 10 MB, dapat dioverride lewat .env tanpa mengubah kode:
     *     EULT_UPLOAD_MAX_MB = 10
     *
     * PENTING: nilai ini hanya batas sisi aplikasi. Batas PHP
     * (upload_max_filesize/post_max_size) dan nginx (client_max_body_size)
     * harus >= nilai ini, kalau tidak berkas dipotong sebelum sampai ke
     * kode. Lihat docs/deploy.md.
     */
    function eult_upload_max_kb(): int
    {
        $mb = (int) (function_exists('env') ? env('EULT_UPLOAD_MAX_MB') : 0);

        if ($mb <= 0) {
            $mb = 10;
        }

        // Batasi agar tidak melampaui batas PHP yang wajar (512 MB).
        $mb = min($mb, 512);

        return $mb * 1024;
    }
}

if (! function_exists('eult_upload_max_mb')) {
    /**
     * Batas ukuran berkas unggahan dalam MB (untuk pesan ke pengguna).
     */
    function eult_upload_max_mb(): int
    {
        return (int) (eult_upload_max_kb() / 1024);
    }
}

if (! function_exists('eult_upload_melebihi_batas')) {
    /**
     * Apakah ukuran berkas melebihi batas.
     *
     * JANGAN membandingkan hasil getSizeByUnit('kb') dengan angka: method itu
     * mengembalikan STRING berformat number_format(), mis. "5,263.00". String
     * dengan koma BUKAN numerik, sehingga PHP 8 membandingkannya sebagai teks:
     *
     *     "5,263.00" > 10240   -> true   (karena '5' > '1')
     *     "1,536.00" > 10240   -> false  (karena ',' < '0')
     *
     * Akibatnya berkas 2-10 MB salah ditolak, sementara 1-2 MB lolos.
     * Perbandingan di sini memakai BYTE (integer) dari getSize(), jadi bebas
     * masalah format angka. getSizeByUnit() juga sudah deprecated sejak 4.6.
     *
     * @param int $ukuranByte Ukuran berkas dalam byte ($berkas->getSize()).
     * @param int $batasKb    Batas dalam KB (mis. eult_upload_max_kb()).
     */
    function eult_upload_melebihi_batas(int $ukuranByte, int $batasKb): bool
    {
        if ($batasKb <= 0) {
            return false;
        }

        return $ukuranByte > $batasKb * 1024;
    }
}

if (! function_exists('eult_upload_pesan_galat')) {
    /**
     * Pesan galat unggahan dalam bahasa Indonesia.
     *
     * Pesan bawaan CI4/PHP berbahasa Inggris dan tidak menyebut angka batas,
     * sehingga pengguna tidak tahu harus berbuat apa. Kasus paling sering:
     * berkas melebihi upload_max_filesize PHP (bukan batas aplikasi) — pesan
     * ini menjelaskannya agar tidak dilaporkan sebagai "aplikasi error".
     */
    function eult_upload_pesan_galat(int $kode): string
    {
        $batasMb = eult_upload_max_mb();

        return match ($kode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Ukuran berkas melebihi batas server. Maksimal ' . $batasMb . ' MB.',
            UPLOAD_ERR_PARTIAL    => 'Berkas hanya terunggah sebagian. Periksa koneksi lalu coba lagi.',
            UPLOAD_ERR_NO_FILE    => 'Tidak ada berkas yang dipilih.',
            UPLOAD_ERR_NO_TMP_DIR => 'Folder sementara server tidak tersedia. Hubungi administrator.',
            UPLOAD_ERR_CANT_WRITE => 'Server gagal menyimpan berkas. Hubungi administrator.',
            UPLOAD_ERR_EXTENSION  => 'Unggahan dihentikan oleh ekstensi PHP. Hubungi administrator.',
            default               => 'Berkas gagal diunggah (kode ' . $kode . ').',
        };
    }
}

if (! function_exists('eult_upload_periksa')) {
    /**
     * FASE 1 — memeriksa berkas TANPA menulis apa pun.
     *
     * Dipisah dari penyimpanan supaya controller bisa membatalkan request
     * SEBELUM baris tiket masuk database. Sebelumnya validasi dan penyimpanan
     * menyatu, sehingga tiket sudah ter-commit saat berkas ditolak —
     * menghasilkan tiket tanpa arsip (4.156 baris di produksi, 10,7%).
     *
     * Tidak melempar exception: pemanggil yang memutuskan cara merespons,
     * sehingga fungsi ini juga aman dipakai di dalam transaksi.
     *
     * @param array{url:string,type:string,size:int,namafile:string} $konfig
     * @param bool $wajib false = berkas boleh tidak ada (mis. petugas mengubah
     *                     tiket tanpa mengunggah ulang)
     *
     * @return array{ok:bool, pesan:string, ekstensi:string}
     */
    function eult_upload_periksa(?\CodeIgniter\HTTP\Files\UploadedFile $berkas, array $konfig, bool $wajib = true): array
    {
        if ($berkas === null || $berkas->getError() === UPLOAD_ERR_NO_FILE) {
            return [
                'ok'       => ! $wajib,
                'pesan'    => $wajib ? 'Berkas persyaratan wajib dilampirkan.' : '',
                'ekstensi' => '',
            ];
        }

        if (! $berkas->isValid()) {
            return ['ok' => false, 'pesan' => eult_upload_pesan_galat($berkas->getError()), 'ekstensi' => ''];
        }

        // Ekstensi sesuai allowed_types CI3 (mis. 'pdf' atau 'pdf|jpg|png')
        $diizinkan = explode('|', strtolower((string) $konfig['type']));
        $ekstensi  = strtolower($berkas->getExtension());

        if (! in_array($ekstensi, $diizinkan, true)) {
            return [
                'ok'       => false,
                'pesan'    => 'Tipe file tidak diizinkan. Hanya: ' . $konfig['type'],
                'ekstensi' => $ekstensi,
            ];
        }

        // Ekstensi bisa dipalsukan dengan rename — cocokkan isi berkas
        // (magic byte) dengan tipe yang diklaim.
        if (! eult_upload_konten_valid($berkas, $ekstensi)) {
            return [
                'ok'       => false,
                'pesan'    => 'Isi file tidak cocok dengan tipe ' . strtoupper($ekstensi) . '.',
                'ekstensi' => $ekstensi,
            ];
        }

        // Bandingkan BYTE, bukan getSizeByUnit('kb') yang mengembalikan string
        // berformat "5,263.00" dan membuat perbandingan jadi perbandingan teks.
        if (eult_upload_melebihi_batas((int) $berkas->getSize(), (int) $konfig['size'])) {
            return [
                'ok'       => false,
                'pesan'    => 'Ukuran file melebihi batas ' . eult_upload_max_mb() . ' MB.',
                'ekstensi' => $ekstensi,
            ];
        }

        return ['ok' => true, 'pesan' => '', 'ekstensi' => $ekstensi];
    }
}

if (! function_exists('eult_upload_simpan')) {
    /**
     * FASE 2 — memindahkan berkas dan mencatat d_archive. TIDAK memvalidasi.
     *
     * Wajib dipanggil setelah eult_upload_periksa() lolos. Bila insert
     * d_archive gagal, berkas yang sudah dipindah dihapus lagi agar tidak
     * menjadi berkas yatim.
     *
     * @param array{url:string,type:string,size:int,namafile:string} $konfig
     *
     * @return string|false Nama berkas tersimpan, atau false bila gagal.
     */
    function eult_upload_simpan(
        \CodeIgniter\HTTP\Files\UploadedFile $berkas,
        array $konfig,
        array $paramArsip,
        ?string $ekstensi = null,
    ): string|false {
        $tujuan = rtrim($konfig['url'], '/') . '/';

        if (! is_dir($tujuan) && ! @mkdir($tujuan, 0755, true) && ! is_dir($tujuan)) {
            log_message('error', 'EULT upload: folder tujuan tidak bisa dibuat: {tujuan}', ['tujuan' => $tujuan]);

            return false;
        }

        $ekstensi ??= strtolower($berkas->getExtension());
        $namaBaru = $konfig['namafile'] . '.' . $ekstensi;

        try {
            $berkas->move($tujuan, $namaBaru, true);
        } catch (\Throwable $e) {
            log_message('error', 'EULT upload: gagal memindahkan berkas: {pesan}', ['pesan' => $e->getMessage()]);

            return false;
        }

        $paramArsip['archiveFile'] = $namaBaru;

        $model = new \App\Models\ModelMaster();

        if (! $model->tambah('d_archive', $paramArsip)) {
            // Rollback berkas: baris d_archive tidak ada, berkas tidak boleh ada.
            @unlink($tujuan . $namaBaru);
            log_message('error', 'EULT upload: insert d_archive gagal, berkas dibatalkan: {berkas}', ['berkas' => $namaBaru]);

            return false;
        }

        return $namaBaru;
    }
}

if (! function_exists('eult_upload_hapus')) {
    /**
     * Menghapus berkas hasil eult_upload_simpan() — dipakai saat transaksi
     * tiket di-rollback, karena pemindahan berkas tidak ikut ter-rollback.
     *
     * @param array{url:string,type:string,size:int,namafile:string} $konfig
     */
    function eult_upload_hapus(array $konfig, ?string $namaBerkas): void
    {
        if ($namaBerkas === null || $namaBerkas === '') {
            return;
        }

        $jalur = rtrim($konfig['url'], '/') . '/' . basename($namaBerkas);

        if (is_file($jalur)) {
            @unlink($jalur);
        }
    }
}

if (! function_exists('eult_upload_ticket')) {
    /**
     * Pembungkus lama: validasi lalu simpan, membatalkan request bila gagal.
     *
     * Dipertahankan untuk pemanggil yang urutannya sudah aman (unggah lebih
     * dulu, baru menulis). Alur simpan tiket memakai eult_upload_periksa() +
     * eult_upload_simpan() secara terpisah agar bisa atomik.
     *
     * @param array{url:string,type:string,size:int,namafile:string} $konfig
     */
    function eult_upload_ticket(array $konfig, array $paramArsip): bool
    {
        $berkas = service('request')->getFile('ticketArchiveId');

        if ($berkas === null || $berkas->getError() === UPLOAD_ERR_NO_FILE) {
            return false;
        }

        $periksa = eult_upload_periksa($berkas, $konfig);

        if (! $periksa['ok']) {
            eult_message_kirim($periksa['pesan'], 'error');
        }

        return eult_upload_simpan($berkas, $konfig, $paramArsip, $periksa['ekstensi']) !== false;
    }
}

if (! function_exists('eult_upload_konten_valid')) {
    /**
     * Validasi isi berkas (magic byte) sesuai ekstensi yang diklaim:
     * PDF wajib berawalan %PDF-, gambar wajib terbaca getimagesize().
     * Ekstensi lain diperbolehkan (tidak ada magic byte yang dikenal).
     */
    function eult_upload_konten_valid(\CodeIgniter\HTTP\Files\UploadedFile $berkas, string $ekstensi): bool
    {
        if ($ekstensi === 'pdf') {
            $fp = @fopen($berkas->getTempName(), 'rb');

            if ($fp === false) {
                return false;
            }

            $magic = (string) fread($fp, 5);
            fclose($fp);

            return $magic === '%PDF-';
        }

        if (in_array($ekstensi, ['jpg', 'jpeg', 'png', 'gif'], true)) {
            return @getimagesize($berkas->getTempName()) !== false;
        }

        return true;
    }
}

if (! function_exists('eult_upload_custom')) {
    /**
     * Unggah generik untuk field arbitrer (porting uploadcustom CI3).
     * Mengembalikan instance File pada lokasi tujuan, sehingga
     * getFilename() berisi nama berkas sebenarnya (bukan nama
     * temporer upload PHP seperti /tmp/phpXXXX).
     *
     * @param array{url:string,type:string,size:int,namafile:string} $konfig
     */
    function eult_upload_custom(array $konfig, string $namaField): \CodeIgniter\Files\File
    {
        $request = service('request');
        $berkas  = $request->getFile($namaField);

        if ($berkas === null || ! $berkas->isValid()) {
            $pesan = $berkas instanceof \CodeIgniter\HTTP\Files\UploadedFile
                ? eult_upload_pesan_galat($berkas->getError())
                : 'File tidak ditemukan.';
            eult_message_kirim(strip_tags($pesan), 'error');
        }

        $tujuan = rtrim($konfig['url'], '/') . '/';
        if (! is_dir($tujuan)) {
            mkdir($tujuan, 0755, true);
        }

        $diizinkan = explode('|', strtolower($konfig['type']));
        $ekstensi  = strtolower($berkas->getExtension());
        if (! in_array($ekstensi, $diizinkan, true)) {
            eult_message_kirim('Tipe file tidak diizinkan. Hanya: ' . $konfig['type'], 'error');
        }

        // Ekstensi bisa dipalsukan dengan rename — cocokkan isi berkas
        // (magic byte) dengan tipe yang diklaim.
        if (! eult_upload_konten_valid($berkas, $ekstensi)) {
            eult_message_kirim('Isi file tidak cocok dengan tipe ' . strtoupper($ekstensi) . '.', 'error');
        }

        // Bandingkan BYTE, bukan getSizeByUnit('kb') (lihat catatan di
        // eult_upload_melebihi_batas).
        if (eult_upload_melebihi_batas((int) $berkas->getSize(), (int) $konfig['size'])) {
            eult_message_kirim('Ukuran file melebihi batas ' . eult_upload_max_mb() . ' MB.', 'error');
        }

        $namaBaru = $konfig['namafile'] . '.' . $ekstensi;
        $berkas->move($tujuan, $namaBaru, true);

        return new \CodeIgniter\Files\File($tujuan . $namaBaru, true);
    }
}
