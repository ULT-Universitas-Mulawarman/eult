<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Meninjau tiket yang tidak punya baris d_archive.
 *
 * LATAR BELAKANG
 * Sebelum perbaikan 2026-10-30, tiket di-INSERT lebih dulu lalu berkas
 * divalidasi. Bila unggahan gagal, tiket tetap tersimpan tanpa arsip.
 * Di produksi ditemukan 4.156 tiket seperti ini (10,7%).
 *
 * Perbaikan kode menghentikan sumbernya, tetapi baris lama tetap ada.
 * Perintah ini membantu meninjaunya — TIDAK menghapus apa pun tanpa
 * --hapus dan tanpa konfirmasi eksplisit.
 *
 * PEMAKAIAN
 *   php spark eult:arsip --dry-run          # ringkasan + contoh (default)
 *   php spark eult:arsip --semua            # tampilkan semua kandidat
 *   php spark eult:arsip --csv=out.csv      # ekspor untuk ditinjau petugas
 *   php spark eult:arsip --hapus --ya       # hapus kandidat (BACKUP DULU!)
 *
 * Kandidat hapus = tiket tanpa arsip DAN tanpa balasan DAN tanpa disposisi
 * DAN tanpa riwayat. Tiket yang pernah ditindaklanjuti TIDAK pernah
 * diusulkan untuk dihapus, walau arsipnya hilang.
 */
class TinjauArsipHilang extends BaseCommand
{
    protected $group       = 'EULT';
    protected $name        = 'eult:arsip';
    protected $description = 'Meninjau tiket tanpa arsip (dampak bug unggahan sebelum perbaikan).';
    protected $usage       = 'eult:arsip [--dry-run] [--semua] [--csv=file] [--hapus --ya]';
    protected $arguments   = [];
    protected $options     = [
        '--dry-run' => 'Hanya ringkasan, tidak menulis apa pun (default).',
        '--semua'   => 'Tampilkan seluruh kandidat, bukan hanya 20 teratas.',
        '--csv'     => 'Ekspor kandidat ke berkas CSV untuk ditinjau petugas.',
        '--hapus'   => 'Hapus kandidat. WAJIB disertai --ya. Backup database lebih dulu!',
        '--ya'      => 'Konfirmasi untuk --hapus.',
        '--sejak'   => 'Hanya hitung tiket yang dibuat sejak tanggal ini (YYYY-MM-DD). '
            . 'Dipakai pemantauan regresi agar tumpukan data lama tidak dianggap regresi baru.',
    ];

    public function run(array $params): void
    {
        $semua = $this->punyaOpsi($params, 'semua');
        $hapus = $this->punyaOpsi($params, 'hapus');
        $ya    = $this->punyaOpsi($params, 'ya');
        $csv   = $this->nilaiOpsi($params, 'csv');
        $sejak = $this->nilaiOpsi($params, 'sejak');

        if ($sejak !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $sejak) !== 1) {
            CLI::error('Format --sejak harus YYYY-MM-DD, mis. --sejak 2026-10-31');

            return;
        }

        $db = db_connect();

        CLI::write('EULT — tinjau tiket tanpa arsip', 'yellow');

        if ($sejak !== null) {
            CLI::write('Dibatasi sejak       : ' . $sejak . ' 00:00:00', 'yellow');
        }

        CLI::newLine();

        $total = $this->totalTiket($db, $sejak);
        $tanpaArsip = $this->hitungTanpaArsip($db, $sejak);

        CLI::write('Total tiket            : ' . number_format($total, 0, ',', '.'));
        CLI::write('Tanpa baris d_archive  : ' . number_format($tanpaArsip, 0, ',', '.')
            . ($total > 0 ? sprintf(' (%.1f%%)', $tanpaArsip / $total * 100) : ''));
        CLI::newLine();

        $kandidat = $this->kandidatHapus($db, $semua ? 0 : 20, $sejak);

        CLI::write('Kandidat hapus (tanpa arsip, balasan, disposisi, riwayat): ' . count($kandidat), 'yellow');
        CLI::newLine();

        if ($kandidat === []) {
            CLI::write('Tidak ada kandidat. Tiket tanpa arsip yang tersisa sudah pernah ditindaklanjuti.', 'green');

            return;
        }

        $this->cetakTabel($kandidat);

        if ($csv !== null) {
            $this->eksporCsv($db, $csv, $sejak);
        }

        if (! $hapus) {
            CLI::newLine();
            CLI::write('Mode tinjau — tidak ada yang dihapus.', 'green');
            CLI::write('Untuk mengekspor: php spark eult:arsip --csv=kandidat.csv --semua', 'green');
            CLI::write('Untuk menghapus : php spark eult:arsip --hapus --ya   (BACKUP DATABASE DULU)', 'yellow');

            return;
        }

        if (! $ya) {
            CLI::newLine();
            CLI::error('--hapus memerlukan --ya sebagai konfirmasi. Tidak ada yang dihapus.');

            return;
        }

        $this->hapusKandidat($db, $kandidat);
    }

    private function totalTiket($db, ?string $sejak): int
    {
        $builder = $db->table('d_ticketing');

        if ($sejak !== null) {
            $builder->where('ticketCreated >=', $sejak . ' 00:00:00');
        }

        return (int) $builder->countAllResults();
    }

    private function hitungTanpaArsip($db, ?string $sejak = null): int
    {
        $sql = 'SELECT COUNT(*) AS n
                  FROM d_ticketing t
                 WHERE t.ticketArchiveId IS NOT NULL
                   AND t.ticketArchiveId <> \'\'
                   AND NOT EXISTS (SELECT 1 FROM d_archive a WHERE a.archiveId = t.ticketArchiveId)';

        if ($sejak !== null) {
            $sql .= ' AND t.ticketCreated >= ' . $db->escape($sejak . ' 00:00:00');
        }

        return (int) $db->query($sql)->getRow()->n;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function kandidatHapus($db, int $batas, ?string $sejak = null): array
    {
        $sql = 'SELECT t.ticketTrackingId, t.ticketCreated, t.ticketName,
                       t.ticketIdentitas, LEFT(t.ticketSubject, 40) AS subjek
                  FROM d_ticketing t
                 WHERE t.ticketArchiveId IS NOT NULL
                   AND t.ticketArchiveId <> \'\'
                   AND NOT EXISTS (SELECT 1 FROM d_archive a   WHERE a.archiveId = t.ticketArchiveId)
                   AND NOT EXISTS (SELECT 1 FROM d_replies r   WHERE r.repliesTicketId = t.ticketTrackingId)
                   AND NOT EXISTS (SELECT 1 FROM d_disposisi d WHERE d.disposisiTicketId = t.ticketTrackingId)
                   AND NOT EXISTS (SELECT 1 FROM d_history h  WHERE h.ticketTrackingIdHistory = t.ticketTrackingId)';

        if ($sejak !== null) {
            $sql .= ' AND t.ticketCreated >= ' . $db->escape($sejak . ' 00:00:00');
        }

        $sql .= ' ORDER BY t.ticketCreated DESC';

        if ($batas > 0) {
            $sql .= ' LIMIT ' . $batas;
        }

        return $db->query($sql)->getResultArray();
    }

    /**
     * @param list<array<string, mixed>> $baris
     */
    private function cetakTabel(array $baris): void
    {
        $lebar = [19, 19, 26, 22, 40];
        $kepala = ['Nomor Tiket', 'Dibuat', 'Nama', 'Identitas', 'Subjek'];

        $garis = '+' . implode('+', array_map(static fn ($w) => str_repeat('-', $w + 2), $lebar)) . '+';

        CLI::write($garis);
        CLI::write('| ' . implode(' | ', array_map(static fn ($t, $i) => str_pad(mb_substr($t, 0, $lebar[$i]), $lebar[$i]), $kepala, array_keys($kepala))) . ' |');
        CLI::write($garis);

        foreach ($baris as $r) {
            $sel = [
                (string) $r['ticketTrackingId'],
                (string) $r['ticketCreated'],
                (string) $r['ticketName'],
                (string) $r['ticketIdentitas'],
                (string) $r['subjek'],
            ];

            CLI::write('| ' . implode(' | ', array_map(
                static fn ($t, $i) => str_pad(mb_substr($t, 0, $lebar[$i]), $lebar[$i]),
                $sel,
                array_keys($sel),
            )) . ' |');
        }

        CLI::write($garis);
    }

    private function eksporCsv($db, string $jalur, ?string $sejak = null): void
    {
        // Ekspor selalu memuat SELURUH kandidat, bukan hanya 20 teratas.
        $baris = $this->kandidatHapus($db, 0, $sejak);

        $fp = @fopen($jalur, 'wb');

        if ($fp === false) {
            CLI::error('Tidak bisa menulis CSV: ' . $jalur);

            return;
        }

        fputcsv($fp, ['ticketTrackingId', 'ticketCreated', 'ticketName', 'ticketIdentitas', 'subjek']);

        foreach ($baris as $r) {
            fputcsv($fp, array_values($r));
        }

        fclose($fp);

        CLI::write('CSV ditulis: ' . $jalur . ' (' . count($baris) . ' baris)', 'green');
    }

    /**
     * @param list<array<string, mixed>> $kandidat
     */
    private function hapusKandidat($db, array $kandidat): void
    {
        $id = array_column($kandidat, 'ticketTrackingId');

        if ($id === []) {
            return;
        }

        CLI::newLine();
        CLI::write('Menghapus ' . count($id) . ' tiket...', 'yellow');

        $db->transBegin();

        $db->table('d_ticketing')->whereIn('ticketTrackingId', $id)->delete();

        if ($db->affectedRows() !== count($id)) {
            $db->transRollback();
            CLI::error('Jumlah baris terhapus tidak sesuai — dibatalkan.');

            return;
        }

        $db->transCommit();

        CLI::write('Selesai. ' . count($id) . ' tiket dihapus.', 'green');
        CLI::write('Jalankan ulang --dry-run untuk memastikan tidak ada kandidat tersisa.', 'green');
    }

    private function punyaOpsi(array $params, string $nama): bool
    {
        if (array_key_exists($nama, $params)) {
            return true;
        }

        foreach (array_keys($params) as $kunci) {
            if (is_string($kunci) && str_starts_with($kunci, $nama . '=')) {
                return true;
            }
        }

        return false;
    }

    private function nilaiOpsi(array $params, string $nama): ?string
    {
        if (isset($params[$nama]) && is_string($params[$nama]) && $params[$nama] !== '') {
            return $params[$nama];
        }

        foreach ($params as $kunci => $nilai) {
            if (is_string($kunci) && str_starts_with($kunci, $nama . '=')) {
                $isi = substr($kunci, strlen($nama) + 1);

                return $isi !== '' ? $isi : (is_string($nilai) && $nilai !== '' ? $nilai : null);
            }
        }

        return null;
    }
}
