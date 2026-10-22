<?php

/**
 * Helper kode tiket EULT.
 * Porting dari CI3 application/helpers/getkode_helper.php.
 */

if (! function_exists('eult_generate_kode')) {
    /**
     * Membuat kode acak 8 huruf format XXXX-XXXX (dipakai nomor tiket).
     */
    function eult_generate_kode(): string
    {
        $karakter = 'QWERTYUIOPASDFGHJKLZXCVBNM';
        $kode     = '';
        for ($i = 0; $i < 8; $i++) {
            $pos = rand(0, strlen($karakter) - 1);
            $kode .= $karakter[$pos];
        }

        return implode('-', str_split($kode, 4));
    }
}

if (! function_exists('eult_auto_increment')) {
    /**
     * Membuat ID arsip berikutnya (porting auto_increment CI3).
     *
     * @param array<string, mixed>|string $kondisi Kondisi WHERE. Caller yang menerima input
     *                                              dari request publik (mis. Login::savetiket())
     *                                              WAJIB memakai bentuk array (array binding aman).
     *                                              Bentuk string HANYA untuk caller internal yang
     *                                              tidak menerima input publik secara langsung
     *                                              (lihat ModelMaster::getByLastId()).
     */
    function eult_auto_increment(string $tabel, string $kolom, string $nip, array|string $kondisi): string
    {
        $model = new \App\Models\ModelMaster();

        /** @var array<string, mixed>|false $terakhir */
        $terakhir = $model->getByLastId($tabel, $kolom, $kondisi);

        if (! empty($terakhir) && isset($terakhir[$kolom])) {
            $id = $nip . sprintf('%04d', (int) substr((string) $terakhir[$kolom], 12, 4) + 1);
        } else {
            $id = $nip . '0001';
        }

        return $id;
    }
}
if (! function_exists('eult_is_safe_raw_condition')) {
    /**
     * Memvalidasi kondisi WHERE string mentah agar hanya mengandung pola
     * "identifier OPERATOR 'literal'" yang aman (digabung AND/OR dan tanda
     * kurung), MENOLAK segala bentuk injeksi SQL (tautologi quote-break,
     * UNION, comment terminator, stacked query, backslash escape, dsb).
     *
     * Dipakai sebagai pertahanan berlapis pada method model yang menerima
     * kondisi string mentah (ModelTicketing::byId(), ModelMaster::ambilSatu(),
     * ModelMaster::getByLastId()) — kondisi string HANYA boleh berasal dari
     * caller server-side yang terkontrol; caller yang menerima input publik
     * WAJIB memakai bentuk array binding.
     *
     * Aturan:
     *  - Identifier: [A-Za-z_][A-Za-z0-9_.]*
     *  - Operator: = != <> <= >= < > LIKE IN IS
     *  - Literal: string single-quoted (escape '' diperbolehkan; backslash
     *    escape TIDAK diperbolehkan), angka, atau NULL
     *  - Penggabung: AND / OR, tanda kurung seimbang untuk grouping
     *  - Diblokir: ;, \\, --, , tanda kutip tak berpasangan, token tak dikenal
     */
    function eult_is_safe_raw_condition(string $kondisi): bool
    {
        if (str_contains($kondisi, ';') || str_contains($kondisi, '\\')) {
            return false;
        }
        if (str_contains($kondisi, '--') || str_contains($kondisi, '/*') || str_contains($kondisi, '*/')) {
            return false;
        }

        $i = 0;
        $n = strlen($kondisi);
        $expectOperand = true; // true => expect identifier atau '('
        $parenDepth    = 0;

        while ($i < $n) {
            while ($i < $n && ctype_space($kondisi[$i])) {
                $i++;
            }
            if ($i >= $n) {
                break;
            }

            $c = $kondisi[$i];

            if ($expectOperand) {
                if ($c === '(') {
                    $parenDepth++;
                    $i++;
                    continue;
                }

                if (preg_match('/[A-Za-z_][A-Za-z0-9_.]*/A', $kondisi, $m, 0, $i)) {
                    $i += strlen($m[0]);
                    while ($i < $n && ctype_space($kondisi[$i])) {
                        $i++;
                    }

                    if (preg_match('/(=|!=|<>|<=|>=|<|>|LIKE|IN|IS)/Ai', $kondisi, $m, 0, $i)) {
                        $i += strlen($m[0]);
                        while ($i < $n && ctype_space($kondisi[$i])) {
                            $i++;
                        }

                        if ($i < $n && $kondisi[$i] === "'") {
                            $i++;
                            $closed = false;
                            while ($i < $n) {
                                if ($kondisi[$i] === "'") {
                                    if ($i + 1 < $n && $kondisi[$i + 1] === "'") {
                                        $i += 2;
                                        continue;
                                    }
                                    $i++;
                                    $closed = true;
                                    break;
                                }
                                if ($kondisi[$i] === '\\') {
                                    return false;
                                }
                                $i++;
                            }
                            if (! $closed) {
                                return false;
                            }
                            $expectOperand = false;
                            continue;
                        }

                        if (preg_match('/\d+(\.\d+)?/A', $kondisi, $m, 0, $i)) {
                            $i += strlen($m[0]);
                            $expectOperand = false;
                            continue;
                        }

                        if (preg_match('/NULL/Ai', $kondisi, $m, 0, $i)) {
                            $i += strlen($m[0]);
                            $expectOperand = false;
                            continue;
                        }

                        return false;
                    }

                    return false;
                }

                return false;
            }

            if ($c === ')') {
                $parenDepth--;
                if ($parenDepth < 0) {
                    return false;
                }
                $i++;
                continue;
            }

            if (preg_match('/(AND|OR)/Ai', $kondisi, $m, 0, $i)) {
                $i += strlen($m[0]);
                $expectOperand = true;
                continue;
            }

            return false;
        }

        return ! $expectOperand && $parenDepth === 0;
    }
}
