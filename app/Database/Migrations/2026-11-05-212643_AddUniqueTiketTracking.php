<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Menjamin ticketTrackingId tidak bisa duplikat di level database.
 *
 * Konteks: nomor tiket dibuat lewat pola read-then-write
 * (baca nomor terakhir via ModelTicketing::getNumber(), lalu insert)
 * yang diserialisasi dengan GET_LOCK('eult_nomor_<kode>') — lihat
 * ModelTicketing::kunciNomorTiket(). Lock tersebut hanya mengikat koneksi
 * aplikasi yang memanggilnya; bila ada jalur lain (skrip, import,
 * query manual) yang melewatkan lock, duplikat tetap bisa lolos.
 *
 * Migration ini menambah lapis kedua: UNIQUE KEY pada ticketTrackingId.
 * Dengan begitu MySQL menolak duplikat (error 1062) apa pun sumbernya,
 * dan aplikasi bisa retry alokasi nomor. GET_LOCK TIDAK dihapus — lock
 * tetap menghindarkan balapan sebelum insert, unique hanya menegakkan
 * di akhir.
 *
 * Idempoten: bila PRIMARY KEY atau UNIQUE KEY pada kolom yang sama sudah
 * ada (seperti di prod saat ini), migration langsung skip tanpa error,
 * sehingga aman dijalankan ulang di semua environment.
 */
class AddUniqueTiketTracking extends Migration
{
    public function up(): void
    {
        if (! $this->forge->getConnection()->tableExists('d_ticketing')) {
            return;
        }

        $kolom = $this->forge->getConnection()->getFieldNames('d_ticketing');
        if (! in_array('ticketTrackingId', $kolom, true)) {
            return;
        }

        // Bila sudah ada PRIMARY KEY / UNIQUE KEY tepat pada kolom ini,
        // perlindungan duplikat sudah ditegakkan — tidak perlu index ganda.
        if ($this->sudahTerproteksi()) {
            return;
        }

        // Berhenti sejak awal bila data lama ternyata sudah duplikat,
        // daripada ALTER TABLE gagal dengan error yang kurang jelas.
        $duplikat = $this->db->table('d_ticketing')
            ->select('ticketTrackingId, COUNT(*) AS jumlah')
            ->groupBy('ticketTrackingId')
            ->having('jumlah > 1')
            ->get()
            ->getResultArray();
        if ($duplikat !== []) {
            throw new \RuntimeException(
                'Ditemukan ' . count($duplikat) . ' ticketTrackingId duplikat di d_ticketing. '
                . 'Bersihkan duplikat terlebih dahulu sebelum menjalankan migration ini.'
            );
        }

        $this->forge->addUniqueKey('ticketTrackingId', 'uk_tiket_tracking');
        $this->forge->processIndexes('d_ticketing');
    }

    public function down(): void
    {
        if (! $this->forge->getConnection()->tableExists('d_ticketing')) {
            return;
        }

        // Hanya drop index yang dibuat migration ini. Bila pengaman yang
        // ada adalah PRIMARY KEY bawaan, jangan sentuh (rollback tidak
        // boleh menghapus proteksi yang sudah ada sejak awal).
        $ada = isset($this->db->getIndexData('d_ticketing')['uk_tiket_tracking']);
        if (! $ada) {
            return;
        }

        $this->forge->dropKey('d_ticketing', 'uk_tiket_tracking');
    }

    private function sudahTerproteksi(): bool
    {
        foreach ($this->db->getIndexData('d_ticketing') as $index) {
            $fields = $index->fields ?? [];
            $type   = strtoupper((string) ($index->type ?? ''));
            if ($fields === ['ticketTrackingId'] && in_array($type, ['PRIMARY', 'UNIQUE'], true)) {
                return true;
            }
        }
        return false;
    }
}
