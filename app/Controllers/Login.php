<?php

namespace App\Controllers;

use App\Libraries\Enkripsi;
use App\Libraries\OsmClient;
use App\Libraries\PengirimEmail;
use App\Models\ModelLogin;
use App\Models\ModelTicketing;

/**
 * Controller publik EULT (porting CI3 Login.php).
 * Menangani form login/buat/lacak tiket + AJAX identitas/layanan/syarat.
 */
class Login extends BaseController
{
    protected ?string $judul = 'Login';

    protected ?string $controllerName = 'login';

    protected ?string $modelName = 'ModelTicketing';

    private ModelTicketing $tiket;

    private ModelLogin $masuk;

    private Enkripsi $enkripsi;

    private OsmClient $osm;

    private PengirimEmail $email;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);

        $this->tiket    = new ModelTicketing();
        $this->masuk    = new ModelLogin();
        $this->enkripsi = new Enkripsi();
        $this->osm      = new OsmClient();
        $this->email    = new PengirimEmail();
    }

    public function index(): string
    {
        // Nilai captcha TIDAK PERNAH dikirim plaintext ke view — hanya
        // di-generate/disimpan ke session; view merender gambar via
        // endpoint captchaImage() dengan nonce cache-busting.
        eult_captcha_generate(4);

        return view('layouts/login', [
            'captcha_image_url' => $this->urlGambarCaptcha(),
            'r_priority'        => $this->tiket->tabelRef('r_priority'),
            'datas'             => false,
        ]);
    }

    /**
     * URL endpoint gambar captcha dengan nonce cache-busting, dibaca
     * ulang oleh browser setiap kali dipanggil (index, refresh, atau
     * respons AJAX new_captcha) sehingga TIDAK PERNAH ada string
     * captcha plaintext yang ikut terkirim ke klien.
     */
    private function urlGambarCaptcha(): string
    {
        // Nonce acak per panggilan (bukan time() resolusi detik): dua
        // refresh berurutan dalam detik yang sama TIDAK BOLEH menghasilkan
        // URL identik yang dilayani cache browser dengan gambar lama.
        return base_url('login/captcha_image') . '?t=' . time() . bin2hex(random_bytes(4));
    }

    /**
     * Menyajikan captcha CURRENT (session) sebagai gambar PNG.
     * TIDAK men-generate ulang nilai captcha — nilai yang divalidasi
     * eult_captcha_check() harus sama dengan yang ditampilkan di gambar.
     */
    public function captchaImage(): \CodeIgniter\HTTP\ResponseInterface
    {
        $teks = session()->get('captcha');

        if (! is_string($teks) || $teks === '') {
            $teks = eult_captcha_generate(4);
        }

        return $this->response
            ->setHeader('Content-Type', 'image/png')
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->setHeader('Pragma', 'no-cache')
            ->setBody(eult_captcha_image($teks));
    }

    public function cektiket()
    {
        if (! $this->validate(['nomorTiket' => 'required'])) {
            return $this->response->setJSON([
                'status'  => 'danger',
                'message' => 'Nomor tiket wajib diisi.',
            ]);
        }

        $nomorTiket = (string) $this->request->getPost('nomorTiket');
        $datas      = $this->tiket->byId(['ticketTrackingId' => $nomorTiket]);

        if ($datas !== false) {
            $kunci = $this->enkripsi->encode($nomorTiket);

            // encode() mengembalikan false bila kunci enkripsi production
            // tidak terisi (remediasi K3) — JANGAN kirim redirect_url
            // buntu "cektiket/index/" yang pasti memantul ke homepage.
            if ($kunci === false || $kunci === '') {
                log_message('critical', 'EULT cektiket: encode gagal untuk tiket {tiket} — kunci enkripsi tidak terisi', ['tiket' => $nomorTiket]);

                return $this->response->setJSON([
                    'status'  => 'danger',
                    'message' => 'Tiket ditemukan, tetapi tautan lacak tidak dapat dibuat (konfigurasi enkripsi belum lengkap). Hubungi administrator.',
                ]);
            }

            return $this->response->setJSON([
                'status'       => 'success',
                'message'      => 'Nomor tiket ditemukan, mengalihkan halaman...',
                'redirect_url' => base_url('cektiket/index/') . $kunci,
            ]);
        }

        return $this->response->setJSON([
            'status'  => 'danger',
            'message' => 'Nomor tiket tidak ditemukan.',
        ]);
    }

    public function savetiket()
    {
        $aturan = [
            'captcha'          => 'required',
            'ticketCategories' => 'required',
            'ticketEmail'      => 'required|valid_email',
            'ticketNoHp'       => 'required',
            'ticketSubject'    => 'required',
            'ticketMessage'    => 'required',
        ];

        if (! $this->validate($aturan)) {
            eult_captcha_generate(4);

            return $this->response->setJSON([
                'status'      => 'danger',
                'message'     => strip_tags(implode(' ', array_values($this->validator->getErrors()))),
                'new_captcha' => $this->urlGambarCaptcha(),
            ]);
        }

        if (! eult_captcha_check((string) $this->request->getPost('captcha'))) {
            eult_captcha_generate(4);

            return $this->response->setJSON([
                'status'      => 'danger',
                'message'     => 'CAPTCHA yang Anda masukkan tidak valid.',
                'new_captcha' => $this->urlGambarCaptcha(),
            ]);
        }

        if (! $this->request->isAJAX()) {
            return $this->response->setJSON([
                'status'  => 'danger',
                'message' => 'Ooops!! Something Wrong!!',
            ]);
        }

        // Guard FK 1452: pastikan kategori yang dipilih benar-benar ada di
        // ref_layanan database referensi aktif. Tanpa ini, nilai basi/tidak
        // valid lolos validasi `required` lalu gagal di INSERT dengan pesan
        // mentah "Cannot add or update a child row...".
        $kategoriDipilih = (string) $this->request->getPost('ticketCategories');

        if (! $this->tiket->layananValid($kategoriDipilih)) {
            eult_captcha_generate(4);
            log_message('error', 'EULT savetiket ditolak: ticketCategories tidak valid ({kategori})', ['kategori' => $kategoriDipilih]);

            return $this->response->setJSON([
                'status'      => 'danger',
                'message'     => 'Kategori layanan tidak valid atau sudah tidak tersedia. Silakan pilih ulang kategori layanan.',
                'new_captcha' => $this->urlGambarCaptcha(),
            ]);
        }

        $nama     = (string) $this->request->getPost('ticketName');
        $email    = (string) $this->request->getPost('ticketEmail');
        $kodeAcak = eult_generate_kode();

        // ---------------------------------------------------------------
        // PENCEGAHAN DUPLIKAT (lapis 1) — idempotency token.
        //
        // Menutup double-click dan retry cepat: token yang sama hanya boleh
        // menghasilkan satu tiket. Token dirotasi setelah sukses, sehingga
        // pengiriman berikutnya (permintaan berbeda) tetap bisa.
        // ---------------------------------------------------------------
        $tokenDikirim = (string) $this->request->getPost('submitToken');

        if (! eult_submit_token_cocok($tokenDikirim)) {
            eult_captcha_generate(4);

            return $this->response->setJSON([
                'status'      => 'danger',
                'message'     => 'Permintaan ini sudah diproses sebelumnya. Silakan periksa email Anda untuk nomor tiket, atau muat ulang halaman untuk mengirim permintaan baru.',
                'new_captcha' => $this->urlGambarCaptcha(),
            ]);
        }

        // ---------------------------------------------------------------
        // PENCEGAHAN DUPLIKAT (lapis 2) — tiket serupa.
        //
        // Bila pengguna mengulang setelah menerima galat (mis. unggahan
        // ditolak), kembalikan nomor tiket yang SUDAH ADA alih-alih membuat
        // tiket baru. Ini yang membuat pengalaman "gagal lalu coba lagi"
        // tidak menghasilkan duplikat.
        // ---------------------------------------------------------------
        $identitas = (string) $this->request->getPost('ticketIdentitas');
        $subjek    = (string) $this->request->getPost('ticketSubject');
        $pesan     = (string) $this->request->getPost('ticketMessage');

        $serupa = $this->tiket->tiketSerupa($identitas, $subjek, $pesan);

        if ($serupa !== false) {
            log_message('info', 'EULT savetiket: permintaan serupa dikenali, memakai tiket {tiket}', ['tiket' => $serupa['ticketTrackingId']]);

            return $this->response->setJSON([
                'status'       => 'success',
                'message'      => 'Permintaan yang sama sudah kami terima sebelumnya. Nomor tiket Anda adalah '
                    . $serupa['ticketTrackingId']
                    . '. <br/> Catat nomor tiket anda dan cek progress secara berkala pada menu lacak tiket.',
                'new_captcha'  => $this->urlGambarCaptcha(),
                'submit_token' => eult_submit_token(true),
            ]);
        }

        // ---------------------------------------------------------------
        // FASE VALIDASI — tidak menulis apa pun.
        //
        // Berkas diperiksa SEBELUM tiket di-INSERT. Sebelumnya tiket
        // disimpan lebih dulu lalu berkas divalidasi, sehingga penolakan
        // berkas meninggalkan tiket tanpa arsip (4.156 baris di produksi)
        // dan pengguna mengulang → duplikat.
        // ---------------------------------------------------------------
        $berkas = $this->request->getFile('ticketArchiveId');

        $periksaBerkas = eult_upload_periksa($berkas, [
            'url'      => WRITEPATH . 'uploads/ticketing/',
            'type'     => 'pdf',
            'size'     => eult_upload_max_kb(),
            'namafile' => '',
        ]);

        if (! $periksaBerkas['ok']) {
            eult_captcha_generate(4);

            return $this->response->setJSON([
                'status'      => 'danger',
                'message'     => $periksaBerkas['pesan'],
                'new_captcha' => $this->urlGambarCaptcha(),
            ]);
        }

        // Alokasi nomor + insert dikunci per kode acak: dua permintaan
        // bersamaan dengan kode sama bisa membaca urutan terakhir yang
        // sama (read-then-write) dan menghasilkan ticketTrackingId ganda.
        // Lihat ModelTicketing::kunciNomorTiket().
        $this->tiket->kunciNomorTiket($kodeAcak);

        $langkah      = 'alokasi nomor tiket';
        $pesanGalat   = null;
        $proses       = false;
        $berkasTersimpan = null;
        $konfig       = null;
        $db           = $this->tiket->dbAktif();

        try {
            $idTiket   = $this->tiket->nomorTiketBerikutnya($kodeAcak);
            $arsipId   = str_replace('-', '', $idTiket);
            $langkah   = 'arsip tiket';
            $arsipBaru = eult_auto_increment('d_archive', 'archiveId', $arsipId, ['archiveTrackingId' => $idTiket]);

            $konfig = [
                'url'      => WRITEPATH . 'uploads/ticketing/',
                'type'     => 'pdf',
                'size'     => eult_upload_max_kb(),
                'namafile' => 'TIKET_' . $arsipId . '_' . date('YmdHis'),
            ];

            $paramFile = [
                'archiveId'         => $arsipBaru,
                'archiveTrackingId' => $idTiket,
                'archiveJenis'      => 'TIKET',
            ];

            $param = [
                'ticketIdentitas'  => (string) $this->request->getPost('ticketIdentitas'),
                'ticketName'       => $nama,
                'ticketCategories' => $kategoriDipilih,
                'ticketEmail'      => $email,
                'ticketNoHp'       => (string) $this->request->getPost('ticketNoHp'),
                'ticketSubject'    => (string) $this->request->getPost('ticketSubject'),
                'ticketPriority'   => (string) $this->request->getPost('ticketPriority'),
                'ticketMessage'    => (string) $this->request->getPost('ticketMessage'),
                'ticketCreated'    => date('Y-m-d H:i:s'),
                'ticketStatus'     => 1,
                'ticketCreatedBy'  => '',
                'ticketArchiveId'  => $arsipBaru,
                'ticketTrackingId' => $idTiket,
            ];

            // -----------------------------------------------------------
            // FASE TULIS — tiket dan arsip dalam SATU transaksi.
            //
            // CI4 mendukung transaksi bersarang: ModelMaster::tambah() di
            // dalam transaksi ini hanya menaikkan transDepth, tidak commit
            // lebih awal. Bila salah satu langkah gagal, keduanya batal.
            // -----------------------------------------------------------
            $db->transBegin();

            $langkah = 'simpan tiket';
            $proses  = $this->tiket->tambah('d_ticketing', $param);

            if ($proses) {
                $langkah          = 'simpan arsip';
                $berkasTersimpan  = eult_upload_simpan($berkas, $konfig, $paramFile, $periksaBerkas['ekstensi']);
                $proses           = $berkasTersimpan !== false;
            }

            if ($proses) {
                $db->transCommit();
            } else {
                $db->transRollback();
                // Pemindahan berkas tidak ikut ter-rollback oleh database.
                eult_upload_hapus($konfig, $berkasTersimpan);
                $berkasTersimpan = null;
            }
        } catch (\Throwable $e) {
            $pesanGalat = $e->getMessage();

            if ($db->transDepth > 0) {
                $db->transRollback();
            }

            if ($konfig !== null) {
                eult_upload_hapus($konfig, $berkasTersimpan);
            }
            $berkasTersimpan = null;

            log_message('error', 'EULT savetiket gagal pada langkah {langkah}: {pesan}', ['langkah' => $langkah, 'pesan' => $pesanGalat]);
        } finally {
            // transStatus tetap false setelah rollback (mode strict). Reset
            // agar pesan galat dan query berikutnya di request ini berjalan.
            if ($db->transDepth === 0) {
                $db->resetTransStatus();
            }

            $this->tiket->lepasKunciNomorTiket($kodeAcak);
        }

        if ($proses) {
            eult_save_history('Tiket Telah Dibuat Oleh ' . $nama, $idTiket);

            $datas = $this->tiket->byId(['ticketTrackingId' => $idTiket]);
            $this->email->buat($email, 'Tiket EULT UNMUL #' . $idTiket, $datas);

            eult_captcha_generate(4);

            return $this->response->setJSON([
                'status'       => 'success',
                'message'      => 'Permintaan layanan anda berhasil disimpan, nomor tiket anda adalah ' . $idTiket . '. <br/> Catat nomor tiket anda dan cek progress secara berkala pada menu lacak tiket.',
                'new_captcha'  => $this->urlGambarCaptcha(),
                // Rotasi token: pengiriman berikutnya = permintaan baru.
                'submit_token' => eult_submit_token(true),
            ]);
        }

        // Galat DB dibaca dari catatan ModelMaster (bukan error() koneksi) karena
        // RELEASE_LOCK di finally di atas sudah mereset error() menjadi 0 / kosong.
        $detail = $pesanGalat ?? $this->tiket->pesanGalat($langkah);
        log_message('error', 'EULT savetiket gagal ({langkah}): {detail}', ['langkah' => $langkah, 'detail' => $detail]);

        return $this->response->setJSON([
            'status'  => 'danger',
            'message' => 'Permintaan layanan anda gagal disimpan, ' . $detail,
        ]);
    }

    public function getIdentitas()
    {
        $id  = (string) $this->request->getPost('id');
        $mhs = $this->osm->mhsId2($id);

        if (is_object($mhs) && ! empty($mhs->nim)) {
            return $this->response->setJSON([
                'status'  => true,
                'ismhs'   => true,
                'datamhs' => ['name' => $mhs->peserta_didik->nama ?? ''],
            ]);
        }

        $pegawai = $this->osm->pegawaiId($id);
        if ($pegawai == true) {
            $nama = is_object($pegawai) ? ($pegawai->nama ?? '') : '';

            return $this->response->setJSON([
                'status'      => true,
                'ismhs'       => false,
                'datapegawai' => ['name' => $nama],
            ]);
        }

        // Pemohon umum: nomor identitas yang bukan NIM/NIP kampus tetap
        // dibuka kategorinya, nama diisi manual oleh pemohon.
        //
        // Panjang yang diterima:
        //   10 digit -> NIM lama (juga NIK sebagian daerah)
        //   16 digit -> NIK KTP
        // NIM/NIP yang ditemukan di OSM sudah ditangani di atas; blok ini
        // hanya berlaku bila OSM tidak mengembalikan data.
        if (in_array(strlen($id), [10, 16], true) && ctype_digit($id)) {
            return $this->response->setJSON([
                'status' => true,
                'umum'   => true,
                'name'   => '',
            ]);
        }

        return $this->response->setJSON(['status' => false, 'message' => 'Data Tidak Ditemukan']);
    }

    public function getLayanan()
    {
        $id = (string) $this->request->getPost('id');

        $datas = $id === 'true' ? $this->masuk->getLayanan() : $this->masuk->getLayanan($id);

        if ($datas !== false && !empty($datas)) {
            $currentGroup = null;
            $opsi = '<option value="">Pilih Kategori Layanan Kampus</option>';
            foreach ($datas as $row) {
                $group = $row['jenislayananNama'];
                if ($group !== $currentGroup) {
                    if ($currentGroup !== null) {
                        $opsi .= '</optgroup>';
                    }
                    $currentGroup = $group;
                    $opsi .= '<optgroup label="' . esc($currentGroup) . '">';
                }
                $opsi .= '<option value="' . esc($row['layananId']) . '">' . esc($row['layananNama']) . ' (' . esc($row['unitNama']) . ')</option>';
            }
            if ($currentGroup !== null) {
                $opsi .= '</optgroup>';
            }

            return $this->response->setBody($opsi);
        }

        return $this->response->setBody('<option value="">Masukkan nomor identitas terlebih dahulu...</option>');
    }

    public function getSyarat()
    {
        $layananId = (string) $this->request->getPost('id');

        $syarat = $this->masuk->tabelRef('r_berkas_layanan', ['berkasidLayanan' => $layananId]);

        if ($syarat !== false) {
            $daftar = '<h5>Persyaratan File yang harus Upload</h5><ol>';
            foreach ($syarat as $r) {
                $daftar .= '<li>' . $r['berkasNama'] . '</li>';
            }
            $daftar .= '</ol>';
        } else {
            $daftar = 'Layanan tidak memerlukan file untuk diupload';
        }

        return $this->response->setBody($daftar);
    }

    public function refreshCaptcha()
    {
        // Regenerate nilai session captcha, TAPI JANGAN kirim string
        // plaintext-nya ke klien — kirim URL endpoint gambar (dengan
        // nonce cache-busting) yang membaca ulang session terkini.
        eult_captcha_generate(4);

        return $this->response->setJSON([
            'captcha_image_url' => $this->urlGambarCaptcha(),
        ]);
    }
}
