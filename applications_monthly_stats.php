<?php
require_once 'config.php';

checkAuth();
$currentUser = getCurrentUser();

if (!finbuild_can('stats.monthly', $currentUser)) {
    header('Location: index.php');
    exit();
}

$pdo = getPDO();

$yearFilter = isset($_GET['year']) ? (int) $_GET['year'] : 0;

$yearsStmt = $pdo->query('SELECT DISTINCT YEAR(created_at) AS y FROM applications WHERE created_at IS NOT NULL ORDER BY y DESC');
$availableYears = array_map('intval', array_column($yearsStmt->fetchAll(PDO::FETCH_ASSOC), 'y'));

$sql = "
    SELECT
        DATE_FORMAT(created_at, '%Y-%m') AS month_key,
        COUNT(*) AS applications_count,
        COALESCE(SUM(amount), 0) AS total_amount
    FROM applications
    WHERE created_at IS NOT NULL
";
$params = [];
if ($yearFilter > 0) {
    $sql .= ' AND YEAR(created_at) = :year';
    $params[':year'] = $yearFilter;
}
$sql .= ' GROUP BY month_key ORDER BY month_key DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$monthlyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$grandCount = 0;
$grandAmount = 0.0;
foreach ($monthlyRows as $row) {
    $grandCount += (int) $row['applications_count'];
    $grandAmount += (float) $row['total_amount'];
}

function finbuild_monthly_stats_format_amount($amount): string
{
    if ($amount === null || $amount === '' || (float) $amount === 0.0) {
        return '—';
    }
    return number_format((float) $amount, 2, ',', ' ') . ' ₽';
}

function finbuild_monthly_stats_month_label(string $monthKey): string
{
    static $names = [
        1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель',
        5 => 'Май', 6 => 'Июнь', 7 => 'Июль', 8 => 'Август',
        9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
    ];
    if (!preg_match('/^(\d{4})-(\d{2})$/', $monthKey, $m)) {
        return $monthKey;
    }
    $month = (int) $m[2];
    return ($names[$month] ?? $monthKey) . ' ' . $m[1];
}

$current_page = 'applications_monthly_stats';
require_once 'header.php';
?>

<style>
.monthly-stats-card {
    border: none;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
}
.monthly-stats-card .table th {
    font-weight: 600;
    color: #64748b;
    border-bottom-width: 1px;
    white-space: nowrap;
}
.monthly-stats-card .table td {
    vertical-align: middle;
}
.monthly-stats-total-row td {
    font-weight: 700;
    background: #f8fafc;
    border-top: 2px solid #e2e8f0;
}
.monthly-stats-amount {
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}
</style>

<div class="page-header mb-4">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="h3 mb-1">Статистика заявок по месяцам</h1>
            <p class="text-muted mb-0">Количество и сумма заявок по дате создания</p>
        </div>
    </div>
</div>

<div class="card monthly-stats-card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end mb-4">
            <div class="col-md-4 col-lg-3">
                <label for="yearFilter" class="form-label">Год</label>
                <select class="form-select" id="yearFilter" name="year" onchange="this.form.submit()">
                    <option value="0" <?= $yearFilter === 0 ? 'selected' : '' ?>>Все годы</option>
                    <?php foreach ($availableYears as $year): ?>
                        <option value="<?= (int) $year ?>" <?= $yearFilter === (int) $year ? 'selected' : '' ?>><?= (int) $year ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($yearFilter > 0): ?>
            <div class="col-auto">
                <a href="applications_monthly_stats.php" class="btn btn-outline-secondary">Сбросить</a>
            </div>
            <?php endif; ?>
        </form>

        <?php if ($monthlyRows === []): ?>
            <p class="text-muted mb-0">Нет данных<?= $yearFilter > 0 ? ' за выбранный год' : '' ?>.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Месяц</th>
                            <th class="text-end">Количество заявок</th>
                            <th class="text-end">Сумма заявок</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($monthlyRows as $row): ?>
                            <?php
                            $count = (int) $row['applications_count'];
                            $amount = (float) $row['total_amount'];
                            ?>
                            <tr>
                                <td><?= htmlspecialchars(finbuild_monthly_stats_month_label((string) $row['month_key']), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="text-end monthly-stats-amount"><?= number_format($count, 0, ',', ' ') ?></td>
                                <td class="text-end monthly-stats-amount text-success"><?= finbuild_monthly_stats_format_amount($amount) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="monthly-stats-total-row">
                            <td>Итого<?= $yearFilter > 0 ? ' за ' . (int) $yearFilter . ' г.' : '' ?></td>
                            <td class="text-end monthly-stats-amount"><?= number_format($grandCount, 0, ',', ' ') ?></td>
                            <td class="text-end monthly-stats-amount text-success"><?= finbuild_monthly_stats_format_amount($grandAmount) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'footer.php'; ?>
