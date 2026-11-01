<div class="col-lg-12">
    <div class="kt-portlet" data-ktportlet="true" id="kt_portlet_tools_1">
        <div class="kt-portlet__head">
            <div class="kt-portlet__head-label">
                <h3 class="kt-portlet__head-title">
                    Riwayat Disposisi
                </h3>
            </div>
            <div class="kt-portlet__head-toolbar">
                <div class="kt-portlet__head-group">
                    <a href="#" data-ktportlet-tool="toggle" class="btn btn-sm btn-icon btn-clean btn-icon-md"><i class="la la-angle-down"></i></a>
                </div>
            </div>
        </div>
        <div class="kt-portlet__body">
            <div class="kt-notes">
                <div class="kt-notes__items">
                    <?php if ($datas != false) : ?>
                        <div class="kt-notes__item kt-notes__item--clean">
                            <div class="kt-notes__media">
                                <span class="kt-notes__circle"></span>
                            </div>
                            <div class="kt-notes__content">
                                <div class="kt-notes__section">
                                    <div class="kt-notes__info">
                                        <span class="kt-notes__desc">
                                            <b><?= datetoindo($datas['ticketCreated']) . ' ' . date('h:i A', strtotime($datas['ticketCreated'])) ?></b>
                                        </span>
                                    </div>
                                </div>
                                <span class="kt-notes__body">
                                    <?= esc($datas['ticketIdentitas'] ?? '') . ' - ' . esc($datas['ticketName'] ?? '') . ' (' . esc($datas['ticketNoHp'] ?? '') . ' / ' . esc($datas['ticketEmail'] ?? '') . ')' ?> <br />
                                    <?= esc($datas['sCatNama'] ?? '') . ' - ' . esc($datas['categoryNama'] ?? '') ?> <br />
                                    <strong><?= esc($datas['ticketSubject'] ?? '') . ' - ' . esc($datas['ticketMessage'] ?? '') ?></strong> <br />
                                    <?= ($archive_first_url != false) ? '<a href="' . esc($archive_first_url) . '" >Lampiran</a>' : '' ?> <br />
                                </span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($disposisi != false) : ?>
                        <?php foreach ($disposisi as $row) : ?>
                            <div class="kt-notes__item kt-notes__item--clean">
                                <div class="kt-notes__media">
                                    <span class="kt-notes__circle"></span>
                                </div>
                                <div class="kt-notes__content">
                                    <div class="kt-notes__section">
                                        <div class="kt-notes__info">
                                            <span class="kt-notes__desc">
                                                <b><?= datetoindo($row['disposisiTanggal']) . ' ' . date('h:i A', strtotime($row['disposisiTanggal'])) ?></b>
                                            </span>
                                        </div>
                                    </div>
                                    <span class="kt-notes__body">
                                        <?= 'Disposisi oleh ' . esc($row['disposisiUser'] ?? '') . ' - ' . esc($row['disposisiUserProfil'] ?? '') . ' (' . esc($row['dunitNama'] ?? '') . ')' ?> <br />
                                        <strong>Pesan Disposisi: <?= esc($row['disposisiMessage'] ?? '') ?></strong> <br />
                                        <?= 'Status: ' . esc($row['statusNama'] ?? '') . ' - ' . esc($row['priorityName'] ?? '') ?> <br />
                                        <?= !empty($row['archiveId']) ? '<a href="' . esc($loadpdf . service('enkripsi')->encode((string) $row['archiveId'])) . '" >Lampiran</a>' : '' ?> <br />
                                    </span>
                                </div>
                            </div>
                    <?php endforeach;
                    endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>