<div class="kt-container  kt-container--fluid  kt-grid__item kt-grid__item--fluid">
    <div class="row">
        <div class="col-md-12">
            <div id="response"></div>
            <!--begin::Portlet-->
            <div class="kt-portlet">
                <div class="kt-portlet__head">
                    <div class="kt-portlet__head-label">
                        <h3 class="kt-portlet__head-title">
                            <?= esc(strtoupper((string) ($page_judul ?? ''))) ?>
                        </h3>
                    </div>
                </div>

                <!--begin::Form-->
                <form class="kt-form" action="<?= esc($save_url ?? '') ?>" method="post" id="form_ticketing" enctype="multipart/form-data">
                    <?= csrf_field() ?>

                    <div class="kt-portlet__body">
                        <div class="form-group row">
                            <div class="col-md-9">
                                <input type="hidden" name="ticketIdOld" value="<?= $datas != false ? esc($datas['ticketTrackingId'] ?? '', 'attr') : '' ?>">
                                <input type="text" class="form-control" id="ticketIdentitas" name="ticketIdentitas" placeholder="Identitas NIM / NIP / NIK" aria-describedby="Identitas NIM / NIP / NIK / NPWP" value="<?= $datas != false ? esc($datas['ticketIdentitas'] ?? '', 'attr') : '' ?>">
                                <span class="form-text text-muted">NIM: 0707055155; NIP: 198810242014041001; NIK: 647203241088003; NPWP: 836972869722000</span>
                            </div>
                            <div class="col-md-3">
                                <a href="#" class="btn btn-label-twitter" id="btn_check">Cek Identitas</a>
                            </div>
                        </div>
                        <div class="form-group">
                            <input type="text" class="form-control" name="ticketName" placeholder="Nama Mahasiswa / Pegawai / Mitra Kerjasama" aria-describedby="Nama" value="<?= $datas != false ? esc($datas['ticketName'] ?? '', 'attr') : '' ?>">
                        </div>
                        <div class="form-group">
                            <select class="form-control m-select2" name="ticketCategories" id="ticketCategories">
                                <option value="">Pilih Layanan</option>
                                <?php
                                if ($datas != false && ! empty($layanan)) {
                                    $option = '';
                                    $head   = null;
                                    foreach ($layanan as $row) {
                                        $now_head = (string) ($row['jenislayananNama'] ?? '');
                                        if ($head !== $now_head) {
                                            if ($head !== null) {
                                                $option .= '</optgroup>';
                                            }
                                            $head    = $now_head;
                                            $option .= '<optgroup label="' . esc($head, 'attr') . '">';
                                        }
                                        $terpilih = ((string) ($row['layananId'] ?? '') === (string) ($datas['ticketCategories'] ?? '')) ? 'selected' : '';
                                        $option  .= '<option value="' . esc((string) ($row['layananId'] ?? ''), 'attr') . '" ' . $terpilih . '>' . esc((string) ($row['layananNama'] ?? '')) . ' (' . esc((string) ($row['unitNama'] ?? '')) . ')</option>';
                                    }
                                    if ($head !== null) {
                                        $option .= '</optgroup>';
                                    }
                                    echo $option;
                                }
                                ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <input type="text" class="form-control" name="ticketEmail" placeholder="Email" aria-describedby="Email" value="<?= $datas != false ? esc($datas['ticketEmail'] ?? '', 'attr') : '' ?>">
                        </div>

                        <div class="form-group">
                            <input type="text" class="form-control" name="ticketNoHp" placeholder="Nomor Handphone" aria-describedby="Contact Person" value="<?= $datas != false ? esc($datas['ticketNoHp'] ?? '', 'attr') : '' ?>">
                            <span class="form-text text-muted">Contoh: 085211224433</span>
                        </div>
                        <div class="form-group">
                            <select class="form-control m-select2" name="ticketPriority">
                                <option value="">Pilih Prioritas</option>
                                <?php
                                if ($r_priority != false) :
                                    foreach ($r_priority as $row) :
                                        $terpilihPrio = ($datas != false && (string) ($row['priorityId'] ?? '') === (string) ($datas['ticketPriority'] ?? '')) ? 'selected' : '';
                                        echo '<option value="' . esc((string) ($row['priorityId'] ?? ''), 'attr') . '" ' . $terpilihPrio . '>' . esc((string) ($row['priorityName'] ?? '')) . '</option>';
                                    endforeach;
                                endif;
                                ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <input type="text" class="form-control" name="ticketSubject" placeholder="Subjek / Judul" aria-describedby="Subjek / Judul" value="<?= $datas != false ? esc($datas['ticketSubject'] ?? '', 'attr') : '' ?>">
                        </div>

                        <div class="form-group">
                            <textarea class="form-control" name="ticketMessage" placeholder="Pesan" aria-describedby="Pesan" rows="4" style="resize: none"><?= $datas != false ? esc($datas['ticketMessage'] ?? '') : '' ?></textarea>
                        </div>

                        <div class="form-group">
                            <input type="file" class="form-control" name="ticketArchiveId" placeholder="Tiket Dokumen" aria-describedby="Tiket Dokumen">
                            <span class="form-text text-muted kt-font-info">* Dokumen Berupa File PDF <?= ($datas != false ? ($archive_url != false ? ' | <a href="' . esc($archive_url) . '" ><i class="la la-file"></i> Lampiran</a>' : '') : '') ?></span>
                        </div>
                    </div>

                    <div class="kt-portlet__foot">
                        <!--begin::Action-->
                        <div class="kt-login__actions">
                            <a href="#" class="kt-link kt-login__link-forgot">
                                &nbsp;
                            </a>
                            <button id="btn_save" class="btn btn-primary btn-elevate kt-login__btn-primary">Submit</button>
                        </div>

                        <!--end::Action-->
                    </div>

                    <!--end::Action-->
                </form>

                <!--end::Form-->
            </div>

            <!--end::Portlet-->
        </div>
    </div>
</div>
<!--End::Row-->