<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Mengoreksi baris waktu yang terlanjur tersimpan dalam UTC.
 *
 * LATAR BELAKANG
 * Sebelum perbaikan, Config\App::$appTimezone bernilai 'UTC'. PHP menulis
 * waktu UTC ke kolom DATETIME, sementara data era CI3 sudah tersimpan dalam
 * WITA. Database jadi bercampur: satu kolom berisi dua zona waktu berbeda.
 *
 * Setelah appTimezone diubah ke Asia/Makassar, baris BARU tersimpan benar,
 * tetapi baris lama hasil CI4 tetap mundur 8 jam. Perintah ini menambahkan
 * 8 jam hanya pada baris yang dibuat sejak cutover.
 *
 * TITIK CUTOVER
 * Ditentukan dari distribusi jam pembuatan tiket di produksi:
 *   <= 2026-10-22 : WITA (jam kerja 08-23 WITA dominan)
 *   2026-10-23/24 : tidak ada data (masa deploy CI4)
 *   >= 2026-10-26 : UTC (mayoritas jatuh di 00-07)
 *   2026-10-25    : ambigu, TIDAK disentuh secara default
 *
 * Karena itu default cutover = 2026-10-26 00:00:00.
 *
 * PEMAKAIAN
 *   php spark eult:waktu --dry-run     # lihat dampak, tidak menulis
 *   php spark eult:waktu               # terapkan
 *   php spark eult:waktu --since=2026-10-25
 *   php spark eult:waktu --batalkan    # rollback (kurangi 8 jam)
 *
 * Idempoten: baris yang sudah dikoreksi dicatat di tabel penanda sehingga
 * menjalankan ulang tidak menambah 8 jam dua kali.
 */
class PerbaikiWaktu extends BaseCommand
{
    protected $group       = 'EULT';
    protected $name        = 'eult:waktu';
    protected $description = 'Mengoreksi baris waktu yang terlanjur tersimpan UTC menjadi WITA (+8 jam).';
    protected $usage       = 'eult:waktu [--dry-run] [--since=YYYY-MM-DD] [--batalkan]';
    protected $arguments   = [];
    protected $options     = [
        '--dry-run'  => 'Tampilkan rencana perubahan tanpa menulis ke database.',
        '--since'    => 'Batas awal baris yang dikoreksi (default 2026-10-26).',
        '--batalkan' => 'Kurangi 8 jam (membatalkan koreksi sebelumnya).',
    ];

    /** Selisih WITA terhadap UTC, dalam jam. */
    private const GESER = 8;

    /** Tabel penanda agar koreksi tidak diterapkan dua kali. */
    private const PENANDA = 'eult_waktu_koreksi';

    /**
     * Kolom DATETIME/TIMESTAMP yang diisi langsung oleh PHP dengan date().
     *
     * @var array<string, list<string>>
     */
    private array $kolom = [
        'd_ticketing' => [
            'ticketCreated', 'ticketAssigned', 'ticketUpdated', 'ticketClosed',
            'ticketValidated', 'ticketAccepted', 'ticketSuratCreated',
            'ticketVerified', 'ticketRejected',
        ],
        'd_disposisi' => ['disposisiTanggal', 'disposisiTanggalAkhir'],
        'd_replies'   => ['repliesDate'],
        'd_history'   => ['tglHistory'],
        's_user'      => ['susrLastLogin'],
    ];

    public function run(array $params): void
    {
        $dryRun   = $this->punyaOpsi($params, 'dry-run');
        $batalkan = $this->punyaOpsi($params, 'batalkan');
        $since    = $this->nilaiOpsi($params, 'since') ?? '2026-10-26';
        $arah     = $batalkan ? -self::GESER : self::GESER;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) !== 1) {
            CLI::error('Format --since harus YYYY-MM-DD, mis. --since 2026-10-25');

            return;
        }
        $since .= ' 00:00:00';

        $db = db_connect();

        CLI::write('EULT — koreksi zona waktu', 'yellow');
        CLI::write('Zona aplikasi : ' . (config('App')->appTimezone));
        CLI::write('Batas sejak   : ' . $since);
        CLI::write('Arah          : ' . ($arah > 0 ? '+8 jam (UTC -> WITA)' : '-8 jam (batalkan)'));
        CLI::write('Mode          : ' . ($dryRun ? 'DRY RUN (tidak menulis)' : 'TERAPKAN'));
        CLI::newLine();

        $sudah = $this->sudahDikoreksi($db);

        if ($sudah !== [] && ! $batalkan) {
            CLI::write('Baris sudah pernah dikoreksi (dilewati): ' . count($sudah), 'yellow');
        }

        $total = 0;

        foreach ($this->kolom as $tabel => $daftarKolom) {
            if (! $db->tableExists($tabel)) {
                CLI::write(sprintf('  %-14s dilewati (tabel tidak ada)', $tabel), 'dark_gray');

                continue;
            }

            $ada = $db->getFieldNames($tabel);

            foreach ($daftarKolom as $kolom) {
                if (! in_array($kolom, $ada, true)) {
                    continue;
                }

                $kunci = $tabel . '.' . $kolom;

                if (! $batalkan && isset($sudah[$kunci])) {
                    continue;
                }

                $jumlah = $this->hitung($db, $tabel, $kolom, $since);

                if ($jumlah === 0) {
                    continue;
                }

                $total += $jumlah;

                if ($dryRun) {
                    CLI::write(sprintf('  %-32s %6d baris', $kunci, $jumlah));

                    continue;
                }

                $db->table($tabel)
                    ->where($kolom . ' >=', $since)
                    ->where($kolom . ' IS NOT NULL', null, false)
                    ->set($kolom, $kolom . ($arah > 0 ? ' + ' : ' - ') . 'INTERVAL ' . abs($arah) . ' HOUR', false)
                    ->update();

                $this->catatPenanda($db, $kunci, $since, $batalkan);

                CLI::write(sprintf('  %-32s %6d baris dikoreksi', $kunci, $jumlah), 'green');
            }
        }

        CLI::newLine();

        if ($total === 0) {
            CLI::write('Tidak ada baris yang perlu dikoreksi.', 'green');

            return;
        }

        if ($dryRun) {
            CLI::write('Total baris terdampak: ' . $total, 'yellow');
            CLI::write('Jalankan tanpa --dry-run untuk menerapkan.', 'yellow');

            return;
        }

        CLI::write('Selesai. Total baris dikoreksi: ' . $total, 'green');
        CLI::write('Jalankan `php spark eult:waktu --dry-run` untuk memastikan sudah bersih.', 'green');
    }

    /**
     * Cek keberadaan flag, baik ditulis `--flag` maupun `--flag=nilai`.
     *
     * CI4::parseCommandLine() memakai ltrim($arg, '-') tanpa memisah '=',
     * sehingga `--since=2026-10-25` menjadi key `since=2026-10-25`, BUKAN
     * `since`. Tanpa penanganan ini, opsi berbentuk `=` diabaikan diam-diam
     * dan perintah berjalan dengan nilai default — berbahaya untuk perintah
     * yang mengubah data.
     */
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

    /**
     * Ambil nilai opsi, mendukung `--opsi nilai` dan `--opsi=nilai`.
     */
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

    private function hitung($db, string $tabel, string $kolom, string $since): int    {
        return $db->table($tabel)
            ->where($kolom . ' >=', $since)
            ->where($kolom . ' IS NOT NULL', null, false)
            ->countAllResults();
    }

    /**
     * @return array<string, true>
     */
    private function sudahDikoreksi($db): array
    {
        if (! $db->tableExists(self::PENANDA)) {
            return [];
        }

        $hasil = [];

        foreach ($db->table(self::PENANDA)->get()->getResultArray() as $baris) {
            $hasil[$baris['kolom']] = true;
        }

        return $hasil;
    }

    private function catatPenanda($db, string $kolom, string $since, bool $batalkan): void
    {
        if (! $db->tableExists(self::PENANDA)) {
            $db->query('CREATE TABLE IF NOT EXISTS `' . self::PENANDA . '` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `kolom` VARCHAR(80) NOT NULL,
                `sejak` DATETIME NOT NULL,
                `arah` TINYINT NOT NULL,
                `dibuat` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `kolom` (`kolom`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        }

        $db->table(self::PENANDA)->replace([
            'kolom'  => $kolom,
            'sejak'  => $since,
            'arah'   => $batalkan ? -self::GESER : self::GESER,
            'dibuat' => date('Y-m-d H:i:s'),
        ]);
    }
}
