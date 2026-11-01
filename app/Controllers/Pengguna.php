<?php

namespace App\Controllers;

use App\Libraries\Enkripsi;
use App\Models\ModelPengguna;

/**
 * Master pengguna + reset password (porting CI3 Pengguna.php).
 */
class Pengguna extends BaseController
{
    protected ?string $judul = 'Pengguna';

    protected ?string $controllerName = 'pengguna';

    protected ?string $pathPage = 'pages/pengguna/';

    protected ?string $pathJs = 'user/';

    private ModelPengguna $pengguna;

    private Enkripsi $enkripsi;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);

        $this->pengguna = new ModelPengguna();
        $this->enkripsi = new Enkripsi();
    }

    public function index(): string
    {
        $data                      = $this->getMaster($this->pathPage . $this->pageIndex);
        $data['scripts']           = [$this->pathJs . $this->controllerName];
        $data['datas']             = $this->pengguna->semua();
        $data['create_url']        = site_url($this->controllerName . '/create') . '/';
        $data['update_url']        = site_url($this->controllerName . '/update') . '/';
        $data['delete_url']        = site_url($this->controllerName . '/delete') . '/';
        $data['resetpassword_url'] = site_url($this->controllerName . '/resetpassword') . '/';

        return view($this->template, $data);
    }

    public function create(): string
    {
        $data                 = $this->getMaster($this->pathPage . 'form');
        $data['scripts']      = [$this->pathJs . $this->controllerName];
        $data['save_url']     = site_url($this->controllerName . '/save') . '/';
        $data['status_page']  = 'Create';
        $data['datas']        = false;
        $data['s_user_group'] = $this->pengguna->tabelRef('s_user_group');

        return view($this->template, $data);
    }

    public function update(string $kunci = ''): string
    {
        $terbuka              = $this->enkripsi->decode($kunci);
        $data                 = $this->getMaster($this->pathPage . 'form');
        $data['scripts']      = [$this->pathJs . $this->controllerName];
        $data['r_category']   = $this->pengguna->tabelRef('r_category');
        $data['save_url']     = site_url($this->controllerName . '/save') . '/';
        $data['status_page']  = 'Update';
        $data['datas']        = (is_string($terbuka) && $terbuka !== '')
            ? $this->pengguna->byId(['susrNama' => $terbuka])
            : false;
        $data['s_user_group'] = $this->pengguna->tabelRef('s_user_group');

        return view($this->template, $data);
    }

    public function save()
    {
        $namaLama = (string) $this->request->getPost('susrNamaOld');

        $aturanNama = $namaLama === '' ? 'required|is_unique[s_user.susrNama]' : 'required';

        if (! $this->validate([
            'susrNama'       => $aturanNama,
            'susrSgroupNama' => 'required',
            'susrProfil'     => 'required',
        ])) {
            eult_message_kirim('Ooops!! Something Wrong!!', 'error');
        }

        $nama  = (string) $this->request->getPost('susrNama');
        $grup  = (string) $this->request->getPost('susrSgroupNama');
        $katId = trim((string) $this->request->getPost('susrCategoryId'));

        // Cegah tabrakan nama pengguna ke akun lain saat melakukan perubahan username
        if ($namaLama !== '' && $nama !== $namaLama && $this->pengguna->byId(['susrNama' => $nama]) !== false) {
            eult_message_kirim('Username sudah digunakan oleh pengguna lain.', 'error');
        }

        $param = [
            'susrNama'       => $nama,
            'susrSgroupNama' => $grup,
            'susrProfil'     => (string) $this->request->getPost('susrProfil'),
            'susrCategoryId' => $katId === '' ? null : (int) $katId,
        ];

        $db = $this->pengguna->dbAktif();

        if ($namaLama === '') {
            // Setel hash password awal dan waktu login awal untuk akun baru
            $param['susrPassword']  = password_hash(eult_generate_password(), PASSWORD_BCRYPT);
            $param['susrLastLogin'] = date('Y-m-d H:i:s');

            $db->transStart();
            $proses = $this->pengguna->tambah('s_user', $param)
                && $this->pengguna->tambah('s_user_group_user', ['sgroupSusrNama' => $nama, 'sgroupSgroupNama' => $grup]);
            $db->transComplete();
        } else {
            $dataLama       = $this->pengguna->byId(['susrNama' => $namaLama]);
            $grupUtamaLama  = is_array($dataLama) ? (string) ($dataLama['susrSgroupNama'] ?? '') : '';
            $keanggotaanLama = $this->pengguna->tabelRef('s_user_group_user', ['sgroupSusrNama' => $namaLama]);
            $daftarGrupBaru = [$grup];

            if (is_array($keanggotaanLama)) {
                foreach ($keanggotaanLama as $barisGrup) {
                    $namaGrupLama = (string) ($barisGrup['sgroupSgroupNama'] ?? '');
                    if ($namaGrupLama !== '' && $namaGrupLama !== $grupUtamaLama && ! in_array($namaGrupLama, $daftarGrupBaru, true)) {
                        $daftarGrupBaru[] = $namaGrupLama;
                    }
                }
            }

            // Sinkronkan s_user dan s_user_group_user dalam satu transaksi atomik
            $db->transStart();
            $proses = $this->pengguna->hapus('s_user_group_user', ['sgroupSusrNama' => $namaLama])
                && $this->pengguna->ubah('s_user', $param, ['susrNama' => $namaLama]);

            if ($proses) {
                foreach ($daftarGrupBaru as $itemGrup) {
                    if (! $this->pengguna->tambah('s_user_group_user', ['sgroupSusrNama' => $nama, 'sgroupSgroupNama' => $itemGrup])) {
                        $proses = false;
                        break;
                    }
                }
            }
            $db->transComplete();
        }

        if ($db->transStatus() && ! empty($proses)) {
            eult_message_kirim($this->judul . ' Berhasil Disimpan', 'success');
        }

        $galat = $this->pengguna->dbAktif()->error();
        eult_message_kirim($this->judul . ' Gagal Disimpan, ' . ($galat['code'] ?? '') . ': ' . ($galat['message'] ?? ''), 'error');
    }

    public function delete(?string $kunci = null)
    {
        $kunci ??= (string) $this->request->getPost('key');
        $terbuka = $this->enkripsi->decode($kunci);

        if (! is_string($terbuka) || $terbuka === '') {
            eult_message_kirim('Kunci pengguna tidak valid.', 'error');
        }

        // Tolak penghapusan akun milik pengguna yang sedang aktif login
        $akunAktif = (string) (($this->sesiLogin() ?? [])['susrNama'] ?? '');
        if ($akunAktif !== '' && $terbuka === $akunAktif) {
            eult_message_kirim('Anda tidak dapat menghapus akun yang sedang digunakan.', 'error');
        }

        if ($this->pengguna->byId(['susrNama' => $terbuka]) === false) {
            eult_message_kirim($this->judul . ' tidak ditemukan.', 'error');
        }

        // FK s_user_group_user ON DELETE RESTRICT: hapus baris anak dulu
        // dalam satu transaksi agar tidak yatim bila salah satu gagal.
        $db = $this->pengguna->dbAktif();
        $db->transStart();
        $anak  = $this->pengguna->hapus('s_user_group_user', ['sgroupSusrNama' => $terbuka]);
        $induk = $anak ? $this->pengguna->hapus('s_user', ['susrNama' => $terbuka]) : false;
        $db->transComplete();

        if ($db->transStatus() && ! empty($induk)) {
            eult_message_kirim($this->judul . ' Berhasil Dihapus', 'success');
        }

        $galat = $db->error();
        eult_message_kirim($this->judul . ' Gagal Dihapus, ' . ($galat['code'] ?? '') . ': ' . ($galat['message'] ?? ''), 'error');
    }

    public function resetpassword(?string $kunci = null)
    {
        $kunci ??= (string) $this->request->getPost('key');
        $terbuka = $this->enkripsi->decode($kunci);

        if (! is_string($terbuka) || $terbuka === '') {
            eult_message_kirim('Kunci pengguna tidak valid.', 'error');
        }

        if ($this->pengguna->byId(['susrNama' => $terbuka]) === false) {
            eult_message_kirim($this->judul . ' tidak ditemukan.', 'error');
        }

        $sandi = eult_generate_password();

        $proses = $this->pengguna->ubah('s_user', ['susrPassword' => password_hash($sandi, PASSWORD_DEFAULT)], ['susrNama' => $terbuka]);

        if (! empty($proses)) {
            eult_message_kirim('Password ' . $this->judul . ' Berhasil Diubah!! Username: <strong>' . $terbuka . '</strong>, Password: <strong>' . $sandi . '</strong>', 'success');
        }

        eult_message_kirim('Password ' . $this->judul . ' Gagal Diubah!!', 'error');
    }
}
