<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Guard statis: memastikan pemanggilan query model tidak memakai kondisi
 * string mentah (rawan SQL injection). Pola yang diperiksa:
 * - byId() argumen pertama berupa string literal
 * - ambilSatu() argumen kedua berupa string literal
 * - eult_auto_increment() argumen keempat berupa string literal
 *
 * Pemeriksaan memakai tokenizer PHP, sehingga komentar dan string biasa
 * tidak ikut dihitung. Test ini berada di suite Unit agar berjalan di CI.
 *
 * @internal
 */
final class GuardRawSqlTest extends CIUnitTestCase
{
    public function testTidakAdaKondisiStringMentahDiPemanggilanQuery(): void
    {
        $pelanggaran = self::cariPelanggaran(APPPATH);

        $this->assertSame([], $pelanggaran, 'Kondisi string mentah ditemukan: ' . implode(', ', $pelanggaran));
    }

    /**
     * Mengembalikan daftar "berkas:baris nama" untuk setiap pemanggilan yang
     * memakai string literal pada argumen yang diperiksa.
     *
     * @return list<string>
     */
    public static function cariPelanggaran(string $direktori): array
    {
        $hasil    = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($direktori, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $berkas) {
            if ($berkas->getExtension() !== 'php') {
                continue;
            }

            $token = token_get_all((string) file_get_contents($berkas->getPathname()));

            foreach (array_keys($token) as $i) {
                if (! is_array($token[$i]) || $token[$i][0] !== T_STRING) {
                    continue;
                }

                $indeksArgumen = self::indeksArgumenDiperiksa($token, $i);
                if ($indeksArgumen === null) {
                    continue;
                }

                $bukaKurung = self::indeksSignifikanBerikutnya($token, $i + 1);
                if ($bukaKurung === null || $token[$bukaKurung] !== '(') {
                    continue;
                }

                $argumen = self::ambilArgumen($token, $bukaKurung);
                if (isset($argumen[$indeksArgumen]) && self::diawaliStringLiteral($argumen[$indeksArgumen])) {
                    $hasil[] = $berkas->getPathname() . ':' . $token[$i][2] . ' ' . $token[$i][1];
                }
            }
        }

        return $hasil;
    }

    /**
     * Indeks argumen yang diperiksa untuk pemanggilan di posisi $i, atau null
     * bila bukan pemanggilan yang diawasi (termasuk deklarasi fungsi).
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $token
     */
    private static function indeksArgumenDiperiksa(array $token, int $i): ?int
    {
        $sebelumnya = self::indeksSignifikanSebelumnya($token, $i - 1);
        if ($sebelumnya !== null && is_array($token[$sebelumnya]) && $token[$sebelumnya][0] === T_FUNCTION) {
            return null;
        }

        $nama                    = $token[$i][1];
        $adalahPemanggilanMetode = $sebelumnya !== null
            && is_array($token[$sebelumnya])
            && in_array($token[$sebelumnya][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);

        return match (true) {
            $nama === 'byId' && $adalahPemanggilanMetode => 0,
            $nama === 'ambilSatu' && $adalahPemanggilanMetode => 1,
            $nama === 'eult_auto_increment' => 3,
            default => null,
        };
    }

    /**
     * Memecah isi pemanggilan (setelah tanda kurung buka) menjadi argumen,
     * dengan menghormati kurung dan kurung siku yang bersarang.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $token
     *
     * @return list<list<array{0: int, 1: string, 2: int}|string>>
     */
    private static function ambilArgumen(array $token, int $bukaKurung): array
    {
        $argumen   = [];
        $saatIni   = [];
        $kedalaman = 0;

        for ($i = $bukaKurung + 1, $jumlah = count($token); $i < $jumlah; $i++) {
            $teks = is_array($token[$i]) ? $token[$i][1] : $token[$i];

            if ($teks === '(' || $teks === '[' || $teks === '{') {
                $kedalaman++;
            } elseif ($teks === ')' || $teks === ']' || $teks === '}') {
                if ($kedalaman === 0) {
                    break;
                }
                $kedalaman--;
            } elseif ($teks === ',' && $kedalaman === 0) {
                $argumen[] = $saatIni;
                $saatIni   = [];

                continue;
            }

            $saatIni[] = $token[$i];
        }

        if ($saatIni !== []) {
            $argumen[] = $saatIni;
        }

        return $argumen;
    }

    /**
     * Apakah token signifikan pertama pada argumen berupa string literal
     * (termasuk string berinterpolasi dan heredoc).
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $argumen
     */
    private static function diawaliStringLiteral(array $argumen): bool
    {
        foreach ($argumen as $t) {
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if (is_array($t)) {
                return in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_START_HEREDOC], true);
            }

            return $t === '"';
        }

        return false;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $token
     */
    private static function indeksSignifikanBerikutnya(array $token, int $dari): ?int
    {
        for ($i = $dari, $jumlah = count($token); $i < $jumlah; $i++) {
            if (! is_array($token[$i]) || ! in_array($token[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $token
     */
    private static function indeksSignifikanSebelumnya(array $token, int $dari): ?int
    {
        for ($i = $dari; $i >= 0; $i--) {
            if (! is_array($token[$i]) || ! in_array($token[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $i;
            }
        }

        return null;
    }
}
