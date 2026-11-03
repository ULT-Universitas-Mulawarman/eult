
<!DOCTYPE html>
<!--
Template Name: Metronic - Bootstrap 4 HTML, React, Angular 9 & VueJS Admin Dashboard Theme
Author: KeenThemes
Website: http://www.keenthemes.com/
Contact: support@keenthemes.com
Follow: www.twitter.com/keenthemes
Dribbble: www.dribbble.com/keenthemes
Like: www.facebook.com/keenthemes
Purchase: https://1.envato.market/EA4JP
Renew Support: https://1.envato.market/EA4JP
License: You must have a valid license purchased only from themeforest(the above link) in order to legally use the theme for your project.
-->
<html lang="en">
    <!--begin::Head-->
    <head><base href="../../../">
        <meta charset="utf-8" />
        <title><?= base_url(); ?> | Unit Layanan Terpadu UNMUL</title>
        <meta name="description" content="Invoice example" />
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
        <link rel="canonical" href="https://keenthemes.com/metronic" />
        <!--begin::Fonts-->
        <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700" />
        <!--end::Fonts-->
        <!--begin::Global Theme Styles(used by all pages)-->
        <link href="<?= eult_url_aset('assets_val/plugins/global/plugins.bundle.css') ?>" rel="stylesheet" type="text/css" />
        <link href="<?= eult_url_aset('assets_val/plugins/custom/prismjs/prismjs.bundle.css') ?>" rel="stylesheet" type="text/css" />
        <link href="<?= eult_url_aset('assets_val/css/style.bundle.css') ?>" rel="stylesheet" type="text/css" />
        <!--end::Global Theme Styles-->
        <!--begin::Layout Themes(used by all pages)-->
        <!--end::Layout Themes-->
        <link rel="shortcut icon" href="<?= base_url('assets_val/media/logos/favicon.ico') ?>" />
        <style {csp-style-nonce}>
            .segel-verifikasi { flex: none; }
            .segel-verifikasi .segel-lingkaran { stroke-dasharray: 151; stroke-dashoffset: 0; animation: segel-lingkaran 0.7s cubic-bezier(0.16, 1, 0.3, 1) both; }
            .segel-verifikasi .segel-centang { stroke-dasharray: 36; stroke-dashoffset: 0; animation: segel-centang 0.35s cubic-bezier(0.16, 1, 0.3, 1) 0.45s both; }
            @keyframes segel-lingkaran { from { stroke-dashoffset: 151; } to { stroke-dashoffset: 0; } }
            @keyframes segel-centang { from { stroke-dashoffset: 36; } to { stroke-dashoffset: 0; } }
            .halaman-pdf-masuk { animation: halaman-pdf-masuk 0.45s cubic-bezier(0.16, 1, 0.3, 1) both; }
            @keyframes halaman-pdf-masuk { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
            @media (prefers-reduced-motion: reduce) {
                .segel-verifikasi .segel-lingkaran, .segel-verifikasi .segel-centang { animation: none; }
                .halaman-pdf-masuk { animation-duration: 0.01s; }
                @keyframes halaman-pdf-masuk { from { opacity: 0; transform: none; } to { opacity: 1; transform: none; } }
            }
        </style>
    </head>
    <!--end::Head-->
    <!--begin::Body-->
    <body id="kt_body" class="print-content-only quick-panel-right demo-panel-right offcanvas-right header-fixed header-mobile-fixed subheader-enabled aside-enabled aside-static page-loading">
        <!--begin::Main-->
        <!--begin::Header Mobile-->
        <div id="kt_header_mobile" class="header-mobile header-mobile-fixed">
            <!--begin::Logo-->
            <a href="<?= base_url(); ?>">
                <img alt="Logo" src="<?= base_url(); ?>assets_val/logo-ult12.png" class="logo-default max-h-60px" />
            </a>
            <div class="header-title"> <h1>Unit Layanan Terpadu Universitas Mulawarman</h1></div>
            <!--end::Logo-->            
        </div>
        <!--end::Header Mobile-->
        <div class="d-flex flex-column flex-root">
            <!--begin::Page-->
            <div class="d-flex flex-row flex-column-fluid page">
                <!--begin::Aside-->
                <div class="aside aside-left d-flex flex-column flex-row-auto" id="kt_aside">
                    <!--begin::Aside Menu-->
                    <div class="aside-menu-wrapper flex-column-fluid" id="kt_aside_menu_wrapper">
                        <!--begin::Menu Container-->
                        <div id="kt_aside_menu" class="aside-menu min-h-lg-800px" data-menu-vertical="1" data-menu-scroll="1" data-menu-dropdown-timeout="500">
                        </div>
                        <!--end::Menu Container-->
                    </div>
                    <!--end::Aside Menu-->
                </div>
                <!--end::Aside-->
                <!--begin::Wrapper-->
                <div class="d-flex flex-column flex-row-fluid wrapper" id="kt_wrapper">
                    <!--begin::Header-->
                    <div id="kt_header" class="header header-fixed">
                        <!--begin::Container-->
                        <div class="container d-flex align-items-stretch justify-content-between">
                            <!--begin::Left-->
                            <div class="d-none d-lg-flex align-items-center mr-3">
                                <!--begin::Logo-->
                                <a href="<?= base_url(); ?>">
                                    <img alt="Logo" src="<?= base_url(); ?>assets_val/logo-ult12.png" class="logo-sticky max-h-60px" />
                                </a>
                                <div class="header-title"> <h1>Unit Layanan Terpadu Universitas Mulawarman</h1></div>
                            </div>
                            <div class="topbar">
                        <!--begin::Tablet & Mobile Search-->
                        <div class="dropdown d-flex d-lg-none">
                            <!--begin::Toggle-->
                            <div class="topbar-item" data-toggle="dropdown" data-offset="10px,0px">
                                <div class="btn btn-icon btn-clean btn-lg btn-dropdown mr-1">
                                    <span class="svg-icon svg-icon-xl">
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="topbar-item mr-4">
                            <a href="<?=base_url();?>">
                                <div class="btn font-weight-bolder btn-sm btn-light-warning px-5">
                                    Login
                                </div>
                            </a>
                            &nbsp
                            <a href="http://ult.unmul.ac.id/">
                                <div class="btn font-weight-bolder btn-sm btn-light-success px-5">
                                    Website ULT
                                </div>
                            </a>                            
                        </div>
                        <!--end::User-->
                        <!--begin::Notifications-->

                        <!--end::Notifications-->
                    </div>
                        </div>
                        <!--end::Container-->
                    </div>
                    <!--end::Header-->
                    <!--begin::Content-->
                    <div class="content d-flex flex-column flex-column-fluid" id="kt_content">
                        <!--begin::Entry-->
                        <div class="d-flex flex-column-fluid">
                            <!--begin::Container-->
                            <div class="container">
                                <!-- begin::Card-->
                                <div class="card card-custom overflow-hidden">
                                    <div class="card-header ribbon ribbon-top ribbon-ver">
                                        <div class="ribbon-target bg-danger" style="top: -2px; right: 20px;">                                            
                                        </div>                              
                                        <h3 class="card-title">Validasi Surat Unit Layanan Terpadu Universitas Mulawarman</h3>
                                    </div>
                                    <div class="card-body p-0">
                                        <div class="card-body py-12">
                                            <div class="row">
                                                <div class="col-lg-12">                         
                                                    <?php if ($datas == false): ?>
                                                        <div class="alert alert-danger" role="alert">
                                                            Surat tidak ditemukan. Kunci validasi tidak valid atau sudah tidak berlaku. Pindai ulang kode QR pada surat cetak; bila tetap gagal, hubungi Unit Layanan Terpadu Universitas Mulawarman.
                                                        </div>
                                                    <?php else: ?>
                                                    <div class="d-flex align-items-center mb-4 p-3" style="background: rgba(10, 187, 135, 0.08); border: 1px solid #ebedf2; border-radius: 4px;">
                                                        <svg class="segel-verifikasi mr-3" width="48" height="48" viewBox="0 0 48 48" fill="none" aria-hidden="true" focusable="false">
                                                            <circle class="segel-lingkaran" cx="24" cy="24" r="22" stroke="#0abb87" stroke-width="2.5" transform="rotate(-90 24 24)" pathLength="151" stroke-dasharray="151" />
                                                            <path class="segel-centang" d="M15 24.5 L21.5 31 L33 18" stroke="#0abb87" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" pathLength="36" stroke-dasharray="36" />
                                                        </svg>
                                                        <div>
                                                            <div class="font-weight-bold text-dark" style="font-size: 1rem; line-height: 1.3;">Surat Terverifikasi</div>
                                                            <div class="small" style="color: #646c9a;">Hasil pindai kode QR ini cocok dengan arsip digital Unit Layanan Terpadu Universitas Mulawarman.</div>
                                                        </div>
                                                    </div>
                                                    <table border="0" width="60%">
                                                    <tr>
                                                        <td>NIM/NIP</td>
                                                        <td>:</td>
                                                        <td><?= $datas != false ? esc($datas['ticketIdentitas'] ?? '') : '' ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td>Nama</td>
                                                        <td>:</td>
                                                        <td><?= $datas != false ? esc($datas['ticketName'] ?? '') : '' ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td>Email</td>
                                                        <td>:</td>
                                                        <td><?= $datas != false ? esc(eult_samarkan_email($datas['ticketEmail'] ?? '')) : '' ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td>No. HP</td>
                                                        <td>:</td>
                                                        <td><?= $datas != false ? esc(eult_samarkan_telepon($datas['ticketNoHp'] ?? '')) : '' ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td>Nomor Tiket</td>
                                                        <td>:</td>
                                                        <td><?= $datas != false ? esc($datas['ticketTrackingId'] ?? '') : '' ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td>Tanggal Tiket</td>
                                                        <td>:</td>
                                                        <td><?= $datas != false ? esc(datetoindo($datas['ticketCreated']) . ' ' . date('h:i A', strtotime($datas['ticketCreated']))) : '' ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td>Layanan</td>
                                                        <td>:</td>
                                                        <td><?= esc(($datas['sCatNama'] ?? '') . ' - ' . ($datas['categoryNama'] ?? '')) ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td>Status Tiket</td>
                                                        <td>:</td>
                                                        <td><?= esc($datas['statusNama'] ?? '') ?></td>
                                                    </tr>
                                                </table>
                                                    
                                                    <hr>

                                                    <!-- Navigasi top-level TIDAK diatur frame-src/child-src, jadi tautan ini jalan di mobile meski pratinjau ter-embed diblokir penampil PDF bawaan browser. -->
                                                    <div class="mb-3">
                                                        <a href="<?= esc($loadpdf_url ?? '') ?>" target="_blank" rel="noopener" class="btn btn-light-primary btn-sm font-weight-bold px-5">Buka Surat PDF di Tab Baru</a>
                                                        <span class="text-muted small ml-2">Gunakan tombol ini bila pratinjau surat tidak tampil di perangkat mobile.</span>
                                                    </div>
                                                    <!-- Pratinjau ter-embed hanya untuk layar besar; di mobile penampil PDF bawaan browser menolak sub-frame internalnya (konsol: Framing '' violates frame-src) sehingga yang tampil hanya placeholder terblokir. -->
                                                    <div class="d-none d-lg-block">
                                                        <iframe id="iframe-pratinjau-pdf" data-pdf-url="<?= esc($loadpdf_url ?? '') ?>" frameborder="0" width="100%" height="600" title="Pratinjau surat PDF"></iframe>
                                                    </div>
                                                    <!-- Di mobile PDF dirender PDF.js ke canvas (tanpa frame sama sekali), sehingga tetap tampil inline meski penampil PDF bawaan browser terblokir CSP. -->
                                                    <div id="penampil-pdf-mobile" class="d-lg-none">
                                                        <p id="status-pdf-mobile" class="text-muted small mb-2">Memuat pratinjau surat...</p>
                                                        <div id="daftar-halaman-pdf"></div>
                                                    </div>
                                                    <script {csp-script-nonce}>
                                                        (function () {
                                                            var bingkai = document.getElementById('iframe-pratinjau-pdf');
                                                            if (!bingkai) {
                                                                return;
                                                            }
                                                            var urlPdf = bingkai.getAttribute('data-pdf-url');
                                                            // Iframe tanpa src tidak pernah membuat frame, jadi di mobile tidak ada request yang ditolak frame-src. URL baru dipasang pada layar besar.
                                                            if (window.matchMedia('(min-width: 992px)').matches) {
                                                                bingkai.src = urlPdf;
                                                                return;
                                                            }
                                                            var status = document.getElementById('status-pdf-mobile');
                                                            var daftar = document.getElementById('daftar-halaman-pdf');
                                                            function gagal() {
                                                                if (status) {
                                                                    status.textContent = 'Pratinjau tidak dapat dimuat. Gunakan tombol Buka Surat PDF di Tab Baru di atas.';
                                                                }
                                                            }
                                                            var skrip = document.createElement('script');
                                                            skrip.src = <?= json_encode(eult_url_aset('assets_val/js/pdfjs/pdf.min.js')); ?>;
                                                            skrip.onerror = gagal;
                                                            skrip.onload = function () {
                                                                window.pdfjsLib.GlobalWorkerOptions.workerSrc = <?= json_encode(eult_url_aset('assets_val/js/pdfjs/pdf.worker.min.js')); ?>;
                                                                window.pdfjsLib.getDocument({ url: urlPdf }).promise.then(function (pdf) {
                                                                    function renderHalaman(nomor) {
                                                                        if (status && nomor <= pdf.numPages) {
                                                                            status.textContent = 'Memuat halaman ' + nomor + ' dari ' + pdf.numPages + '...';
                                                                        }
                                                                        if (nomor > pdf.numPages) {
                                                                            if (status) {
                                                                                status.textContent = 'Pratinjau surat (' + pdf.numPages + ' halaman). Gunakan tombol di atas untuk membuka ukuran penuh.';
                                                                            }
                                                                            return;
                                                                        }
                                                                        pdf.getPage(nomor).then(function (halaman) {
                                                                            var lebar = daftar.clientWidth || 320;
                                                                            var skala = lebar / halaman.getViewport({ scale: 1 }).width;
                                                                            var rasio = Math.min(window.devicePixelRatio || 1, 2);
                                                                            var viewport = halaman.getViewport({ scale: skala * rasio });
                                                                            var kanvas = document.createElement('canvas');
                                                                            kanvas.className = 'halaman-pdf-masuk';
                                                                            kanvas.width = viewport.width;
                                                                            kanvas.height = viewport.height;
                                                                            kanvas.style.width = '100%';
                                                                            kanvas.style.height = 'auto';
                                                                            kanvas.style.display = 'block';
                                                                            kanvas.style.margin = '0 auto 12px';
                                                                            kanvas.style.background = '#ffffff';
                                                                            kanvas.style.border = '1px solid #ebedf2';
                                                                            daftar.appendChild(kanvas);
                                                                            halaman.render({ canvasContext: kanvas.getContext('2d'), viewport: viewport }).promise.then(function () {
                                                                                renderHalaman(nomor + 1);
                                                                            }, gagal);
                                                                        }, gagal);
                                                                    }
                                                                    renderHalaman(1);
                                                                }, gagal);
                                                            };
                                                            document.head.appendChild(skrip);
                                                        })();
                                                    </script>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <!-- end::Card-->
                            </div>
                            <!--end::Container-->
                        </div>
                        <!--end::Entry-->
                    </div>
                    <!--end::Content-->
                    <!--begin::Footer-->
                    <div class="footer bg-white py-4 d-flex flex-lg-column" id="kt_footer">
                        <!--begin::Container-->
                        <div class="container d-flex flex-column flex-md-row align-items-center justify-content-between">
                            <!--begin::Copyright-->
                            <div class="text-dark order-2 order-md-1">
                                <span class="text-muted font-weight-bold mr-2">Copyright&nbsp;&copy;&nbsp;2019&nbsp;</span><a href="https://ict.unmul.ac.id/" target="_blank" class="text-dark-75 text-hover-primary">UPT. Teknologi Informasi dan Komunikasi</a>
                            </div>
                            <!--end::Copyright-->
                            <!--begin::Nav-->
                            <div class="nav nav-dark order-1 order-md-2">
                                <a href="https://eult.unmul.ac.id/" target="_blank" class="text-dark-75 text-hover-primary">
                                    Unit Layanan Terpadu Universitas Mulawarman</a>
                            </div>
                            <!--end::Nav-->
                        </div>
                        <!--end::Container-->
                    </div>
                    <!--end::Footer-->                    
                </div>
                <!--end::Wrapper-->
            </div>
            <!--end::Page-->
        </div>
        
        </div>
        <!--end::Chat Panel-->
        <!--begin::Scrolltop-->
        
        <!--end::Scrolltop-->
        <!--begin::Sticky Toolbar-->
        
        <!--end::Sticky Toolbar-->
        <!--begin::Demo Panel-->
        
        <!--end::Demo Panel-->

        <!--begin::Global Config(global config for global JS scripts)-->
        <script {csp-script-nonce}>var KTAppSettings = { "breakpoints": { "sm": 576, "md": 768, "lg": 992, "xl": 1200, "xxl": 1200 }, "colors": { "theme": { "base": { "white": "#ffffff", "primary": "#6993FF", "secondary": "#E5EAEE", "success": "#1BC5BD", "info": "#8950FC", "warning": "#FFA800", "danger": "#F64E60", "light": "#F3F6F9", "dark": "#212121" }, "light": { "white": "#ffffff", "primary": "#E1E9FF", "secondary": "#ECF0F3", "success": "#C9F7F5", "info": "#EEE5FF", "warning": "#FFF4DE", "danger": "#FFE2E5", "light": "#F3F6F9", "dark": "#D6D6E0" }, "inverse": { "white": "#ffffff", "primary": "#ffffff", "secondary": "#212121", "success": "#ffffff", "info": "#ffffff", "warning": "#ffffff", "danger": "#ffffff", "light": "#464E5F", "dark": "#ffffff" } }, "gray": { "gray-100": "#F3F6F9", "gray-200": "#ECF0F3", "gray-300": "#E5EAEE", "gray-400": "#D6D6E0", "gray-500": "#B5B5C3", "gray-600": "#80808F", "gray-700": "#464E5F", "gray-800": "#1B283F", "gray-900": "#212121" } }, "font-family": "Poppins" };</script>
        <!--end::Global Config-->
        <!--begin::Global Theme Bundle(used by all pages)-->
        <script src="<?= eult_url_aset('assets_val/plugins/global/plugins.bundle.js') ?>"></script>
        <script src="<?= eult_url_aset('assets_val/plugins/custom/prismjs/prismjs.bundle.js') ?>"></script>
        <script src="<?= eult_url_aset('assets_val/js/scripts.bundle.js') ?>"></script>
        <!--end::Global Theme Bundle-->
    </body>
    <!--end::Body-->
</html>