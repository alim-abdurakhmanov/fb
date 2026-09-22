<?php
/**
 * ЛК банка: карточка заявки.
 */
declare(strict_types=1);

$current_page = 'bank_applications';
require_once __DIR__ . '/config.php';
checkAuth();
$bankPortalUser = getCurrentUser();
if (($bankPortalUser['role'] ?? '') !== 'bank') {
    header('Location: dashboard.php');
    exit;
}

require_once __DIR__ . '/includes/bank_portal.php';

$caseId = (int) ($_GET['id'] ?? 0);
if ($caseId <= 0) {
    header('Location: bank_applications.php');
    exit;
}

$pdo = getPDO();
$bankPortalCode = finbank_user_bank_code($pdo, $bankPortalUser);
if ($bankPortalCode === null) {
    header('Location: bank_applications.php');
    exit;
}

$caseRow = finbank_bank_submitted_case($pdo, $caseId, $bankPortalCode);
if (!$caseRow) {
    header('Location: bank_applications.php');
    exit;
}

$applicationId = (int) $caseRow['application_id'];
$stmt = $pdo->prepare('SELECT * FROM applications WHERE id = ?');
$stmt->execute([$applicationId]);
$application = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$assignedManagerName = '—';
if (!empty($application['assigned_to'])) {
    $sm = $pdo->prepare('SELECT first_name, last_name FROM users WHERE id = ? AND role IN (' . finbuild_manager_roles_sql_in() . ')');
    $sm->execute([(int) $application['assigned_to']]);
    $mgr = $sm->fetch(PDO::FETCH_ASSOC);
    if ($mgr) {
        $t = trim(($mgr['first_name'] ?? '') . ' ' . ($mgr['last_name'] ?? ''));
        if ($t !== '') {
            $assignedManagerName = $t;
        }
    }
}

$fmtAmount = static function ($v): string {
    if ($v === null || $v === '' || (float) $v == 0.0) {
        return '—';
    }
    return number_format((float) $v, 2, ',', ' ') . ' ₽';
};
$fmtDate = static function ($v): string {
    if (empty($v)) {
        return '—';
    }
    return date('d.m.Y', strtotime((string) $v));
};
$yn = static function ($v): string {
    return !empty($v) ? 'Да' : 'Нет';
};

require_once __DIR__ . '/includes/application_structure.php';
$structureCanEdit = false;
$structureGrouped = finbuild_application_structure_fetch_grouped($pdo, (int) $applicationId, false);

require_once __DIR__ . '/header.php';
?>
<script src="assets/js/show_notification.js"></script>

<div class="page-header mb-4">
    <div class="row align-items-center">
        <div class="col">
            <nav class="mb-2" aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item"><a href="bank_applications.php">Заявки в банк</a></li>
                    <li class="breadcrumb-item active">Заявка #<?= (int) $applicationId ?></li>
                </ol>
            </nav>
            <h1 class="h3 mb-1">Заявка #<?= (int) $applicationId ?></h1>
            <p class="text-muted mb-0">
                <?= htmlspecialchars((string) ($application['company_name'] ?? '')) ?>
                <?php if (!empty($application['inn'])): ?>
                    <span class="dot-separator text-muted">·</span> ИНН <?= htmlspecialchars((string) $application['inn']) ?>
                <?php endif; ?>
            </p>
            <div class="mt-2">
                <span class="badge <?= htmlspecialchars(finbank_bank_display_status_badge_class((string) $caseRow['status'], $caseRow['product_status'] ?? null)) ?> fs-6" id="bankDetailStatusBadge"><?= htmlspecialchars(finbank_bank_display_status_label((string) $caseRow['status'], $caseRow['product_status'] ?? null)) ?></span>
            </div>
        </div>
        <div class="col-auto">
            <a href="bank_applications.php" class="btn btn-primary">
                <i class="bi bi-arrow-left me-2"></i>Назад к заявкам
            </a>
        </div>
    </div>
</div>

<?php
$termBgLine = $fmtDate($application['term_bg'] ?? null);
$termMonths = !empty($application['term']) ? (int) $application['term'] : 0;
if ($termMonths > 0) {
    $termBgLine = $termBgLine === '—' ? '— (' . $termMonths . ' мес.)' : $termBgLine . ' (' . $termMonths . ' мес.)';
}
$mgrBankCommentHtml = trim((string) ($caseRow['manager_comment'] ?? ''));
?>
<link rel="stylesheet" href="assets/css/application_tabs.css">
<link rel="stylesheet" href="assets/css/company_analytics.css?v=<?= (int) (@filemtime(__DIR__ . '/assets/css/company_analytics.css') ?: time()) ?>">
<link rel="stylesheet" href="assets/css/bank_methodology.css?v=<?= (int) (@filemtime(__DIR__ . '/assets/css/bank_methodology.css') ?: time()) ?>">

<div class="row">
    <div class="col-12">
<ul class="nav application-tabs mb-4" id="bankDetailTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="bank-main-tab" data-bs-toggle="tab" data-bs-target="#bankAppMain" type="button" role="tab" aria-controls="bankAppMain" aria-selected="true">
            <i class="bi bi-journal-text me-2"></i><span class="tab-label">Заявка</span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="bank-documents-tab" data-bs-toggle="tab" data-bs-target="#bankAppDocuments" type="button" role="tab" aria-controls="bankAppDocuments" aria-selected="false">
            <i class="bi bi-folder2-open me-2"></i><span class="tab-label">Документы</span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="bank-analytics-tab" data-bs-toggle="tab" data-bs-target="#bankAppAnalytics" type="button" role="tab" aria-controls="bankAppAnalytics" aria-selected="false">
            <i class="bi bi-graph-up me-2"></i><span class="tab-label">Аналитика</span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="bank-methodology-tab" data-bs-toggle="tab" data-bs-target="#bankAppMethodology" type="button" role="tab" aria-controls="bankAppMethodology" aria-selected="false">
            <i class="bi bi-clipboard2-check me-2"></i><span class="tab-label">Банковская методика</span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="bank-structure-tab" data-bs-toggle="tab" data-bs-target="#bankAppStructure" type="button" role="tab" aria-controls="bankAppStructure" aria-selected="false">
            <i class="bi bi-diagram-3 me-2"></i><span class="tab-label">Структура</span>
        </button>
    </li>
</ul>

<div class="tab-content" id="bankDetailTabsContent">
<div class="tab-pane fade show active" id="bankAppMain" role="tabpanel" aria-labelledby="bank-main-tab">
<div class="row g-4 align-items-start">
    <div class="col-lg-8">
        <div id="bankDetailManagerCommentWrap" class="card shadow-sm border-0 mb-4<?= $mgrBankCommentHtml === '' ? ' d-none' : '' ?>">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="bi bi-chat-left-text me-2"></i>Комментарий менеджера</h5>
            </div>
            <div class="card-body" id="bankDetailManagerComment"><?= $mgrBankCommentHtml !== '' ? nl2br(htmlspecialchars($mgrBankCommentHtml)) : '' ?></div>
        </div>

        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="bi bi-journal-text me-2"></i>Параметры заявки</h5>
            </div>
            <div class="card-body">
                <div class="row g-3 small">
                    <div class="col-md-6"><span class="text-muted">Вид ФЗ</span><br><strong id="bankDetailFz"><?= ($application['fz_type'] ?? '') !== '' ? htmlspecialchars((string) $application['fz_type']) : '—' ?></strong></div>
                    <div class="col-md-6"><span class="text-muted">Вид гарантии</span><br><strong id="bankDetailGuaranteeType"><?= ($application['guarantee_type'] ?? '') !== '' ? htmlspecialchars((string) $application['guarantee_type']) : '—' ?></strong></div>
                    <div class="col-md-6"><span class="text-muted">Срок гарантии до</span><br><strong id="bankDetailTermBg"><?= htmlspecialchars($termBgLine) ?></strong></div>
                    <div class="col-md-6"><span class="text-muted">Сумма БГ</span><br><strong id="bankDetailAmount"><?= $fmtAmount($application['amount'] ?? null) ?></strong></div>
                    <div class="col-md-6"><span class="text-muted">Номер закупки</span><br><strong id="bankDetailPurchaseNumber"><?= ($application['purchase_number'] ?? '') !== '' ? htmlspecialchars((string) $application['purchase_number']) : '—' ?></strong></div>
                    <div class="col-12"><span class="text-muted">Ссылка на закупку</span><br>
                        <span id="bankDetailPurchaseLinkWrap"><?php
                            $pl = trim((string) ($application['purchase_link'] ?? ''));
                        if ($pl !== '') {
                            echo '<a href="' . htmlspecialchars($pl) . '" target="_blank" rel="noopener" class="text-break">' . htmlspecialchars($pl) . '</a>';
                        } else {
                            echo '<span class="text-muted">—</span>';
                        }
                        ?></span></div>
                    <div class="col-12"><span class="text-muted">Предмет контракта</span><br><strong id="bankDetailContractSubject" class="text-break fw-normal"><?= ($application['contract_subject'] ?? '') !== '' ? htmlspecialchars((string) $application['contract_subject']) : '—' ?></strong></div>
                    <div class="col-md-6"><span class="text-muted">Цена контракта</span><br><strong id="bankDetailContractPrice"><?= $fmtAmount($application['contract_price'] ?? null) ?></strong></div>
                    <div class="col-md-6"><span class="text-muted">ИНН заказчика</span><br><strong id="bankDetailCustomerInn"><?= ($application['customer_inn'] ?? '') !== '' ? htmlspecialchars((string) $application['customer_inn']) : '—' ?></strong></div>
                    <div class="col-12"><span class="text-muted">Наименование заказчика</span><br><strong id="bankDetailCustomerName" class="text-break fw-normal"><?= ($application['customer_name'] ?? '') !== '' ? htmlspecialchars((string) $application['customer_name']) : '—' ?></strong></div>
                    <div class="col-md-6"><span class="text-muted">Продление</span><br><strong id="bankDetailExtension"><?= $yn($application['is_extension'] ?? 0) ?></strong></div>
                    <div class="col-md-6"><span class="text-muted">Переобеспечение</span><br><strong id="bankDetailReplacement"><?= $yn($application['is_replacement'] ?? 0) ?></strong></div>
                    <div class="col-12 pt-1 border-top">
                        <span class="text-muted">Срок предоставления гарантии</span><br>
                        <strong id="bankDetailGuaranteeProvisionDeadline" class="text-break fw-normal"><?php
                            $gpd = trim((string)($application['guarantee_provision_deadline'] ?? ''));
                            echo $gpd !== '' ? htmlspecialchars($gpd) : '—';
                        ?></strong>
                    </div>
                    <div class="col-12 pt-1 border-top">
                        <span class="text-muted">Обеспечение</span><br>
                        <?php
                        $showCollateral = static function(array $app, string $key, string $label): string {
                            $en = !empty($app[$key . '_enabled']);
                            $dt = trim((string)($app[$key . '_details'] ?? ''));
                            $out = '<div class="mb-1"><strong>' . htmlspecialchars($label) . ':</strong> ' . ($en ? 'Да' : 'Нет') . '</div>';
                            if ($en && $dt !== '') {
                                $out .= '<div class="text-muted small mb-2">' . nl2br(htmlspecialchars($dt)) . '</div>';
                            }
                            return $out;
                        };
                        echo $showCollateral($application, 'collateral_transport', 'Обеспечение транспортом');
                        echo $showCollateral($application, 'collateral_real_estate', 'Обеспечение недвижимостью');
                        echo $showCollateral($application, 'collateral_deposit_note', 'Обеспечение депозит/вексель');
                        echo $showCollateral($application, 'collateral_third_party_guarantee', 'Поручительство третьих юр. лиц');
                        ?>
                    </div>
                    <div class="col-12"><span class="text-muted">Ответственный менеджер</span><br><strong id="bankDetailAssignedManager"><?= htmlspecialchars($assignedManagerName) ?></strong></div>
                    <div class="col-12 pt-1 border-top"><span class="text-muted">Отправлено в банк</span><br><strong id="bankDetailSubmitted"><?= $caseRow['submitted_at'] ? date('d.m.Y H:i', strtotime((string) $caseRow['submitted_at'])) : '—' ?></strong></div>
                </div>
            </div>
        </div>

        <div class="accordion shadow-sm rounded overflow-hidden mb-4" id="bankDetailStatusLogAccordion">
            <div class="accordion-item border-0">
                <h2 class="accordion-header" id="bankDetailStatusLogHeading">
                    <button class="accordion-button collapsed bg-white py-3 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#bankDetailStatusLogCollapse" aria-expanded="false" aria-controls="bankDetailStatusLogCollapse">
                        <span class="mb-0 fw-semibold"><i class="bi bi-clock-history me-2"></i>История статусов</span>
                    </button>
                </h2>
                <div id="bankDetailStatusLogCollapse" class="accordion-collapse collapse" aria-labelledby="bankDetailStatusLogHeading" data-bs-parent="#bankDetailStatusLogAccordion">
                    <div class="accordion-body small pt-0 border-top bg-white" id="bankDetailStatusLog"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="bi bi-arrow-left-right me-2"></i>Статус</h5>
            </div>
            <div class="card-body">
                <form id="bankStatusForm" class="mb-0">
                    <div class="mb-3">
                        <label class="form-label small">Новый статус</label>
                        <select class="form-select" id="bankNewStatus" name="new_status"></select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Комментарий к смене статуса</label>
                        <textarea class="form-control" name="comment" id="bankStatusComment" rows="3" placeholder="Будет виден менеджеру платформы"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Документы к смене статуса</label>
                        <input type="file" class="form-control form-control-sm" id="bankStatusFiles" name="status_files[]" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.txt,.zip,.rar">
                        <div class="form-text small mb-0">По желанию. Макс. 20 МБ на файл.</div>
                        <div id="bankStatusSelectedFiles" class="mt-2 small text-muted"></div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100" id="bankStatusSubmit">Сохранить статус</button>
                    <p class="text-muted small mt-2 mb-0 d-none" id="bankStatusNoTransitions">Для текущего статуса нет доступных переходов.</p>
                </form>
            </div>
        </div>

        <div class="card shadow-sm border-0 mb-4 bank-case-chat-card">
            <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
                <h5 class="mb-0"><i class="bi bi-chat-dots me-2"></i>Чат с менеджером</h5>
            </div>
            <div class="card-body p-0">
                <div class="bank-chat-container">
                    <div class="bank-chat-messages p-3" id="bankDetailMessages"></div>
                    <div class="bank-chat-input border-top bg-light p-3">
                        <textarea class="form-control bank-chat-textarea mb-2" id="bankDetailMessageInput" rows="2" placeholder="Сообщение…"></textarea>
                        <div class="d-flex align-items-start gap-2 flex-wrap">
                            <div class="flex-grow-1 min-w-0">
                                <div class="bank-file-input-wrap position-relative d-inline-block">
                                    <button type="button" class="btn btn-outline-secondary btn-sm"><i class="bi bi-paperclip me-1"></i>Файлы</button>
                                    <input type="file" id="bankDetailChatFiles" name="chat_files[]" multiple class="position-absolute top-0 start-0 opacity-0 w-100 h-100" style="cursor:pointer">
                                </div>
                                <div id="bankDetailChatSelectedFiles" class="mt-1 small"></div>
                            </div>
                            <button type="button" class="btn btn-primary" id="bankDetailMessageSend"><i class="bi bi-send me-1"></i>Отправить</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</div><!-- #bankAppMain -->

<div class="tab-pane fade" id="bankAppDocuments" role="tabpanel" aria-labelledby="bank-documents-tab">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-4">
        <div>
            <h4 class="mb-1">Документы</h4>
            <p class="text-muted small mb-0">Пакет документов по заявке</p>
        </div>
        <a class="btn btn-outline-primary d-none"
           id="bankDownloadAllDocsBtn"
           href="api_download_application_documents.php?bank_case_id=<?= (int) $caseId ?>"
           title="Скачать все документы архивом">
            <i class="bi bi-file-zip me-2"></i>Скачать все архивом
        </a>
    </div>
    <div id="bankDetailPackage"></div>
</div>

<div class="tab-pane fade" id="bankAppAnalytics" role="tabpanel" aria-labelledby="bank-analytics-tab">
    <div class="row">
        <div class="col-12">
            <?php
            require __DIR__ . '/includes/partials/company_analytics_tab.php';
            ?>
        </div>
    </div>
</div>

<div class="tab-pane fade" id="bankAppMethodology" role="tabpanel" aria-labelledby="bank-methodology-tab">
    <?php require __DIR__ . '/includes/partials/bank_methodology_tab.php'; ?>
</div>

<div class="tab-pane fade" id="bankAppStructure" role="tabpanel" aria-labelledby="bank-structure-tab">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-4">
        <div>
            <h4 class="mb-1">Структура принципала</h4>
            <p class="text-muted small mb-0">Преимущества и стоп-факторы компании</p>
        </div>
    </div>
    <?php
    $structureApplicationId = (int) $applicationId;
    $structureCanEdit = true;
    $structureEditOwnOnly = true;
    $structureSectionCodes = [
        FINBUILD_STRUCTURE_SECTION_ADVANTAGES,
        FINBUILD_STRUCTURE_SECTION_STOP_FACTORS,
    ];
    $structureShowDeleted = false;
    $structureShowReadonlyHint = false;
    $structureShowItemMeta = true;
    $structureShowItemMetaBankOnly = true;
    $structureIncludeScript = true;
    $structureCurrentUserId = (int) ($_SESSION['user_id'] ?? 0);
    $structureGridCols = 2;
    require __DIR__ . '/includes/partials/application_structure_block.php';
    ?>
</div>
</div><!-- #bankDetailTabsContent -->
    </div>
</div>

<style>
.bank-case-chat-card .bank-chat-container {
    display: flex;
    flex-direction: column;
    min-height: 550px;
    max-height: 700px;
    height: 70vh;
}
.bank-case-chat-card .bank-chat-messages {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    background: #fff;
}
.bank-case-chat-card .bank-chat-input {
    flex-shrink: 0;
}
.bank-case-chat-card .bank-chat-textarea {
    resize: none;
    min-height: 44px;
    max-height: 120px;
}
.bank-case-chat-card .bank-msg {
    margin-bottom: 1rem;
    max-width: 92%;
}
.bank-case-chat-card .bank-msg.own {
    margin-left: auto;
}
.bank-case-chat-card .bank-msg-hdr {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.35rem;
    gap: 0.5rem;
    flex-wrap: wrap;
}
.bank-case-chat-card .bank-msg.own .bank-msg-hdr {
    justify-content: flex-end;
}
.bank-case-chat-card .bank-msg-sender {
    font-weight: 600;
    color: #2c3e50;
    font-size: 0.85rem;
}
.bank-case-chat-card .bank-msg-time {
    font-size: 0.75rem;
    color: #6c757d;
}
.bank-case-chat-card .bank-msg-body {
    background: #f8f9fa;
    padding: 0.65rem 0.85rem;
    border-radius: 12px;
    border: 1px solid #e9ecef;
    word-wrap: break-word;
    font-size: 0.9rem;
}
.bank-case-chat-card .bank-msg.own .bank-msg-body {
    background: #3498db;
    color: #fff;
    border-color: #3498db;
}
.bank-case-chat-card .bank-msg-files {
    margin-top: 0.5rem;
    padding-top: 0.5rem;
    border-top: 1px solid rgba(0,0,0,0.08);
}
.bank-case-chat-card .bank-msg.own .bank-msg-files {
    border-top-color: rgba(255,255,255,0.25);
}
.bank-case-chat-card .bank-file-item {
    display: flex;
    align-items: center;
    padding: 0.5rem 0.65rem;
    background: #fff;
    border-radius: 8px;
    margin-bottom: 0.35rem;
    text-decoration: none;
    color: #2c3e50;
    border: 1px solid #e9ecef;
    font-size: 0.85rem;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.bank-case-chat-card .bank-msg.own .bank-file-item {
    background: rgba(255,255,255,0.95);
}
.bank-case-chat-card .bank-file-item:hover {
    border-color: #3498db;
    box-shadow: 0 1px 4px rgba(52,152,219,0.2);
    color: #2c3e50;
}
.bank-case-chat-card .bank-file-item:last-child { margin-bottom: 0; }
.bank-case-chat-card .bank-file-item i { font-size: 1.35rem; margin-right: 0.5rem; flex-shrink: 0; }
.bank-case-chat-card .bank-file-meta { min-width: 0; }
.bank-case-chat-card .bank-file-name { font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.bank-case-chat-card .bank-file-size { font-size: 0.7rem; opacity: 0.75; }
.bank-case-chat-card .bank-msg-system {
    text-align: center;
    margin: 1rem 0;
    font-size: 0.85rem;
    color: #6c757d;
}
.bank-case-chat-card .selected-file-chip {
    display: inline-flex;
    align-items: center;
    background: #e9ecef;
    padding: 0.25rem 0.5rem;
    border-radius: 6px;
    margin: 0.15rem 0.35rem 0.15rem 0;
    font-size: 0.8rem;
    border: 1px solid #dee2e6;
}
.bank-case-chat-card .selected-file-chip button { border: 0; background: none; padding: 0 0 0 0.35rem; line-height: 1; opacity: 0.6; }
.bank-case-chat-card .selected-file-chip button:hover { opacity: 1; }
#bankDetailStatusLog .log-file-link { display: inline-block; margin-top: 0.25rem; margin-right: 0.35rem; }

.bank-doc-empty {
    padding: 2.5rem 1rem;
    border: 1px dashed #dee2e6;
    border-radius: 12px;
    background: #fafbfc;
}
.bank-doc-empty i {
    font-size: 2rem;
    color: #adb5bd;
    display: block;
    margin-bottom: 0.75rem;
}
.bank-doc-section__title {
    font-size: 0.9375rem;
    font-weight: 600;
    color: #0d6efd;
}
.bank-doc-section__title .badge {
    font-size: 0.7rem;
    vertical-align: middle;
}
.bank-doc-section__desc {
    line-height: 1.4;
}
.bank-doc-card {
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 0.65rem 0.75rem;
    background: #fff;
    height: 100%;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
    overflow: hidden;
}
.bank-doc-card:hover {
    border-color: #3498db;
    box-shadow: 0 2px 8px rgba(52, 152, 219, 0.12);
}
.bank-doc-card__row {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
    min-width: 0;
}
.bank-doc-card__icon {
    flex: 0 0 30px;
    font-size: 1.25rem;
    line-height: 1;
    margin-top: 0.1rem;
}
.bank-doc-card__info {
    flex: 1 1 auto;
    min-width: 0;
    overflow: hidden;
}
.bank-doc-card__name {
    font-size: 0.8125rem;
    font-weight: 600;
    color: #2c3e50;
    word-break: break-word;
    overflow-wrap: anywhere;
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 3;
    overflow: hidden;
    line-height: 1.35;
}
.bank-doc-card__meta {
    font-size: 0.75rem;
    margin-top: 0.1rem;
}
.bank-doc-card__actions {
    display: flex;
    gap: 0.25rem;
    flex-shrink: 0;
}
.bank-doc-card__actions .btn {
    padding: 0.2rem 0.45rem;
    line-height: 1.2;
}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="assets/js/company_analytics.js?v=<?= (int) (@filemtime(__DIR__ . '/assets/js/company_analytics.js') ?: time()) ?>"></script>
<script src="assets/js/bank_methodology.js?v=<?= (int) (@filemtime(__DIR__ . '/assets/js/bank_methodology.js') ?: time()) ?>"></script>
<script>
(function () {
    const caseId = <?= (int) $caseId ?>;
    const bankChatUserId = <?= (int) ($_SESSION['user_id'] ?? 0) ?>;
    const statusHistoryLabels = <?= json_encode([
        FINBANK_STATUS_DRAFT => finbank_case_status_label_history(FINBANK_STATUS_DRAFT),
        FINBANK_STATUS_SENT => finbank_case_status_label_history(FINBANK_STATUS_SENT),
        FINBANK_STATUS_IN_PROGRESS => finbank_case_status_label_history(FINBANK_STATUS_IN_PROGRESS),
        FINBANK_STATUS_REQUEST => finbank_case_status_label_history(FINBANK_STATUS_REQUEST),
        FINBANK_STATUS_APPROVED => finbank_case_status_label_history(FINBANK_STATUS_APPROVED),
        FINBANK_STATUS_BG_ISSUED => finbank_case_status_label_history(FINBANK_STATUS_BG_ISSUED),
        FINBANK_STATUS_REJECTED => finbank_case_status_label_history(FINBANK_STATUS_REJECTED),
    ], JSON_UNESCAPED_UNICODE) ?>;

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function toast(msg, type) {
        showNotification(msg, type === 'error' ? 'danger' : (type || 'success'));
    }

    function formatFileSize(bytes) {
        const n = Number(bytes);
        if (!n || n <= 0) return '';
        const k = 1024;
        const sizes = ['Б', 'КБ', 'МБ', 'ГБ'];
        const i = Math.floor(Math.log(n) / Math.log(k));
        return (Math.round((n / Math.pow(k, i)) * 100) / 100) + ' ' + sizes[i];
    }

    function bankFileIconClass(fileType) {
        const m = {
            pdf: 'bi-file-earmark-pdf text-danger',
            word: 'bi-file-earmark-word text-primary',
            excel: 'bi-file-earmark-spreadsheet text-success',
            image: 'bi-file-earmark-image text-warning',
            archive: 'bi-file-earmark-zip text-secondary',
            text: 'bi-file-earmark-text text-info',
            file: 'bi-file-earmark text-secondary'
        };
        return m[fileType] || m.file;
    }

    function finbankLogLabel(code) {
        if (code == null || code === '') return '—';
        return statusHistoryLabels[code] || code;
    }

    const BANK_DOC_MISC_SECTION = 'Прочие документы';
    const BANK_DOC_HIDDEN_DESCRIPTIONS = [
        'Формы 1 и 2 бухгалтерской отчетности за последний отчетный период',
        'Проект договора или скан подписанного контракта',
        'Реестр исполненных контрактов за последние 3 года',
        'Информация о текущем кредитном портфеле компании',
        'Дополнительные документы по усмотрению клиента'
    ];

    function bankDocSectionKey(title) {
        const t = String(title || '').trim().toLowerCase();
        if (t === 'другие документы' || t === 'прочие документы') {
            return BANK_DOC_MISC_SECTION;
        }
        return String(title || 'Документы').trim() || 'Документы';
    }

    function bankDocShouldShowDescription(title, description) {
        const d = String(description || '').trim();
        if (!d) {
            return false;
        }
        if (bankDocSectionKey(title) === BANK_DOC_MISC_SECTION) {
            return false;
        }
        if (BANK_DOC_HIDDEN_DESCRIPTIONS.indexOf(d) !== -1) {
            return false;
        }
        if (d === String(title || '').trim()) {
            return false;
        }
        return true;
    }

    function bankDocFileIcon(fileType, fileName) {
        if (fileType) {
            return bankFileIconClass(fileType);
        }
        const parts = String(fileName || '').split('.');
        const ext = parts.length > 1 ? parts.pop().toLowerCase() : '';
        const map = {
            pdf: 'pdf',
            doc: 'word',
            docx: 'word',
            xls: 'excel',
            xlsx: 'excel',
            jpg: 'image',
            jpeg: 'image',
            png: 'image',
            txt: 'text',
            zip: 'archive',
            rar: 'archive'
        };
        return bankFileIconClass(map[ext] || 'file');
    }

    function bankFileHref(file, download) {
        if (download && file.file_url_download) {
            return file.file_url_download;
        }
        return file.file_url || file.file_path || '#';
    }

    function renderBankDocFileCard(file) {
        const name = file.original_name || 'Файл';
        const path = bankFileHref(file, false);
        const downloadPath = bankFileHref(file, true);
        const ic = bankDocFileIcon(file.file_type, name);
        const size = formatFileSize(file.file_size);
        let html = '<div class="col-md-6 col-xl-4">';
        html += '<div class="bank-doc-card">';
        html += '<div class="bank-doc-card__row">';
        html += '<div class="bank-doc-card__icon"><i class="bi ' + ic + '"></i></div>';
        html += '<div class="bank-doc-card__info">';
        html += '<div class="bank-doc-card__name" title="' + esc(name) + '">' + esc(name) + '</div>';
        if (size) {
            html += '<div class="bank-doc-card__meta">' + esc(size) + '</div>';
        }
        html += '</div>';
        html += '<div class="bank-doc-card__actions">';
        html += '<a href="' + esc(downloadPath) + '" class="btn btn-outline-primary btn-sm" target="_blank" rel="noopener" download="' + esc(name) + '" title="Скачать"><i class="bi bi-download"></i></a>';
        html += '<a href="' + esc(path) + '" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener" title="Просмотр"><i class="bi bi-eye"></i></a>';
        html += '</div></div></div></div>';
        return html;
    }

    function collectItemFiles(it) {
        const files = [];
        if (it.type === 'application_document' && (it.file_path || it.file_url)) {
            files.push({
                file_path: it.file_path,
                file_url: it.file_url,
                file_url_download: it.file_url_download,
                original_name: it.original_name,
                file_size: it.file_size,
                file_type: it.file_type
            });
        }
        if (it.type === 'product_document' && it.files && it.files.length) {
            it.files.forEach(function (f) {
                if (f.file_path) {
                    files.push(f);
                }
            });
        }
        return files;
    }

    function buildBankDocSections(items, uploads) {
        const sectionOrder = [];
        const sectionMap = {};

        function getOrCreateSection(title, description) {
            const key = bankDocSectionKey(title);
            if (!sectionMap[key]) {
                sectionMap[key] = {
                    key: key,
                    title: key,
                    description: '',
                    files: []
                };
                sectionOrder.push(key);
            }
            const sec = sectionMap[key];
            if (bankDocShouldShowDescription(title, description) && !sec.description) {
                sec.description = String(description || '').trim();
            }
            return sec;
        }

        items.forEach(function (it) {
            const files = collectItemFiles(it);
            if (!files.length) {
                return;
            }
            const sec = getOrCreateSection(it.title, it.description);
            sec.files.push.apply(sec.files, files);
        });

        uploads.forEach(function (u) {
            if (!u.file_path) {
                return;
            }
            const sec = getOrCreateSection(u.title || 'Дополнительные', u.description);
            sec.files.push(u);
        });

        const miscIdx = sectionOrder.indexOf(BANK_DOC_MISC_SECTION);
        if (miscIdx >= 0 && miscIdx !== sectionOrder.length - 1) {
            sectionOrder.splice(miscIdx, 1);
            sectionOrder.push(BANK_DOC_MISC_SECTION);
        }

        return sectionOrder.map(function (key) {
            const sec = sectionMap[key];
            return {
                title: sec.title,
                description: sec.description,
                files: sec.files
            };
        });
    }

    function renderBankDocSection(title, description, files) {
        if (!files.length) {
            return '';
        }
        let html = '<div class="bank-doc-section mb-3">';
        html += '<div class="bank-doc-section__head mb-2">';
        html += '<h6 class="bank-doc-section__title">' + esc(title || 'Документы');
        html += ' <span class="badge bg-primary">' + files.length + '</span></h6>';
        if (description) {
            html += '<p class="bank-doc-section__desc mb-0 mt-1">' + esc(description).replace(/\n/g, '<br>') + '</p>';
        }
        html += '</div><div class="row g-2">';
        files.forEach(function (f) {
            html += renderBankDocFileCard(f);
        });
        html += '</div></div>';
        return html;
    }

    function renderPackage(pkg) {
        const el = document.getElementById('bankDetailPackage');
        if (!el) return;
        const items = (pkg && pkg.items) ? pkg.items : [];
        const uploads = (pkg && pkg.uploads) ? pkg.uploads : [];
        let html = '';

        buildBankDocSections(items, uploads).forEach(function (sec) {
            html += renderBankDocSection(sec.title, sec.description, sec.files);
        });

        if (!html) {
            html = '<div class="bank-doc-empty text-center text-muted">'
                + '<i class="bi bi-folder2-open"></i>'
                + '<div class="fw-medium">Нет документов в пакете</div>'
                + '<div class="small mt-1">Менеджер ещё не отправил файлы по этой заявке</div>'
                + '</div>';
        }
        el.innerHTML = html;

        const downloadBtn = document.getElementById('bankDownloadAllDocsBtn');
        if (downloadBtn) {
            downloadBtn.classList.toggle('d-none', !items.length && !uploads.length);
        }
    }

    function renderMessages(messages) {
        const el = document.getElementById('bankDetailMessages');
        if (!el) return;
        if (!messages || !messages.length) {
            el.innerHTML = '<div class="bank-msg-system"><span><i class="bi bi-chat-dots me-2"></i>Чат с менеджером начат. Напишите первое сообщение.</span></div>';
            el.scrollTop = 0;
            return;
        }
        let html = '';
        messages.forEach(function (m) {
            const uid = parseInt(String(m.user_id || '0'), 10);
            const own = uid === bankChatUserId;
            const who = ((m.first_name || '') + ' ' + (m.last_name || '')).trim();
            const bankBadgeLabel = ((m.user_company_name || '').trim()) || 'Банк';
            const roleBadge = m.role === 'bank'
                ? '<span class="badge bg-secondary ms-1">' + esc(bankBadgeLabel) + '</span>'
                : '<span class="badge bg-primary text-white ms-1">Менеджер</span>';
            const files = m.files && m.files.length ? m.files : [];
            html += '<div class="bank-msg' + (own ? ' own' : '') + '">';
            html += '<div class="bank-msg-hdr"><span class="bank-msg-sender">'
                + (m.identity_masked ? '' : esc(who || 'Пользователь'))
                + roleBadge + '</span>';
            html += '<span class="bank-msg-time">' + esc(m.created_at || '') + '</span></div>';
            if ((m.message || '').trim() !== '') {
                html += '<div class="bank-msg-body">' + esc(m.message || '').replace(/\n/g, '<br>') + '</div>';
            }
            if (files.length) {
                html += '<div class="bank-msg-files">';
                files.forEach(function (f) {
                    const ic = bankFileIconClass(f.file_type || 'file');
                    html += '<a class="bank-file-item" href="' + esc(bankFileHref(f, false)) + '" target="_blank" rel="noopener" download="' + esc(f.original_name || '') + '">';
                    html += '<i class="bi ' + ic + '"></i><div class="bank-file-meta"><div class="bank-file-name">' + esc(f.original_name || 'Файл') + '</div>';
                    html += '<div class="bank-file-size">' + esc(formatFileSize(f.file_size)) + '</div></div></a>';
                });
                html += '</div>';
            }
            html += '</div>';
        });
        el.innerHTML = html;
        el.scrollTop = el.scrollHeight;
    }

    function renderLog(rows) {
        const el = document.getElementById('bankDetailStatusLog');
        if (!el) return;
        if (!rows || !rows.length) {
            el.innerHTML = '<span class="text-muted">Нет записей</span>';
            return;
        }
        let html = '<ul class="list-unstyled mb-0">';
        rows.slice().reverse().forEach(function (r) {
            const who = ((r.first_name || '') + ' ' + (r.last_name || '')).trim();
            html += '<li class="mb-3 pb-3 border-bottom border-light"><div class="text-muted">' + esc(r.created_at || '') + (who ? ' · ' + esc(who) : '') + '</div>';
            html += '<div>' + esc(finbankLogLabel(r.old_status)) + ' → <strong>' + esc(finbankLogLabel(r.new_status)) + '</strong></div>';
            if (r.comment) {
                html += '<div class="mt-1">' + esc(r.comment).replace(/\n/g, '<br>') + '</div>';
            }
            const lf = r.files && r.files.length ? r.files : [];
            if (lf.length) {
                html += '<div class="mt-1">';
                lf.forEach(function (f) {
                    html += '<a class="log-file-link btn btn-sm btn-outline-secondary" href="' + esc(bankFileHref(f, false)) + '" target="_blank" rel="noopener"><i class="bi bi-paperclip me-1"></i>' + esc(f.original_name || 'Файл') + '</a>';
                });
                html += '</div>';
            }
            html += '</li>';
        });
        html += '</ul>';
        el.innerHTML = html;
    }

    function fillStatusSelect(allowed) {
        const sel = document.getElementById('bankNewStatus');
        const hint = document.getElementById('bankStatusNoTransitions');
        const form = document.getElementById('bankStatusForm');
        if (!sel) return;
        sel.innerHTML = '';
        if (!allowed || !allowed.length) {
            sel.disabled = true;
            if (hint) hint.classList.remove('d-none');
            if (form) {
                const btn = form.querySelector('button[type="submit"]');
                if (btn) btn.disabled = true;
            }
            return;
        }
        if (hint) hint.classList.add('d-none');
        sel.disabled = false;
        allowed.forEach(function (opt, idx) {
            const o = document.createElement('option');
            o.value = opt.code;
            o.textContent = opt.label;
            if (idx === 0) {
                o.selected = true;
            }
            sel.appendChild(o);
        });
        const btn = form ? form.querySelector('button[type="submit"]') : null;
        if (btn) btn.disabled = false;
    }

    async function load() {
        let data;
        try {
            const r = await fetch('api_bank_case.php?action=bank_get&id=' + encodeURIComponent(String(caseId)));
            const raw = await r.text();
            try {
                data = JSON.parse(raw);
            } catch (parseErr) {
                toast('Ошибка ответа сервера (не JSON). Откройте консоль разработчика.', 'error');
                console.error(raw.substring(0, 800));
                return;
            }
        } catch (e) {
            toast('Ошибка сети или загрузки', 'error');
            console.error(e);
            return;
        }
        if (!data.success) {
            toast(data.error || 'Ошибка загрузки', 'error');
            return;
        }
        const badge = document.getElementById('bankDetailStatusBadge');
        if (badge) {
            badge.textContent = data.status_label_bank || '';
            badge.className = 'badge fs-6 ' + (data.status_badge_class || 'bg-secondary');
        }
        if (data.application) {
            const a = data.application;
            const amt = document.getElementById('bankDetailAmount');
            if (amt) {
                if (a.amount == null || a.amount === '' || Number(a.amount) === 0) {
                    amt.textContent = '—';
                } else {
                    amt.textContent = new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(a.amount)) + ' ₽';
                }
            }
            const setText = function (id, t) {
                const el = document.getElementById(id);
                if (el) {
                    el.textContent = t;
                }
            };
            const dash = function (s) {
                const x = (s == null ? '' : String(s)).trim();
                return x === '' ? '—' : x;
            };
            const fmtDate = function (d) {
                if (!d) {
                    return '—';
                }
                const dt = new Date(d);
                if (isNaN(dt.getTime())) {
                    return '—';
                }
                const dd = String(dt.getDate()).padStart(2, '0');
                const mm = String(dt.getMonth() + 1).padStart(2, '0');
                const yy = dt.getFullYear();
                return dd + '.' + mm + '.' + yy;
            };
            const yn = function (v) {
                return (v === 1 || v === '1' || v === true) ? 'Да' : 'Нет';
            };
            const fmtContractPrice = function (v) {
                if (v == null || v === '' || Number(v) === 0) {
                    return '—';
                }
                return new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(v)) + ' ₽';
            };
            setText('bankDetailFz', dash(a.fz_type));
            setText('bankDetailGuaranteeType', dash(a.guarantee_type));
            let termBgCombined = fmtDate(a.term_bg);
            const termM = a.term != null && String(a.term) !== '' ? parseInt(String(a.term), 10) : 0;
            if (termM > 0) {
                termBgCombined = termBgCombined === '—' ? '— (' + termM + ' мес.)' : termBgCombined + ' (' + termM + ' мес.)';
            }
            setText('bankDetailTermBg', termBgCombined);
            setText('bankDetailPurchaseNumber', dash(a.purchase_number));
            const plWrap = document.getElementById('bankDetailPurchaseLinkWrap');
            if (plWrap) {
                const pl = (a.purchase_link || '').trim();
                plWrap.innerHTML = pl
                    ? '<a href="' + esc(pl) + '" target="_blank" rel="noopener" class="text-break">' + esc(pl) + '</a>'
                    : '<span class="text-muted">—</span>';
            }
            const subj = document.getElementById('bankDetailContractSubject');
            if (subj) {
                subj.textContent = dash(a.contract_subject);
            }
            const cprice = document.getElementById('bankDetailContractPrice');
            if (cprice) {
                cprice.textContent = fmtContractPrice(a.contract_price);
            }
            setText('bankDetailCustomerInn', dash(a.customer_inn));
            const cname = document.getElementById('bankDetailCustomerName');
            if (cname) {
                cname.textContent = dash(a.customer_name);
            }
            setText('bankDetailExtension', yn(a.is_extension));
            setText('bankDetailReplacement', yn(a.is_replacement));
            const mgr = (a.assigned_manager_name || '').trim();
            setText('bankDetailAssignedManager', mgr || '—');
        }
        const mcWrap = document.getElementById('bankDetailManagerCommentWrap');
        const mc = document.getElementById('bankDetailManagerComment');
        if (mcWrap && mc && data.case) {
            const t = (data.case.manager_comment || '').trim();
            mcWrap.classList.toggle('d-none', !t);
            mc.innerHTML = t ? esc(t).replace(/\n/g, '<br>') : '';
        }
        const subEl = document.getElementById('bankDetailSubmitted');
        if (subEl && data.case) {
            const sa = data.case.submitted_at;
            if (sa) {
                const dt = new Date(String(sa).replace(' ', 'T'));
                if (!isNaN(dt.getTime())) {
                    const pad = function (n) { return String(n).padStart(2, '0'); };
                    subEl.textContent = pad(dt.getDate()) + '.' + pad(dt.getMonth() + 1) + '.' + dt.getFullYear()
                        + ' ' + pad(dt.getHours()) + ':' + pad(dt.getMinutes());
                } else {
                    subEl.textContent = '—';
                }
            } else {
                subEl.textContent = '—';
            }
        }
        renderPackage(data.package);
        renderMessages(data.messages);
        renderLog(data.status_log || []);
        fillStatusSelect(data.allowed_statuses || []);
    }

    function refreshBankStatusFileChips() {
        const inp = document.getElementById('bankStatusFiles');
        const wrap = document.getElementById('bankStatusSelectedFiles');
        if (!inp || !wrap) return;
        wrap.innerHTML = '';
        if (!inp.files || !inp.files.length) return;
        Array.from(inp.files).forEach(function (file) {
            const span = document.createElement('span');
            span.className = 'd-inline-block me-2 mb-1';
            span.textContent = file.name;
            wrap.appendChild(span);
        });
    }

    function refreshBankChatFileChips() {
        const inp = document.getElementById('bankDetailChatFiles');
        const wrap = document.getElementById('bankDetailChatSelectedFiles');
        if (!inp || !wrap) return;
        wrap.innerHTML = '';
        if (!inp.files || !inp.files.length) return;
        Array.from(inp.files).forEach(function (file, index) {
            const chip = document.createElement('span');
            chip.className = 'selected-file-chip';
            chip.innerHTML = '<i class="bi bi-file-earmark me-1"></i>' + esc(file.name)
                + '<button type="button" aria-label="Убрать"><i class="bi bi-x"></i></button>';
            chip.querySelector('button').addEventListener('click', function () {
                const dt = new DataTransfer();
                Array.from(inp.files).forEach(function (f, i) {
                    if (i !== index) dt.items.add(f);
                });
                inp.files = dt.files;
                refreshBankChatFileChips();
            });
            wrap.appendChild(chip);
        });
    }

    document.getElementById('bankStatusForm').addEventListener('submit', function (e) {
        e.preventDefault();
        const sel = document.getElementById('bankNewStatus');
        const comment = (document.getElementById('bankStatusComment').value || '').trim();
        if (!sel || sel.disabled) return;
        const fd = new FormData();
        fd.append('action', 'bank_set_status');
        fd.append('bank_case_id', String(caseId));
        fd.append('new_status', sel.value);
        fd.append('comment', comment);
        const sf = document.getElementById('bankStatusFiles');
        if (sf && sf.files && sf.files.length) {
            Array.from(sf.files).forEach(function (f) {
                fd.append('status_files[]', f);
            });
        }
        const btn = document.getElementById('bankStatusSubmit');
        if (btn) {
            btn.disabled = true;
        }
        fetch('api_bank_case.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    toast('Статус обновлён', 'success');
                    document.getElementById('bankStatusComment').value = '';
                    if (sf) {
                        sf.value = '';
                        refreshBankStatusFileChips();
                    }
                    load();
                } else {
                    toast(data.error || 'Ошибка', 'error');
                }
            })
            .catch(function () { toast('Ошибка сети', 'error'); })
            .finally(function () {
                if (btn) btn.disabled = false;
            });
    });

    document.getElementById('bankDetailMessageSend').addEventListener('click', function () {
        const ta = document.getElementById('bankDetailMessageInput');
        const fileInput = document.getElementById('bankDetailChatFiles');
        const text = (ta.value || '').trim();
        const hasFiles = fileInput && fileInput.files && fileInput.files.length > 0;
        if (!text && !hasFiles) {
            toast('Введите сообщение или прикрепите файл', 'error');
            return;
        }
        const fd = new FormData();
        fd.append('action', 'post_message');
        fd.append('bank_case_id', String(caseId));
        fd.append('message', text);
        if (hasFiles) {
            Array.from(fileInput.files).forEach(function (f) {
                fd.append('chat_files[]', f);
            });
        }
        const btn = document.getElementById('bankDetailMessageSend');
        btn.disabled = true;
        fetch('api_bank_case.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    ta.value = '';
                    if (fileInput) {
                        fileInput.value = '';
                        refreshBankChatFileChips();
                    }
                    load();
                } else {
                    toast(data.error || 'Ошибка', 'error');
                }
            })
            .catch(function () { toast('Ошибка сети', 'error'); })
            .finally(function () { btn.disabled = false; });
    });

    document.addEventListener('DOMContentLoaded', function () {
        var params = new URLSearchParams(window.location.search);
        var activeTab = params.get('tab');
        if (activeTab) {
            var tabTrigger = document.querySelector(
                '#bankDetailTabs [data-bs-target="#' + activeTab + '"], #bankDetailTabs [href="#' + activeTab + '"]'
            );
            if (tabTrigger && typeof bootstrap !== 'undefined' && bootstrap.Tab) {
                try {
                    new bootstrap.Tab(tabTrigger).show();
                } catch (e) {
                    console.warn('Tab init', e);
                }
            }
        }

        [].slice.call(document.querySelectorAll(
            '#bankDetailTabs button[data-bs-toggle="tab"], #bankDetailTabs a[data-bs-toggle="tab"]'
        )).forEach(function (triggerEl) {
            triggerEl.addEventListener('shown.bs.tab', function (event) {
                var el = event.currentTarget || event.target;
                var target = el.getAttribute('data-bs-target') || el.getAttribute('href');
                if (!target) {
                    return;
                }
                var tabName = target.replace('#', '');
                var url = new URL(window.location);
                url.searchParams.set('tab', tabName);
                window.history.replaceState({}, '', url);
            });
        });

        const cf = document.getElementById('bankDetailChatFiles');
        if (cf) {
            cf.addEventListener('change', refreshBankChatFileChips);
        }
        const sf = document.getElementById('bankStatusFiles');
        if (sf) {
            sf.addEventListener('change', refreshBankStatusFileChips);
        }
        load();
        if (typeof initCompanyAnalytics === 'function') {
            initCompanyAnalytics({
                applicationId: <?= (int) $applicationId ?>,
                readOnly: false,
                tabId: 'bank-analytics-tab',
                paneId: 'bankAppAnalytics'
            });
        }
    });
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
