<?php

namespace App\Models;

/**
 * Model laporan EULT (porting CI3 Model_laporan.php).
 */
class ModelLaporan extends ModelMaster
{
    protected $table      = 'd_ticketing';
    protected $primaryKey = 'ticketTrackingId';
    protected $returnType = 'array';

    /**
     * Menormalisasi batas awal (00:00:00) dan batas akhir (23:59:59) tanggal laporan.
     *
     * @return array{0: string, 1: string}
     */
    private function normalisasiBatasTanggal(string $awal, string $akhir): array
    {
        $awal  = trim($awal);
        $akhir = trim($akhir);

        if (strlen($awal) === 10) {
            $awal .= ' 00:00:00';
        }
        if (strlen($akhir) === 10) {
            $akhir .= ' 23:59:59';
        }

        return [$awal, $akhir];
    }

    /**
     * Rekap per unit dalam rentang tanggal.
     *
     * @param array{0:string,1:string} $rentang [tglAwal, tglAkhir] format Y-m-d
     * @return array<int, array<string, mixed>>|false
     */
    public function getLaporan(array $rentang): array|false
    {
        [$tglAwal, $tglAkhir] = $this->normalisasiBatasTanggal((string) ($rentang[0] ?? ''), (string) ($rentang[1] ?? ''));
        $refDb = $this->dbUltName();

        $hasil = $this->db->table('d_ticketing')
            ->select("DATE(ticketCreated) ticketCreated, unitNama, unitId,
                SUM(CASE WHEN ticketStatus IN ('1','2','3','4','5','6','7') THEN 1 ELSE 0 END) AS TERIMA,
                SUM(CASE WHEN ticketStatus IN ('8','9','10') THEN 1 ELSE 0 END) AS TOLAK,
                SUM(CASE WHEN ticketStatus IN ('1','2','3','4','6','7') THEN 1 ELSE 0 END) AS PROSES,
                SUM(CASE WHEN ticketStatus = '5' THEN 1 ELSE 0 END) AS SELESAI,
                SUM(CASE WHEN ticketStatus THEN 1 ELSE 0 END) AS Jumlah", false)
            ->join("{$refDb}.ref_layanan", 'layananId = ticketCategories', 'left')
            ->join("{$refDb}.ref_unit", 'layananunitId = unitId', 'left')
            ->where('ticketCreated >=', $tglAwal)
            ->where('ticketCreated <=', $tglAkhir)
            ->groupBy('unitId')
            ->get()->getResultArray();

        return $hasil === [] ? false : $hasil;
    }

    /**
     * Rekap per layanan dalam rentang tanggal + filter grup.
     *
     * @return array<int, array<string, mixed>>|false
     */
    public function getLaporanLayanan(string $tanggalAwal, string $tanggalAkhir, string $grup, string $kategori = ''): array|false
    {
        [$awal, $akhir] = $this->normalisasiBatasTanggal($tanggalAwal, $tanggalAkhir);
        $refDb = $this->dbUltName();

        $sub = $this->db->table('d_ticketing')
            ->select('*')
            ->join("{$refDb}.ref_layanan", 'layananId = ticketCategories', 'left')
            ->join("{$refDb}.ref_unit", 'layananunitId = unitId', 'left')
            ->join("{$refDb}.ref_jenis_layanan", 'jenislayananId = layananjenisId', 'left')
            ->join('s_user_group', 'sgroupCategoryId = unitId', 'left')
            ->where('ticketCreated >=', $awal)
            ->where('ticketCreated <=', $akhir)
            ->groupBy('ticketTrackingId');

        if ($grup === 'ADMIN' || strpos($grup, 'OPERATOR') !== false) {
            $sub->where('layananunitId', $kategori);
        } else {
            $sub->where('sgroupNama', $grup);
        }

        $sqlTiket = $sub->getCompiledSelect();

        $hasil = $this->db->newQuery()
            ->select("DATE(ticketCreated) ticketCreated, jenislayananNama, layananNama, unitNama, unitId,
                SUM(CASE WHEN ticketStatus IN ('1','2','3','4','5','6','7') THEN 1 ELSE 0 END) AS TERIMA,
                SUM(CASE WHEN ticketStatus IN ('8','9','10') THEN 1 ELSE 0 END) AS TOLAK,
                SUM(CASE WHEN ticketStatus IN ('1','2','3','4','6','7') THEN 1 ELSE 0 END) AS PROSES,
                SUM(CASE WHEN ticketStatus = '5' THEN 1 ELSE 0 END) AS SELESAI,
                SUM(CASE WHEN ticketStatus THEN 1 ELSE 0 END) AS Jumlah", false)
            ->from('(' . $sqlTiket . ') tiket')
            ->groupBy('layananId')
            ->orderBy('jenislayananId')
            ->get()->getResultArray();

        return $hasil === [] ? false : $hasil;
    }
}
