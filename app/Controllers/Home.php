<?php

namespace App\Controllers;

use App\Models\ModelHome;

/**
 * Dashboard + manajemen sesi pengguna (porting CI3 Home.php).
 */
class Home extends BaseController
{
    protected ?string $judul = 'Dashboard';

    protected ?string $controllerName = 'home';

    protected ?string $pathPage = 'pages/home/';

    private ModelHome $beranda;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);

        $this->beranda = new ModelHome();
    }

    public function index(): string
    {
        $data            = $this->getMaster($this->pathPage . $this->pageIndex);
        $data['scripts'] = [];

        // Ambil data agregat tiket untuk KPI dashboard sesuai cakupan unit kerja pengguna
        $db   = \Config\Database::connect();
        $sesi = $this->sesiLogin() ?? [];
        $grup = (string) ($sesi['susrSgroupNama'] ?? '');

        $totalMasuk    = 0;
        $totalProses   = 0;
        $totalSelesai  = 0;
        $totalTindakan = 0;
        $urgentTickets = [];
        $trenLabel     = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum'];
        $trenData      = [0, 0, 0, 0, 0];

        try {
            // Deteksi skema tabel database (kompatibilitas nama tabel konseptual dan riil)
            $ticketTable    = $db->tableExists('ticket') ? 'ticket' : ($db->tableExists('d_ticketing') ? 'd_ticketing' : null);
            $unitTable      = $db->tableExists('unit') ? 'unit' : ($db->tableExists('s_unit') ? 's_unit' : null);
            $statusTable    = $db->tableExists('ticket_status') ? 'ticket_status' : ($db->tableExists('r_status') ? 'r_status' : null);
            $disposisiTable = $db->tableExists('d_disposisi') ? 'd_disposisi' : null;

            $butuhFilterUnit = ($grup !== 'ADMIN' && strpos($grup, 'OPERATOR') === false);
            $unitDiizinkan   = [];

            if ($butuhFilterUnit && $grup !== '' && $db->tableExists('s_user_group_unit')) {
                $barisUnit = $db->table('s_user_group_unit')
                    ->select('sgroupunitUnitId')
                    ->where('sgroupunitSgroupNama', $grup)
                    ->get()
                    ->getResultArray();
                $unitDiizinkan = array_values(array_unique(array_filter(
                    array_map(static fn (array $r): string => (string) ($r['sgroupunitUnitId'] ?? ''), $barisUnit),
                    static fn (string $v): bool => $v !== ''
                )));
            }

            if ($ticketTable !== null && (! $butuhFilterUnit || $unitDiizinkan !== [])) {
                $daftarUnitSql = implode(',', array_map(static fn (string $u): string => $db->escape($u), $unitDiizinkan));

                $terapkanFilterUnit = static function ($qb) use ($butuhFilterUnit, $unitDiizinkan, $daftarUnitSql, $ticketTable, $disposisiTable): void {
                    if (! $butuhFilterUnit) {
                        return;
                    }
                    $qb->groupStart()
                        ->whereIn("{$ticketTable}.ticketAssign", $unitDiizinkan);
                    if ($disposisiTable !== null && $daftarUnitSql !== '') {
                        $qb->orWhere(
                            "EXISTS (SELECT 1 FROM {$disposisiTable} d WHERE d.disposisiTicketId = {$ticketTable}.ticketTrackingId AND d.disposisiUnit IN ({$daftarUnitSql}))",
                            null,
                            false
                        );
                    }
                    $qb->groupEnd();
                };

                $qbMasuk = $db->table($ticketTable)->where("{$ticketTable}.ticketStatus", 1);
                $terapkanFilterUnit($qbMasuk);
                $totalMasuk = $qbMasuk->countAllResults();

                $qbProses = $db->table($ticketTable)->whereIn("{$ticketTable}.ticketStatus", [2, 3, 4, 6, 7]);
                $terapkanFilterUnit($qbProses);
                $totalProses = $qbProses->countAllResults();

                $qbSelesai = $db->table($ticketTable)->whereIn("{$ticketTable}.ticketStatus", [5, 8]);
                $terapkanFilterUnit($qbSelesai);
                $totalSelesai = $qbSelesai->countAllResults();

                $qbTindakan = $db->table($ticketTable)->whereIn("{$ticketTable}.ticketStatus", [1, 2, 7]);
                $terapkanFilterUnit($qbTindakan);
                $totalTindakan = $qbTindakan->countAllResults();

                $builder = $db->table($ticketTable)
                    ->select("{$ticketTable}.ticketTrackingId, {$ticketTable}.ticketName, {$ticketTable}.ticketCreated, {$ticketTable}.ticketStatus");

                if ($unitTable !== null) {
                    $builder->select("{$unitTable}.unitNama")
                        ->join($unitTable, "{$unitTable}.unitId = {$ticketTable}.ticketAssign", 'left');
                }

                if ($statusTable !== null) {
                    $builder->select("{$statusTable}.statusNama, {$statusTable}.statusColor")
                        ->join($statusTable, "{$statusTable}.statusId = {$ticketTable}.ticketStatus", 'left');
                }

                $terapkanFilterUnit($builder);
                $urgentTickets = $builder
                    ->whereIn("{$ticketTable}.ticketStatus", [1, 2])
                    ->orderBy("{$ticketTable}.ticketCreated", 'DESC')
                    ->limit(5)
                    ->get()
                    ->getResultArray();

                // Hitung tren tiket masuk nyata untuk 5 hari kerja minggu berjalan (Senin s/d Jumat)
                $hariKe    = (int) date('N');
                $seninTs   = strtotime('-' . ($hariKe - 1) . ' days', strtotime(date('Y-m-d')));
                $jumatTs   = strtotime('+4 days', $seninTs);
                $awalPekan = date('Y-m-d 00:00:00', $seninTs);
                $akhirPekan = date('Y-m-d 23:59:59', $jumatTs);

                $qbTren = $db->table($ticketTable)
                    ->select("DATE({$ticketTable}.ticketCreated) AS tanggal, COUNT(*) AS jumlah", false)
                    ->where("{$ticketTable}.ticketCreated >=", $awalPekan)
                    ->where("{$ticketTable}.ticketCreated <=", $akhirPekan)
                    ->groupBy("DATE({$ticketTable}.ticketCreated)");
                $terapkanFilterUnit($qbTren);

                $petaTren = [];
                foreach ($qbTren->get()->getResultArray() as $barisTren) {
                    $petaTren[(string) ($barisTren['tanggal'] ?? '')] = (int) ($barisTren['jumlah'] ?? 0);
                }

                for ($i = 0; $i < 5; $i++) {
                    $tglHari      = date('Y-m-d', strtotime("+{$i} days", $seninTs));
                    $trenData[$i] = $petaTren[$tglHari] ?? 0;
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'Gagal memuat agregat KPI dashboard: ' . $e->getMessage());
        }

        $data['total_masuk']    = $totalMasuk;
        $data['total_proses']   = $totalProses;
        $data['total_selesai']  = $totalSelesai;
        $data['total_tindakan'] = $totalTindakan;
        $data['tiket_urgent']   = $urgentTickets;
        $data['tren_mingguan']  = [
            'labels' => $trenLabel,
            'data'   => $trenData,
        ];

        return view($this->template, $data);
    }

    public function logout()
    {
        session()->remove('logged_in');
        session()->destroy();

        return redirect()->to(site_url('home'));
    }

    public function ubahpass(): string|\CodeIgniter\HTTP\RedirectResponse
    {
        $sesi = $this->sesiLogin() ?? [];

        if (($sesi['susrSgroupNama_ori'] ?? 'USER') === 'USER') {
            return redirect()->to(site_url('login'));
        }

        $data             = $this->getMaster($this->pathPage . 'ubahpassword');
        $data['save_url'] = site_url($this->controllerName . '/prosesubahpassword');
        $data['judul']    = 'Ubah Password';
        $data['scripts']  = [];

        return view($this->template, $data);
    }

    public function ubahhakakses(): string
    {
        $sesi = $this->sesiLogin() ?? [];

        $data             = $this->getMaster($this->pathPage . 'ubahhakakses');
        $data['save_url'] = site_url($this->controllerName . '/prosesubahhakakses');
        $data['judul']    = 'Ubah Hak Akses';
        $data['hakakses'] = $this->beranda->byId((string) ($sesi['susrNama'] ?? ''));
        $data['scripts']  = [];

        return view($this->template, $data);
    }

    public function prosesubahpassword()
    {
        $sesi = $this->sesiLogin() ?? [];

        if (! $this->validate([
            'susrPasswordOld'         => 'required',
            'susrPasswordNew'         => 'required',
            'susrPasswordNewConfirm'  => 'required',
        ])) {
            eult_message_kirim('Ooops!! Something Wrong!!', 'error');
        }

        $lama     = (string) $this->request->getPost('susrPasswordOld');
        $baru     = (string) $this->request->getPost('susrPasswordNew');
        $konfirm  = (string) $this->request->getPost('susrPasswordNewConfirm');
        $nama     = (string) ($sesi['susrNama'] ?? '');

        $cek = $this->beranda->ambilSatu('s_user', ['susrNama' => $nama]);

        if ($cek === false) {
            eult_message_kirim('Username/Password Lama Salah', 'error');
        }

        if (! password_verify($lama, (string) $cek['susrPassword'])) {
            eult_message_kirim('Username dan Password Lama Salah', 'error');
        }

        if ($baru !== $konfirm) {
            eult_message_kirim('Password Baru Tidak Sama Dengan Konfirmasi', 'error');
        }

        $proses = $this->beranda->ubah('s_user', ['susrPassword' => password_hash($baru, PASSWORD_DEFAULT)], ['susrNama' => $nama]);

        eult_message_kirim($proses ? 'Password Berhasil Diubah' : 'Password Gagal diubah', $proses ? 'success' : 'error');
    }

    public function prosesubahhakakses()
    {
        $sesi = $this->sesiLogin() ?? [];

        if (! $this->validate(['hakakses' => 'required'])) {
            eult_message_kirim('Ooops!! Something Wrong!!', 'error');
        }

        if (($sesi['susrSgroupNama_ori'] ?? 'USER') === 'USER') {
            return redirect()->to(site_url('login'));
        }

        $namaPengguna = (string) ($sesi['susrNama'] ?? '');
        $hakaksesBaru = (string) $this->request->getPost('hakakses');
        $daftarGrup   = $this->beranda->byId($namaPengguna);
        $grupTerpilih = null;

        // Pastikan pengguna benar-benar memiliki keanggotaan di grup tujuan
        if (is_array($daftarGrup)) {
            foreach ($daftarGrup as $barisGrup) {
                if ((string) ($barisGrup['sgroupSgroupNama'] ?? '') === $hakaksesBaru) {
                    $grupTerpilih = $barisGrup;
                    break;
                }
            }
        }

        if ($grupTerpilih === null) {
            eult_message_kirim('Anda tidak memiliki hak akses untuk grup tersebut.', 'error');
        }

        session()->set('logged_in', [
            'susrNama'           => $sesi['susrNama'],
            'susrSgroupNama'     => $hakaksesBaru,
            'susrSgroupNama_ori' => $sesi['susrSgroupNama_ori'],
            'susrProfil'         => $sesi['susrProfil'],
            'susrCategoryId'     => (string) ($grupTerpilih['sgroupCategoryId'] ?? ($sesi['susrCategoryId'] ?? '')),
        ]);

        eult_message_kirim('Hak Akses Berhasil Diubah', 'success');
    }
}
