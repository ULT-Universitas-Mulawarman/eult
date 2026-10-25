<?php

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * Model dasar EULT (pengganti CI3 application/core/Model_Master.php).
 *
 * Semua model EULT extends class ini agar mendapat helper CRUD generik
 * (ambilSatu/ambilTerakhir/tabelRef/tambah/ganti/ubah/hapus) plus
 * query menu, otorisasi modul, layanan, dan notifikasi — setara
 * Model_Master CI3. Hasil dikembalikan sebagai array (pengganti
 * row()/result() CI3) kecuali dinyatakan lain.
 */
class ModelMaster extends \CodeIgniter\Model
{
    protected $DBGroup = 'default';

    // Tabel generik: properti $table/$primaryKey diisi tiap model turunan.
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected bool $allowEmptyInserts = true;

    /**
     * Galat tulis terakhir. Static agar dibagi antar instance ModelMaster
     * (helper eult_* membuat instance baru pada koneksi yang sama).
     *
     * @var array<string, mixed>
     */
    protected static array $galatTerakhir = [];

    /**
     * Catat galat DB sesaat setelah penulisan, SEBELUM transComplete()/rollback
     * dan query berikutnya (mis. RELEASE_LOCK) yang mereset error() koneksi ke 0.
     */
    protected function catatGalat(string $langkah, string $tabel): void
    {
        $galat = $this->db->error();
        $kode  = $galat['code'] ?? 0;
        $pesan = (string) ($galat['message'] ?? '');

        if ((int) $kode === 0 && $pesan === '') {
            return;
        }

        static::$galatTerakhir = [
            'langkah' => $langkah,
            'tabel'   => $tabel,
            'code'    => $kode,
            'message' => $pesan,
        ];

        log_message('error', 'EULT {langkah} gagal pada {tabel}: [{code}] {pesan}', [
            'langkah' => $langkah,
            'tabel'   => $tabel,
            'code'    => $kode,
            'pesan'   => $pesan,
        ]);
    }

    /**
     * Kosongkan galat tersimpan di awal penulisan tingkat atas (bukan transaksi bersarang).
     */
    protected function resetGalat(): void
    {
        if ($this->db->transDepth === 0) {
            static::$galatTerakhir = [];
        }
    }

    /**
     * Pesan galat penulisan terakhir berbentuk "kode: pesan". Bila DB tidak
     * melaporkan apa pun, kembalikan penyebab beserta nama langkah (tidak pernah "0: ").
     */
    public function pesanGalat(string $langkah = ''): string
    {
        if (static::$galatTerakhir !== []) {
            return static::$galatTerakhir['code'] . ': ' . static::$galatTerakhir['message'];
        }

        $galat = $this->db->error();
        $kode  = $galat['code'] ?? 0;
        $pesan = (string) ($galat['message'] ?? '');

        if ((int) $kode !== 0 || $pesan !== '') {
            return $kode . ': ' . $pesan;
        }

        $pesan = 'penyebab tidak diketahui' . ($langkah !== '' ? ' (langkah: ' . $langkah . ')' : '');
        log_message('error', 'EULT galat penulisan kosong: {pesan}', ['pesan' => $pesan]);

        return $pesan;
    }

    /**
     * Koneksi kedua ke db_ult (ref_layanan/jenis/unit).
     */
    protected function dbUlt(): BaseConnection
    {
        return \Config\Database::connect('dbult');
    }

    /**
     * Ambil satu baris (setara get_by_id CI3).
     */
    public function ambilSatu(string $tabel, array|string $kondisi): array|false
    {
        $builder = $this->db->table($tabel);

        if (is_array($kondisi)) {
            $builder->where($kondisi);
        } else {
            // Pertahanan berlapis: kondisi string mentah HANYA boleh berbentuk
            // "identifier OPERATOR 'literal'" yang aman (digabung AND/OR + tanda
            // kurung). Segala bentuk injeksi SQL ditolak dan mengembalikan false.
            // Caller dari input publik WAJIB memakai bentuk array binding.
            if (! eult_is_safe_raw_condition($kondisi)) {
                return false;
            }
            $builder->where($kondisi, null, false);
        }

        $baris = $builder->get()->getRowArray();

        return $baris ?? false;
    }

    /**
     * Ambil nilai MAX kolom (setara get_by_last_id CI3).
     *
     * @param array<string, mixed>|string $kondisi Kondisi WHERE. Bentuk array (array binding)
     *                                              WAJIB dipakai untuk kondisi yang berasal dari
     *                                              input publik/request pengguna. Bentuk string
     *                                              mentah HANYA diperbolehkan untuk caller
     *                                              internal yang tidak menerima/meneruskan input
     *                                              publik langsung ke fragmen kondisi.
     *
     * @deprecated-for-public-input Bentuk string TIDAK aman terhadap SQL injection bila
     *                               $kondisi dibangun dari data pengguna (concatenation).
     *                               Gunakan bentuk array untuk seluruh caller baru yang
     *                               menerima input dari request publik (lihat K1 fix pada
     *                               Cektiket::rating()/Login::savetiket()).
     */
    public function getByLastId(string $tabel, string $kolom, array|string $kondisi = []): array|false
    {
        $builder = $this->db->table($tabel)->selectMax($kolom, $kolom);
        if (! empty($kondisi)) {
            if (is_array($kondisi)) {
                $builder->where($kondisi);
            } else {
                // Pertahanan berlapis: kondisi string mentah HANYA boleh berbentuk
                // "identifier OPERATOR 'literal'" yang aman (digabung AND/OR + tanda
                // kurung). Segala bentuk injeksi SQL ditolak dan mengembalikan false.
                // Caller dari input publik WAJIB memakai bentuk array binding.
                if (! eult_is_safe_raw_condition($kondisi)) {
                    return false;
                }
                $builder->where($kondisi, null, false);
            }
        }

        $baris = $builder->get()->getRowArray();

        return $baris ?? false;
    }

    /**
     * Ambil tabel referensi (setara get_ref_table CI3).
     */
    public function tabelRef(string $tabel, array|string $kondisi = [], string $urut = ''): array|false
    {
        $builder = $this->db->table($tabel);
        if (! empty($kondisi)) {
            $builder->where($kondisi);
        }
        if ($urut !== '') {
            $builder->orderBy($urut);
        }

        $hasil = $builder->get()->getResultArray();

        return $hasil === [] ? false : $hasil;
    }

    /**
     * Insert satu baris dalam transaksi (setara insert CI3).
     */
    public function tambah(string $tabel, array $param): bool
    {
        $this->resetGalat();
        $this->db->transStart();
        $this->db->table($tabel)->insert($param);
        $this->catatGalat('insert', $tabel);
        log_message('debug', 'EULT insert {tabel}: {query}', ['tabel' => $tabel, 'query' => $this->db->getLastQuery()]);
        $this->db->transComplete();

        return $this->db->transStatus();
    }

    /**
     * Insert batch dalam transaksi (setara insert_batch CI3).
     */
    public function tambahBatch(string $tabel, array $param): bool
    {
        $this->resetGalat();
        $this->db->transStart();
        $this->db->table($tabel)->insertBatch($param);
        $this->catatGalat('insert batch', $tabel);
        $this->db->transComplete();

        return $this->db->transStatus();
    }

    /**
     * Replace satu baris dalam transaksi (setara replace CI3).
     */
    public function ganti(string $tabel, array $param): bool
    {
        $this->resetGalat();
        $this->db->transStart();
        $this->db->table($tabel)->replace($param);
        $this->catatGalat('replace', $tabel);
        $this->db->transComplete();

        return $this->db->transStatus();
    }

    /**
     * Update dalam transaksi (setara update CI3).
     */
    public function ubah(string $tabel, array $param, array|string $kondisi): bool
    {
        $this->resetGalat();
        $this->db->transStart();
        $this->db->table($tabel)->where($kondisi)->update($param);
        $this->catatGalat('update', $tabel);
        log_message('debug', 'EULT update {tabel}: {query}', ['tabel' => $tabel, 'query' => $this->db->getLastQuery()]);
        $this->db->transComplete();

        return $this->db->transStatus();
    }

    /**
     * Update batch dalam transaksi (setara update_batch CI3).
     */
    public function ubahBatch(string $tabel, array $param, string $kolomKunci): bool
    {
        $this->resetGalat();
        $this->db->transStart();
        $this->db->table($tabel)->updateBatch($param, $kolomKunci);
        $this->catatGalat('update batch', $tabel);
        $this->db->transComplete();

        return $this->db->transStatus();
    }

    /**
     * Hapus baris dalam transaksi (setara delete CI3).
     */
    public function hapus(string $tabel, array|string $kondisi): bool
    {
        try {
            $this->resetGalat();
            $this->db->transStart();
            $this->db->table($tabel)->where($kondisi)->delete();
            $this->catatGalat('hapus', $tabel);
            $this->db->transComplete();

            return $this->db->transStatus();
        } catch (\Throwable $e) {
            log_message('error', 'EULT hapus gagal {tabel}: {pesan}', ['tabel' => $tabel, 'pesan' => $e->getMessage()]);
            static::$galatTerakhir = ['langkah' => 'hapus', 'tabel' => $tabel, 'code' => 0, 'message' => $e->getMessage()];

            return false;
        }
    }

    /**
     * Kosongkan tabel dalam transaksi (setara delete_truncate CI3).
     */
    public function hapusTruncate(string $tabel): bool
    {
        try {
            $this->db->transStart();
            $this->db->table($tabel)->truncate();
            $this->db->transComplete();

            return $this->db->transStatus();
        } catch (\Throwable $e) {
            log_message('error', 'EULT truncate gagal {tabel}: {pesan}', ['tabel' => $tabel, 'pesan' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Menu sidebar per grup pengguna.
     */
    public function getMenuByGroup(string $namaGrup): array|false
    {
        $hasil = $this->db->table('s_user_group_modul')
            ->select('susrmodulNama,susrmodulNamaDisplay,susrmdgroupDisplay,susrmdgroupIcon')
            ->join('s_user_modul_ref', 'sgroupmodulSusrmodulNama=susrmodulNama', 'left')
            ->join('s_user_modul_group_ref', 'susrmodulSusrmdgroupNama=susrmdgroupNama', 'left')
            ->where('sgroupmodulSgroupNama', $namaGrup)
            ->where('sgroupmodulSusrmodulRead', '1')
            ->where('susrmodulSusrmdgroupNama IS NOT NULL', null, false)
            ->orderBy('susrmdgroupDisplay')
            ->orderBy('susrmodulUrut')
            ->orderBy('susrmodulNamaDisplay')
            ->get()->getResultArray();

        return $hasil === [] ? false : $hasil;
    }

    /**
     * Menu publik tanpa login.
     */
    public function getMenuNonLogin(): array|false
    {
        $hasil = $this->db->table('s_user_modul_ref')
            ->select('susrmodulNama,susrmodulNamaDisplay')
            ->where('susrmodulIsLogin', '0')
            ->orderBy('susrmodulUrut')
            ->get()->getResultArray();

        return $hasil === [] ? false : $hasil;
    }

    /**
     * Cek hak baca modul per grup (dipakai AuthFilter + BaseController).
     */
    public function otentikasiMenu(string $namaGrup, string $namaModul): array|false
    {
        $hasil = $this->db->table('s_user_group_modul')
            ->select('susrmodulNamaDisplay,susrmdgroupDisplay')
            ->join('s_user_modul_ref', 'sgroupmodulSusrmodulNama=susrmodulNama', 'left')
            ->join('s_user_modul_group_ref', 'susrmodulSusrmdgroupNama=susrmdgroupNama', 'left')
            ->where('sgroupmodulSgroupNama', $namaGrup)
            ->where('sgroupmodulSusrmodulRead', '1')
            ->where('sgroupmodulSusrmodulNama', $namaModul)
            ->limit(1)
            ->get()->getResultArray();

        return $hasil === [] ? false : $hasil;
    }

    /**
     * Daftar layanan aktif dari db_ult (join ref_layanan/jenis/unit).
     */
    public function getLayanan(string $khusus = ''): array|false
    {
        $db = $this->dbUlt();

        $builder = $db->table('ref_layanan')
            ->select('jenislayananNama,layananId,layananNama,unitNama')
            ->join('ref_jenis_layanan', 'layananjenisId = jenislayananId', 'LEFT')
            ->join('ref_unit', 'layananunitId = unitId', 'LEFT')
            ->where('layananisdisplay', 1)
            ->orderBy('jenislayananId')
            ->orderBy('unitUrut')
            ->orderBy('layananId');

        if (! empty($khusus)) {
            // Daftar layanan khusus dapat dioverride via .env
            // (EULT_LAYANAN_KHUSUS, dipisah koma) tanpa mengubah kode.
            $daftar = array_filter(array_map('trim', explode(',', (string) (env('EULT_LAYANAN_KHUSUS') ?: '9,12,32,45,78,88,110,27,28,29,146,150,168'))));
            $builder->groupStart()->where('jenislayananId', 3)->orWhereIn('layananId', $daftar)->groupEnd();
        }

        $hasil = $builder->get()->getResultArray();

        return $hasil === [] ? false : $hasil;
    }

    /**
     * Notifikasi tiket untuk ADMIN/OPERATOR (replies belum dibaca).
     */
    public function dataNotif(array|string $kondisi = ''): array|false
    {
        $sub1 = $this->db->table('d_disposisi')
            ->select('disposisiTicketId mDisp,MAX(disposisiTanggal) mTgl')
            ->groupBy('disposisiTicketId')
            ->getCompiledSelect();

        $sub2 = $this->db->newQuery()
            ->select('d_disposisi.*')
            ->from('(' . $sub1 . ') as d')
            ->join('d_disposisi', 'mDisp = disposisiTicketId AND mTgl = disposisiTanggal')
            ->getCompiledSelect();

        $builder = $this->db->table('d_ticketing')
            ->select("d_ticketing.*,db_ult.ref_jenis_layanan.*,r_priority.*,r_status.*,sunit.*,d_disposisi.*,d_archive.*,
                    runit.unitNama runitNama,
                    ref_layanan.layananId sCatId,
                    ref_layanan.layananNama sCatNama,
                    ref_layanan.layananunitId sCatCategoryId,
                    ref_layanan.layananisbottomup sCatDisposisi,
                    repliesTicketId,
                    COUNT(repliesTicketId) as jumlah,
                    CONCAT(ref_jenis_layanan.jenislayananNama,' (',runit.unitNama ,')') categoryNama", false)
            ->join('d_replies', 'ticketTrackingId = repliesTicketId', 'LEFT')
            ->join('db_ult.ref_layanan', 'ticketCategories = layananId', 'LEFT')
            ->join('db_ult.ref_jenis_layanan', 'layananjenisId = jenislayananId', 'LEFT')
            ->join('db_ult.ref_unit runit', 'layananunitId = runit.unitId', 'LEFT')
            ->join('r_priority', 'ticketPriority = priorityId', 'LEFT')
            ->join('r_status', 'ticketStatus = statusId', 'LEFT')
            ->join('d_archive', 'archiveTrackingId = ticketTrackingId', 'LEFT')
            ->join('s_unit sunit', 'ticketAssign = sunit.unitId', 'left')
            ->join('(' . $sub2 . ') d_disposisi', 'ticketTrackingId = disposisiTicketId', 'left')
            ->groupBy('repliesTicketId')
            ->orderBy('disposisiTanggal DESC');

        if (! empty($kondisi)) {
            $builder->where($kondisi);
        }

        $hasil = $builder->get()->getResultArray();

        return $hasil === [] ? false : $hasil;
    }

    /**
     * Notifikasi disposisi per unit (untuk role unit/non-admin).
     */
    public function disposisiNotif(array|string $kondisi): array|false
    {
        $sub1 = $this->db->table('d_disposisi')
            ->select('disposisiTicketId mDisp,MAX(disposisiTanggal) mTgl')
            ->groupBy('disposisiTicketId')
            ->getCompiledSelect();

        $sub2 = $this->db->newQuery()
            ->select('d_disposisi.*')
            ->from('(' . $sub1 . ') as d')
            ->join('d_disposisi', 'mDisp = disposisiTicketId AND mTgl = disposisiTanggal')
            ->getCompiledSelect();

        $hasil = $this->db->table('d_ticketing')
            ->select("d_disposisi.*,d_ticketing.*,r_priority.*,r_status.*,s_unit.*,s_user_group_unit.*,db_ult.ref_jenis_layanan.*,
                    d_unit.unitNama dunitNama,
                    runit.unitNama runitNama,
                    ref_layanan.layananId sCatId,
                    ref_layanan.layananNama sCatNama,
                    ref_layanan.layananunitId sCatCategoryId,
                    ref_layanan.layananisbottomup sCatDisposisi,
                    repliesTicketId,
                    COUNT(repliesTicketId) as jumlah,
                    CONCAT(ref_jenis_layanan.jenislayananNama,' (',runit.unitNama ,')') categoryNama", false)
            ->join('d_replies', 'ticketTrackingId = repliesTicketId', 'LEFT')
            ->join('db_ult.ref_layanan', 'ticketCategories = layananId', 'LEFT')
            ->join('db_ult.ref_jenis_layanan', 'layananjenisId = jenislayananId', 'LEFT')
            ->join('db_ult.ref_unit runit', 'layananunitId = runit.unitId', 'LEFT')
            ->join('s_unit', 'ticketAssign = s_unit.unitId', 'left')
            ->join('(' . $sub2 . ') d_disposisi', 'ticketTrackingId = disposisiTicketId', 'left')
            ->join('r_priority', 'ticketPriority = priorityId', 'LEFT')
            ->join('r_status', 'ticketStatus = statusId', 'LEFT')
            ->join('s_user_group_unit', 'sgroupunitUnitId = disposisiUnit', 'left')
            ->join('s_unit d_unit', 'disposisiUnit = d_unit.unitId', 'left')
            ->where($kondisi)
            ->groupBy('repliesTicketId')
            ->orderBy('disposisiTanggal DESC')
            ->get()->getResultArray();

        return $hasil === [] ? false : $hasil;
    }

    /**
     * Koneksi database aktif (dipakai BaseController untuk escape manual).
     */
    public function dbAktif(): \CodeIgniter\Database\BaseConnection
    {
        return $this->db;
    }

    /**
     * Query builder untuk tabel arbitrer (dipakai controller datatables).
     */
    public function tabelBuilder(string $tabel): BaseBuilder
    {
        return $this->db->table($tabel);
    }
}
