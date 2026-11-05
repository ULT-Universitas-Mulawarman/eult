<?php

namespace App\Libraries;

/**
 * Kebijakan aksi tiket per peran dan per unit.
 *
 * Kelas murni (tanpa DB/session) yang memusatkan aturan "siapa boleh apa
 * atas tiket mana". Aturan mencerminkan getaction versi awal (CI3):
 * OPERATOR front-office hanya triase (assign/tolak saat status 1) dan
 * validasi akhir, sedangkan verifikasi berkas (terima), paraf, TTD, dan
 * surat adalah hak unit pemilik tiket (home unit).
 *
 * Dipakai dua arah agar tidak ada dua sumber kebenaran:
 *  - Ticketing controller: penjaga server-side tiap aksi mutasi.
 *  - Halaman detail: flag tampil tiap tombol (controller menghitung,
 *    view hanya membaca; flag hilang berarti tombol mati/fail-closed).
 *
 * Baris tiket memakai kunci hasil ModelTicketing::byId(): ticketAssign,
 * ticketIsVerified, ticketStatus, ticketSuratCreated, sCatDisposisi,
 * jenislayananId, disposisiIsTrue.
 */
final class KebijakanTiket
{
    /**
     * @param mixed $nilai
     */
    private static function int(mixed $nilai): int
    {
        return (int) $nilai;
    }

    public static function isAdmin(string $grup): bool
    {
        return $grup === 'ADMIN';
    }

    public static function isOperator(string $grup): bool
    {
        return strpos($grup, 'OPERATOR') !== false;
    }

    public static function isVerifikator(string $grup): bool
    {
        return strpos($grup, 'KASUBBAG') !== false || strpos($grup, 'VERIFIKATOR') !== false;
    }

    public static function isProduksi(string $grup): bool
    {
        return strpos($grup, 'KASUBBAG') !== false || strpos($grup, 'PRODUKSI') !== false;
    }

    public static function isDisposisi(string $grup): bool
    {
        return strpos($grup, 'DISPOSISI') !== false;
    }

    /**
     * Tiket sedang berada di home unit pengguna dan disposisinya masih terbuka.
     *
     * @param array<int, string> $homeUnitIds Daftar sgroupunitUnitId (IsHome > 0).
     */
    public static function isHome(mixed $ticketAssign, array $homeUnitIds, mixed $disposisiIsTrue): bool
    {
        if ($ticketAssign === null || $ticketAssign === '' || $homeUnitIds === []) {
            return false;
        }

        if (self::int($disposisiIsTrue) === 1) {
            return false;
        }

        foreach ($homeUnitIds as $unitId) {
            if ((string) $unitId === (string) $ticketAssign) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifikasi berkas (terima): KASUBBAG/VERIFIKATOR home unit, BOTTOMUP, belum verifikasi.
     *
     * @param array<string, mixed> $tiket
     * @param array<int, string>   $homeUnitIds
     */
    public static function bolehTerima(array $tiket, string $grup, array $homeUnitIds): bool
    {
        if (self::isAdmin($grup)) {
            return true;
        }

        // OPERATOR front-office tidak punya hak verifikasi unit.
        if (self::isOperator($grup)) {
            return false;
        }

        return self::isVerifikator($grup)
            && self::int($tiket['ticketIsVerified'] ?? 0) !== 1
            && ($tiket['sCatDisposisi'] ?? null) === 'BOTTOMUP'
            && self::isHome($tiket['ticketAssign'] ?? null, $homeUnitIds, $tiket['disposisiIsTrue'] ?? null);
    }

    /**
     * Penolakan: OPERATOR saat status 1 (triage), atau verifikator home unit.
     *
     * @param array<string, mixed> $tiket
     * @param array<int, string>   $homeUnitIds
     */
    public static function bolehTolak(array $tiket, string $grup, array $homeUnitIds): bool
    {
        if (self::isAdmin($grup)) {
            return true;
        }

        if (self::isOperator($grup)) {
            return self::int($tiket['ticketStatus'] ?? 0) === 1;
        }

        if (! self::isVerifikator($grup)) {
            return false;
        }

        if (! self::isHome($tiket['ticketAssign'] ?? null, $homeUnitIds, $tiket['disposisiIsTrue'] ?? null)) {
            return false;
        }

        $bottomupBelumVerifikasi = ($tiket['sCatDisposisi'] ?? null) === 'BOTTOMUP'
            && self::int($tiket['ticketIsVerified'] ?? 0) !== 1;

        return $bottomupBelumVerifikasi || self::int($tiket['jenislayananId'] ?? 0) === 2;
    }

    /**
     * Assign tiket ke unit (BO/Kasubbag): OPERATOR saat status 1 layanan sehari==2.
     *
     * @param array<string, mixed> $tiket
     */
    public static function bolehAssign(array $tiket, string $grup): bool
    {
        if (self::isAdmin($grup)) {
            return true;
        }

        return self::isOperator($grup)
            && self::int($tiket['ticketStatus'] ?? 0) === 1
            && self::int($tiket['jenislayananId'] ?? 0) === 2;
    }

    /**
     * Paraf dan disposisi (accept): DISPOSISI, atau verifikator yang
     * tiketnya sudah terverifikasi dan suratnya sudah dibuat.
     *
     * @param array<string, mixed> $tiket
     */
    public static function bolehAccept(array $tiket, string $grup): bool
    {
        if (self::isAdmin($grup)) {
            return true;
        }

        if (self::isOperator($grup)) {
            return false;
        }

        if (self::int($tiket['jenislayananId'] ?? 0) !== 2) {
            return false;
        }

        if (self::isDisposisi($grup)) {
            return true;
        }

        // Syarat verifikator sama dengan getaction: terverifikasi + surat ada.
        $terverifikasi = self::int($tiket['ticketIsVerified'] ?? 0) === 1
            && ($tiket['sCatDisposisi'] ?? null) === 'BOTTOMUP';
        $suratAda = ($tiket['sCatDisposisi'] ?? null) === 'BOTTOMUP'
            && ! empty($tiket['ticketSuratCreated']);

        return self::isVerifikator($grup) && $terverifikasi && $suratAda;
    }

    /**
     * Tanda tangan (validasi): DISPOSISI/verifikator home unit yang siap TTD.
     *
     * @param array<string, mixed> $tiket
     * @param array<int, string>   $homeUnitIds
     */
    public static function bolehValidasi(array $tiket, string $grup, array $homeUnitIds): bool
    {
        if (self::isAdmin($grup)) {
            return true;
        }

        if (self::isOperator($grup)) {
            return false;
        }

        if (! self::isDisposisi($grup) && ! self::isVerifikator($grup)) {
            return false;
        }

        if (self::int($tiket['jenislayananId'] ?? 0) !== 2) {
            return false;
        }

        if (($tiket['sCatDisposisi'] ?? null) !== 'BOTTOMUP') {
            return false;
        }

        if (self::int($tiket['ticketIsVerified'] ?? 0) !== 1 || empty($tiket['ticketSuratCreated'])) {
            return false;
        }

        return self::isHome($tiket['ticketAssign'] ?? null, $homeUnitIds, $tiket['disposisiIsTrue'] ?? null);
    }

    /**
     * Buat surat: produksi + terverifikasi + surat belum ada.
     *
     * @param array<string, mixed> $tiket
     */
    public static function bolehBuatSurat(array $tiket, string $grup): bool
    {
        if (self::isAdmin($grup)) {
            return true;
        }

        return self::isProduksi($grup)
            && self::int($tiket['ticketIsVerified'] ?? 0) === 1
            && empty($tiket['ticketSuratCreated']);
    }

    /**
     * Edit surat: produksi + terverifikasi + surat sudah ada + home unit.
     *
     * @param array<string, mixed> $tiket
     * @param array<int, string>   $homeUnitIds
     */
    public static function bolehEditSurat(array $tiket, string $grup, array $homeUnitIds): bool
    {
        if (self::isAdmin($grup)) {
            return true;
        }

        return self::isProduksi($grup)
            && self::int($tiket['ticketIsVerified'] ?? 0) === 1
            && ! empty($tiket['ticketSuratCreated'])
            && self::isHome($tiket['ticketAssign'] ?? null, $homeUnitIds, $tiket['disposisiIsTrue'] ?? null);
    }
}
