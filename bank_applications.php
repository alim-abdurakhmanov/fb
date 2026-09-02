<?php
/**
 * ЛК банка: список заявок, отправленных в банк.
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

$pdo = getPDO();
$bankPortalCode = finbank_user_bank_code($pdo, $bankPortalUser);
if ($bankPortalCode === null) {
    http_response_code(403);
    echo 'Личный кабинет банка не настроен для этого пользователя.';
    exit;
}

$perPageOptions = [10, 20, 50, 100];
$perPage = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 20;
if (!in_array($perPage, $perPageOptions, true)) {
    $perPage = 20;
}
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;

$baseWhere = 'c.bank_code = ? AND c.status <> ?';
$baseParams = [$bankPortalCode, FINBANK_STATUS_DRAFT];

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM application_product_bank_cases c
     INNER JOIN application_products ap ON ap.id = c.application_product_id
     INNER JOIN applications a ON a.id = ap.application_id
     WHERE {$baseWhere}"
);
$countStmt->execute($baseParams);
$totalCount = (int) $countStmt->fetchColumn();

$totalPages = max(1, (int) ceil($totalCount / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$limitInt = $perPage;
$offsetInt = max(0, $offset);

$dataStmt = $pdo->prepare(
    "SELECT c.id AS case_id, c.status, c.submitted_at, c.manager_comment,
            a.id AS application_id, a.company_name, a.inn, a.amount, a.term, a.status AS app_status,
            ap.status AS product_status
     FROM application_product_bank_cases c
     INNER JOIN application_products ap ON ap.id = c.application_product_id
     INNER JOIN applications a ON a.id = ap.application_id
     WHERE {$baseWhere}
     ORDER BY c.submitted_at DESC, c.id DESC
     LIMIT {$limitInt} OFFSET {$offsetInt}"
);
$dataStmt->execute($baseParams);
$cases = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

$startItem = $totalCount > 0 ? $offset + 1 : 0;
$endItem = $totalCount > 0 ? min($offset + count($cases), $totalCount) : 0;

function bank_applications_format_amount($amount): string
{
    if ($amount === null || $amount === '') {
        return '—';
    }
    return number_format((float) $amount, 2, ',', ' ') . ' ₽';
}

require_once __DIR__ . '/header.php';
?>

<style>
.bank-applications-table {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 20px rgba(0, 0, 0, 0.08);
    overflow: hidden;
    font-size: 0.875rem;
}
.bank-applications-table .table th {
    background: #f8f9fa;
    border-bottom: 2px solid #e9ecef;
    font-weight: 600;
    color: #495057;
    padding: 0.75rem;
    font-size: 0.875rem;
}
.bank-applications-table .table td {
    padding: 1.25rem 0.75rem;
    vertical-align: middle;
    border-bottom: 1px solid #e9ecef;
    font-size: 0.875rem;
}
.bank-applications-table .table tbody tr:hover {
    background-color: #f8f9fa;
}
.bank-applications-table .amount-cell {
    font-weight: 600;
    color: #27ae60;
    white-space: nowrap;
}
.pagination-controls {
    padding: 0.75rem 1rem 1rem;
    background: #fff;
    border-top: 1px solid #e9ecef;
}
.pagination-controls .form-select {
    width: auto;
    min-width: 4.5rem;
}
.pagination .page-link {
    color: #3498db;
    border-color: #dee2e6;
}
.pagination .page-item.active .page-link {
    background: linear-gradient(135deg, #3498db, #2980b9);
    border-color: #2980b9;
    color: #fff;
}
.bank-applications-table .badge {
    font-weight: 500;
    font-size: 0.75rem;
    padding: 0.35rem 0.65rem;
}
@media (max-width: 768px) {
    .bank-applications-table .table td,
    .bank-applications-table .table th {
        padding: 0.65rem 0.5rem;
        font-size: 0.82rem;
    }
}
</style>

<div class="page-header mb-4">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="h3 mb-0">Заявки в банк</h1>
        </div>
    </div>
</div>

<?php if (empty($cases)): ?>
    <div class="text-center py-5 rounded-3 bg-white shadow-sm border-0" style="box-shadow: 0 2px 20px rgba(0,0,0,0.08);">
        <div class="mb-3"><i class="bi bi-inbox text-muted opacity-50 display-4"></i></div>
        <h5 class="text-muted fw-normal">Пока нет отправленных заявок</h5>
    </div>
<?php else: ?>
    <div class="bank-applications-table">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th class="ps-3">ID</th>
                        <th>Компания</th>
                        <th>ИНН</th>
                        <th>Сумма</th>
                        <th>Статус в банке</th>
                        <th>Отправлено</th>
                        <th class="text-end pe-3">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cases as $row): ?>
                        <tr role="button" style="cursor: pointer;"
                            onclick="if (!event.target.closest('a,button')) window.location='bank_application_detail.php?id=<?= (int) $row['case_id'] ?>'">
                            <td class="ps-3"><strong>#<?= (int) $row['application_id'] ?></strong></td>
                            <td>
                                <div class="fw-medium text-dark"><?= htmlspecialchars((string) $row['company_name']) ?></div>
                            </td>
                            <td><small class="text-muted"><?= htmlspecialchars((string) $row['inn']) ?></small></td>
                            <td class="amount-cell"><?= bank_applications_format_amount($row['amount'] ?? null) ?></td>
                            <td><span class="badge <?= htmlspecialchars(finbank_bank_display_status_badge_class((string) $row['status'], $row['product_status'] ?? null)) ?>"><?= htmlspecialchars(finbank_bank_display_status_label((string) $row['status'], $row['product_status'] ?? null)) ?></span></td>
                            <td class="small text-muted"><?= $row['submitted_at'] ? date('d.m.Y H:i', strtotime((string) $row['submitted_at'])) : '—' ?></td>
                            <td class="text-end pe-3">
                                <a class="btn btn-sm btn-outline-primary" href="bank_application_detail.php?id=<?= (int) $row['case_id'] ?>" onclick="event.stopPropagation();">Открыть</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php
        $queryParams = $_GET;
        $queryParams['per_page'] = $perPage;
        $maxLinks = 5;
        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $startPage + $maxLinks - 1);
        $startPage = max(1, $endPage - $maxLinks + 1);
        ?>
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 pagination-controls">
            <form method="GET" class="d-flex align-items-center gap-2">
                <input type="hidden" name="page" value="1">
                <?php foreach ($_GET as $key => $value): ?>
                    <?php if (in_array($key, ['per_page', 'page'], true)) {
                        continue;
                    } ?>
                    <input type="hidden" name="<?= htmlspecialchars((string) $key) ?>" value="<?= htmlspecialchars((string) $value) ?>">
                <?php endforeach; ?>
                <label class="form-label mb-0 small text-muted">Показывать</label>
                <select name="per_page" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach ($perPageOptions as $option): ?>
                        <option value="<?= (int) $option ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= (int) $option ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <div class="text-muted small">
                Показано <?= (int) $startItem ?>–<?= (int) $endItem ?> из <?= (int) $totalCount ?>
            </div>
            <nav aria-label="Навигация по страницам">
                <ul class="pagination mb-0">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <?php $prevParams = $queryParams;
                        $prevParams['page'] = max(1, $page - 1); ?>
                        <a class="page-link" href="bank_applications.php?<?= htmlspecialchars(http_build_query($prevParams)) ?>">&laquo;</a>
                    </li>
                    <?php if ($startPage > 1): ?>
                        <?php $firstParams = $queryParams;
                        $firstParams['page'] = 1; ?>
                        <li class="page-item"><a class="page-link" href="bank_applications.php?<?= htmlspecialchars(http_build_query($firstParams)) ?>">1</a></li>
                        <?php if ($startPage > 2): ?>
                            <li class="page-item disabled"><span class="page-link">…</span></li>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <?php $pageParams = $queryParams;
                        $pageParams['page'] = $i; ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="bank_applications.php?<?= htmlspecialchars(http_build_query($pageParams)) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <?php if ($endPage < $totalPages): ?>
                        <?php if ($endPage < $totalPages - 1): ?>
                            <li class="page-item disabled"><span class="page-link">…</span></li>
                        <?php endif; ?>
                        <?php $lastParams = $queryParams;
                        $lastParams['page'] = $totalPages; ?>
                        <li class="page-item"><a class="page-link" href="bank_applications.php?<?= htmlspecialchars(http_build_query($lastParams)) ?>"><?= (int) $totalPages ?></a></li>
                    <?php endif; ?>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <?php $nextParams = $queryParams;
                        $nextParams['page'] = min($totalPages, $page + 1); ?>
                        <a class="page-link" href="bank_applications.php?<?= htmlspecialchars(http_build_query($nextParams)) ?>">&raquo;</a>
                    </li>
                </ul>
            </nav>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
