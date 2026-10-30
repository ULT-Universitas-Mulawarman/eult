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

if (! function_exists('eult_upload_ticket')) {
    /**
     * Mengunggah lampiran tiket (field 'ticketArchiveId') lalu
     * mencatatnya ke d_archive via ModelMaster.
     *
     * @param array{url:string,type:string,size:int,namafile:string} $konfig
     */
    function eult_upload_ticket(array $konfig, array $paramArsip): bool
    {
        $request = service('request');
        $berkas  = $request->getFile('ticketArchiveId');

        if ($berkas === null || $berkas->getError() === UPLOAD_ERR_NO_FILE) {
            return false;
        }

        if (! $berkas->isValid()) {
            eult_message_kirim(eult_upload_pesan_galat($berkas->getError()), 'error');
        }

        $tujuan = rtrim($konfig['url'], '/') . '/';
        if (! is_dir($tujuan)) {
            mkdir($tujuan, 0755, true);
        }

        // Validasi ekstensi sesuai allowed_types CI3 (mis. 'pdf' atau 'pdf|jpg|png')
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

        // Validasi ukuran (CI3 max_size dalam KB)
        if ($berkas->getSizeByUnit('kb') > $konfig['size']) {
            eult_message_kirim('Ukuran file melebihi batas ' . eult_upload_max_mb() . ' MB.', 'error');
        }

        $namaBaru                    = $konfig['namafile'] . '.' . $ekstensi;
        $berkas->move($tujuan, $namaBaru, true);
        $paramArsip['archiveFile'] = $namaBaru;

        $model = new \App\Models\ModelMaster();

        return $model->tambah('d_archive', $paramArsip);
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

        if ($berkas->getSizeByUnit('kb') > $konfig['size']) {
            eult_message_kirim('Ukuran file melebihi batas ' . eult_upload_max_mb() . ' MB.', 'error');
        }

        $namaBaru = $konfig['namafile'] . '.' . $ekstensi;
        $berkas->move($tujuan, $namaBaru, true);

        return new \CodeIgniter\Files\File($tujuan . $namaBaru, true);
    }
}
