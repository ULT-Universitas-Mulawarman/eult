"use strict";

/**
 * Menyertakan token CSRF CodeIgniter pada setiap request jQuery AJAX yang
 * mengubah data (POST/PUT/PATCH/DELETE) dan memperbarui token dari header
 * respons agar submit berikutnya pada halaman yang sama tidak memakai token
 * basi (Config\Security::$regenerate = true).
 *
 * Sumber token: <meta name="{csrf_header()}" content="{csrf_hash()}"> dan
 * <meta name="eult-csrf-field" content="{csrf_token()}"> pada layout.
 */
(function ($) {
    if (!$ || !$.ajaxPrefilter) {
        return;
    }

    var metaToken = function () {
        return $('meta[name="eult-csrf-header"]');
    };

    var headerName = function () {
        return metaToken().attr('data-header') || 'X-CSRF-TOKEN';
    };

    var fieldName = function () {
        return metaToken().attr('data-field') || '';
    };

    var currentToken = function () {
        return metaToken().attr('content') || '';
    };

    var setToken = function (token) {
        if (token) {
            metaToken().attr('content', token);
            $('input[type="hidden"][name="' + fieldName() + '"]').val(token);
        }
    };

    // Pesan dari server (JSON {status, message}) lebih tepat daripada teks
    // generik: HTTP 403 bisa berarti token CSRF basi ATAU akses tiket
    // ditolak (Ticketing::bolehAksesTiket), dan keduanya dulu ditampilkan
    // sebagai "Sesi Berakhir" — menyesatkan operator.
    var pesanServer = function (xhr) {
        try {
            var data = JSON.parse(xhr.responseText);
            if (data && typeof data.message === 'string' && data.message) {
                return data.message;
            }
        } catch (e) {
            // Body bukan JSON (mis. halaman HTML error) — pakai teks generik.
        }
        return '';
    };

    // Handler error default untuk semua AJAX jQuery yang tidak punya
    // callback error sendiri — sebelumnya kegagalan jaringan/server total
    // tak terlihat (tombol "Sedang Menyimpan" menggantung tanpa notifikasi).
    // Callback error eksplisit tidak terpengaruh (ajaxSetup hanya nilai
    // default).
    $.ajaxSetup({
        error: function (xhr) {
            if (typeof swal === 'undefined') {
                return;
            }
            if (xhr && (xhr.status === 403 || xhr.status === 419)) {
                swal.fire({
                    title: 'Permintaan Ditolak',
                    text: pesanServer(xhr) || 'Sesi atau token keamanan Anda sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.',
                    type: 'warning',
                    confirmButtonText: 'Muat Ulang Halaman'
                }).then(function (hasil) {
                    if (hasil.value) {
                        window.location.reload();
                    }
                });
                return;
            }
            swal.fire({
                title: 'Kesalahan Jaringan',
                text: 'Permintaan gagal diproses. Periksa koneksi lalu coba lagi, atau muat ulang halaman.',
                type: 'error'
            });
        }
    });

    $.ajaxPrefilter(function (options, originalOptions, jqXHR) {
        var method = (options.type || options.method || 'GET').toUpperCase();
        if (method === 'GET' || method === 'HEAD' || options.crossDomain) {
            return;
        }

        var token = currentToken();
        if (!token) {
            return;
        }

        jqXHR.setRequestHeader(headerName(), token);

        jqXHR.always(function () {
            var baru = jqXHR.getResponseHeader(headerName());
            if (baru) {
                setToken(baru);
            }
        });
    });

    // Form yang dirender ulang via AJAX (modal/inline) membawa hidden input
    // csrf_field() dari server; sinkronkan meta agar token terbaru dipakai.
    // MutationObserver, bukan DOMNodeInserted: mutation events dimatikan
    // default sejak Chromium 127 sehingga handler lama tidak pernah jalan.
    var sinkronDariForm = function (root) {
        if (!root || root.nodeType !== 1) {
            return;
        }

        var nama = fieldName();
        if (!nama) {
            return;
        }

        var $root = $(root);
        var input = $root.is('form') ? $root.find('input[type="hidden"][name="' + nama + '"]').first() : $();
        if (!input.length) {
            input = $root.find('form input[type="hidden"][name="' + nama + '"]').first();
        }

        if (input.length && input.val()) {
            metaToken().attr('content', input.val());
        }
    };

    if (window.MutationObserver) {
        new MutationObserver(function (records) {
            records.forEach(function (record) {
                Array.prototype.forEach.call(record.addedNodes, sinkronDariForm);
            });
        }).observe(document.documentElement, { childList: true, subtree: true });
    }
})(window.jQuery);
