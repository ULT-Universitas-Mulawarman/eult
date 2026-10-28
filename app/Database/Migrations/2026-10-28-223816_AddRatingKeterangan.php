<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddRatingKeterangan extends Migration
{
    public function up(): void
    {
        if (! $this->forge->getConnection()->tableExists('d_rating')) {
            return;
        }

        $kolom = $this->forge->getConnection()->getFieldNames('d_rating');
        if (in_array('ratingKeterangan', $kolom, true)) {
            return;
        }

        $this->forge->addColumn('d_rating', [
            'ratingKeterangan' => [
                'type'       => 'TEXT',
                'null'       => true,
                'after'      => 'ratingNilai',
            ],
        ]);
    }

    public function down(): void
    {
        if (! $this->forge->getConnection()->tableExists('d_rating')) {
            return;
        }

        $kolom = $this->forge->getConnection()->getFieldNames('d_rating');
        if (! in_array('ratingKeterangan', $kolom, true)) {
            return;
        }

        $this->forge->dropColumn('d_rating', 'ratingKeterangan');
    }
}
