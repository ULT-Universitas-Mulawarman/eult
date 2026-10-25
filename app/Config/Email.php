<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Email extends BaseConfig
{
    /**
     * Konfigurasi SMTP EULT. Nilai default di bawah ditimpa .env (EULT_MAIL_*)
     * di constructor — cukup ganti .env saat pindah server tanpa sentuh kode.
     */
    public string $fromEmail  = 'tiketult@unmul.ac.id';
    public string $fromName   = 'EULT UNMUL';
    public string $recipients = '';

    /**
     * The "user agent"
     */
    public string $userAgent = 'CodeIgniter';

    /**
     * The mail sending protocol: mail, sendmail, smtp
     */
    public string $protocol = 'smtp';

    /**
     * The server path to Sendmail.
     */
    public string $mailPath = '/usr/sbin/sendmail';

    /**
     * SMTP Server Hostname (tanpa prefix ssl:// atau tls:// — prefix
     * ditambahkan otomatis oleh CI4 dari SMTPPort/SMTPCrypto; bila host
     * mengandung prefix, fsockopen menerima "ssl://ssl://..." dan gagal
     * DNS seperti kasus tiket ODTT-OFOJ-001).
     */
    public string $SMTPHost = 'smtp.gmail.com';

    /**
     * Which SMTP authentication method to use: login, plain
     */
    public string $SMTPAuthMethod = 'login';

    /**
     * SMTP Username
     */
    public string $SMTPUser = 'tiketult@unmul.ac.id';

    /**
     * SMTP Password
     */
    public string $SMTPPass = '';

    /**
     * SMTP Port
     */
    public int $SMTPPort = 465;

    /**
     * SMTP Timeout (in seconds)
     */
    public int $SMTPTimeout = 5;

    /**
     * Enable persistent SMTP connections
     */
    public bool $SMTPKeepAlive = false;

    /**
     * SMTP Encryption.
     *
     * @var string '', 'tls' or 'ssl'. 'tls' will issue a STARTTLS command
     *             to the server. 'ssl' means implicit SSL. Connection on port
     *             465 should set this to ''.
     */
    public string $SMTPCrypto = 'ssl';

    /**
     * Enable word-wrap
     */
    public bool $wordWrap = true;

    /**
     * Character count to wrap at
     */
    public int $wrapChars = 76;

    /**
     * Type of mail, either 'text' or 'html'
     */
    public string $mailType = 'html';

    /**
     * Character set (utf-8, iso-8859-1, etc.)
     */
    public string $charset = 'UTF-8';

    /**
     * Whether to validate the email address
     */
    public bool $validate = false;

    /**
     * Email Priority. 1 = highest. 5 = lowest. 3 = normal
     */
    public int $priority = 3;

    /**
     * Newline character. (Use “\r\n” to comply with RFC 822)
     */
    public string $CRLF = "\r\n";

    /**
     * Newline character. (Use “\r\n” to comply with RFC 822)
     */
    public string $newline = "\r\n";

    /**
     * Enable BCC Batch Mode.
     */
    public bool $BCCBatchMode = false;

    /**
     * Number of emails in each BCC batch
     */
    public int $BCCBatchSize = 200;

    /**
     * Enable notify message from server
     */
    public bool $DSN = false;

    public function __construct()
    {
        parent::__construct();

        // Timpa SMTP dari .env (EULT_MAIL_*) agar cukup ganti .env saat pindah server.
        $baca = static fn (string $kunci, string $bawaan = ''): string => ($v = env($kunci)) !== null && $v !== '' ? (string) $v : $bawaan;

        $this->SMTPHost  = $this->normalisasiHost($baca('EULT_MAIL_HOST', $this->SMTPHost));
        $this->SMTPPort  = (int) $baca('EULT_MAIL_PORT', (string) $this->SMTPPort);
        $this->SMTPUser  = $baca('EULT_MAIL_USER', $this->SMTPUser);
        $this->SMTPPass  = $baca('EULT_MAIL_PASS', $this->SMTPPass);
        $this->fromEmail = $baca('EULT_MAIL_FROM', $this->fromEmail);
        $this->fromName  = $baca('EULT_MAIL_NAME', $this->fromName);

        if ($this->SMTPPass === '') {
            log_message('critical', 'EULT email: SMTPPass kosong — isi EULT_MAIL_PASS di .env (App Password Gmail bila memakai smtp.gmail.com). Pengiriman email akan gagal auth.');
        }
    }

    /**
     * Buang prefix ssl:// / tls:// bila operator terlanjur menulisnya di
     * .env (EULT_MAIL_HOST), karena CI4 menambahkannya sendiri dari
     * SMTPPort/SMTPCrypto. Tanpa normalisasi, host menjadi
     * "ssl://ssl://..." dan gagal DNS (kasus tiket ODTT-OFOJ-001).
     */
    private function normalisasiHost(string $host): string
    {
        $host = trim($host);

        foreach (['ssl://', 'tls://'] as $prefix) {
            if (str_starts_with(strtolower($host), $prefix)) {
                $host = substr($host, strlen($prefix));
                break;
            }
        }

        return $host;
    }
}
