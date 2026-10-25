<!-- BEGIN: Subheader -->
<?= $this->include('layouts/subheader') ?>
<!-- END: Subheader -->

<!-- begin:: Content -->
<div class="kt-container  kt-container--fluid  kt-grid__item kt-grid__item--fluid">
    <div class="row">
        <div class="col-md-12">
            <!--begin::Portlet-->
            <div class="kt-portlet">
                <div class="kt-portlet__head">
                    <div class="kt-portlet__head-label">
                        <h3 class="kt-portlet__head-title">
                            <?= strtoupper(esc($page_judul ?? 'Hak Akses User')) ?>
                        </h3>
                    </div>
                </div>
                <div class="kt-portlet__body">
                    <!--
                        Modul ini hanya menampilkan daftar grup pengguna sebagai
                        informasi (lihat docblock Hakaksesuser). Form "pilih role
                        lalu Show" dari versi lama tidak pernah punya endpoint
                        maupun handler JS — di CI3 controller-nya kosong dan view
                        ini tidak pernah dirender — jadi tidak dipertahankan.
                    -->
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead>
                                <tr>
                                    <th style="width: 50px;">No</th>
                                    <th>Nama Grup</th>
                                    <th>Unit</th>
                                    <th>Keterangan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (! empty($datas)) : ?>
                                    <?php $no = 1; ?>
                                    <?php foreach ($datas as $row) : ?>
                                        <tr>
                                            <td><?= $no++ ?></td>
                                            <td><?= esc($row['sgroupNama'] ?? '') ?></td>
                                            <td><?= esc($row['sgroupUnit'] ?? '') ?></td>
                                            <td><?= esc($row['sgroupKeterangan'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted">Belum ada data grup pengguna.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <!--end::Portlet-->
        </div>
    </div>
</div>
