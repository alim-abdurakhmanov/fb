<?php
/**
 * Упрощённая заявка на БГ от заказчика (бенефициара).
 */
declare(strict_types=1);

$current_page = 'applications';
require_once __DIR__ . '/config.php';
checkAuth();

$pdo = getPDO();
$currentUser = getCurrentUser();
$userRole = (string) ($currentUser['role'] ?? '');
$userId = (int) ($currentUser['id'] ?? 0);

if ($userRole !== 'beneficiary') {
    header('Location: create_application.php');
    exit;
}

$errors = [];
$successId = null;

$profileInn = preg_replace('/\D+/', '', (string) ($currentUser['inn'] ?? '')) ?? '';
$profileCompany = trim((string) ($currentUser['company_name'] ?? ''));
$contactName = trim(((string) ($currentUser['first_name'] ?? '')) . ' ' . ((string) ($currentUser['last_name'] ?? '')));
$contactPhone = trim((string) ($currentUser['phone'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amountMode = (string) ($_POST['amount_mode'] ?? 'fixed');
    if (!in_array($amountMode, ['fixed', 'open'], true)) {
        $amountMode = 'fixed';
    }
    $requestedRaw = trim((string) ($_POST['requested_amount'] ?? ''));
    $principalInn = preg_replace('/\D+/', '', (string) ($_POST['principal_inn'] ?? '')) ?? '';
    $principalCompany = trim((string) ($_POST['principal_company_name'] ?? ''));
    $guaranteeType = trim((string) ($_POST['guarantee_type'] ?? ''));
    $comment = trim((string) ($_POST['comment'] ?? ''));
    $termBg = trim((string) ($_POST['term_bg'] ?? ''));

    if ($profileInn === '' || !preg_match('/^\d{10,12}$/', $profileInn)) {
        $errors[] = 'В профиле не заполнен корректный ИНН заказчика. Обновите профиль.';
    }
    if ($profileCompany === '') {
        $errors[] = 'В профиле не указано название организации. Обновите профиль.';
    }

    $requestedAmount = null;
    if ($amountMode === 'fixed') {
        $normalized = str_replace([' ', ','], ['', '.'], $requestedRaw);
        if ($normalized === '' || !is_numeric($normalized) || (float) $normalized <= 0) {
            $errors[] = 'Укажите сумму БГ или выберите режим без конкретной суммы.';
        } else {
            $requestedAmount = (float) $normalized;
        }
    }

    if ($principalInn !== '' && !preg_match('/^\d{10,12}$/', $principalInn)) {
        $errors[] = 'ИНН принципала должен содержать 10–12 цифр.';
    }
    if ($principalInn !== '' && $principalCompany === '') {
        $errors[] = 'Укажите название компании принципала.';
    }
    if ($principalCompany !== '' && $principalInn === '') {
        $errors[] = 'Укажите ИНН принципала.';
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO applications (
                    company_name, inn, product_type, guarantee_type, amount, amount_mode, requested_amount,
                    term_bg, customer_inn, customer_name, principal_inn, principal_company_name,
                    contact_name, contact_phone, comment, status, intake_status, created_by, added_by, assigned_to
                 ) VALUES (
                    ?, ?, \'bg\', ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, \'new\', \'pending_review\', ?, ?, NULL
                 )'
            );
            // company_name/inn temporarily store beneficiary; after approve replaced with principal
            $stmt->execute([
                $profileCompany,
                $profileInn,
                $guaranteeType !== '' ? $guaranteeType : null,
                $requestedAmount,
                $amountMode,
                $requestedAmount,
                $termBg !== '' ? $termBg : null,
                $profileInn,
                $profileCompany,
                $principalInn !== '' ? $principalInn : null,
                $principalCompany !== '' ? $principalCompany : null,
                $contactName !== '' ? $contactName : null,
                $contactPhone !== '' ? $contactPhone : null,
                $comment !== '' ? $comment : null,
                $userId,
                $userId,
            ]);
            $successId = (int) $pdo->lastInsertId();
            header('Location: application_details.php?id=' . $successId);
            exit;
        } catch (Throwable $e) {
            $errors[] = 'Не удалось создать заявку: ' . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/header.php';
?>

<div class="page-header mb-4">
    <div class="row align-items-center">
        <div class="col">
            <h1 class="h3 mb-1">Заявка на банковскую гарантию</h1>
            <p class="text-muted mb-0">Запрос от заказчика (бенефициара). Документы на этом шаге не обязательны.</p>
        </div>
        <div class="col-auto">
            <a href="applications.php" class="btn btn-outline-secondary btn-sm">К списку</a>
        </div>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $err): ?>
                <li><?= htmlspecialchars($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <form method="POST" id="beneficiaryAppForm">
            <h2 class="h5 mb-3">Заказчик (бенефициар)</h2>
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label">ИНН</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($profileInn) ?>" readonly>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Организация</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($profileCompany) ?>" readonly>
                </div>
            </div>

            <h2 class="h5 mb-3">Сумма гарантии</h2>
            <div class="mb-3">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="radio" name="amount_mode" id="amountFixed" value="fixed"
                           <?= (($_POST['amount_mode'] ?? 'fixed') === 'fixed') ? 'checked' : '' ?>>
                    <label class="form-check-label" for="amountFixed">Конкретная сумма</label>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="radio" name="amount_mode" id="amountOpen" value="open"
                           <?= (($_POST['amount_mode'] ?? '') === 'open') ? 'checked' : '' ?>>
                    <label class="form-check-label" for="amountOpen">Без конкретной суммы — установить лимит</label>
                </div>
                <div id="requestedAmountWrap">
                    <label class="form-label" for="requested_amount">Сумма БГ, ₽</label>
                    <input type="text" class="form-control" name="requested_amount" id="requested_amount"
                           value="<?= htmlspecialchars((string) ($_POST['requested_amount'] ?? '')) ?>"
                           placeholder="Например, 5 000 000">
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label" for="guarantee_type">Тип гарантии</label>
                    <input type="text" class="form-control" name="guarantee_type" id="guarantee_type"
                           value="<?= htmlspecialchars((string) ($_POST['guarantee_type'] ?? '')) ?>"
                           placeholder="Например, исполнение контракта">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="term_bg">Срок БГ</label>
                    <input type="text" class="form-control" name="term_bg" id="term_bg"
                           value="<?= htmlspecialchars((string) ($_POST['term_bg'] ?? '')) ?>"
                           placeholder="Например, до 31.12.2026">
                </div>
            </div>

            <h2 class="h5 mb-3">Принципал (исполнитель) — опционально</h2>
            <p class="text-muted small">Можно указать сейчас или сотрудники дозаполнят при одобрении.</p>
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label" for="principal_inn">ИНН принципала</label>
                    <input type="text" class="form-control" name="principal_inn" id="principal_inn" maxlength="12"
                           value="<?= htmlspecialchars((string) ($_POST['principal_inn'] ?? '')) ?>">
                </div>
                <div class="col-md-8">
                    <label class="form-label" for="principal_company_name">Компания принципала</label>
                    <input type="text" class="form-control" name="principal_company_name" id="principal_company_name"
                           value="<?= htmlspecialchars((string) ($_POST['principal_company_name'] ?? '')) ?>">
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label" for="comment">Комментарий</label>
                <textarea class="form-control" name="comment" id="comment" rows="3"><?= htmlspecialchars((string) ($_POST['comment'] ?? '')) ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="bi bi-send me-1"></i>Отправить на рассмотрение
            </button>
        </form>
    </div>
</div>

<script>
(function () {
    const fixed = document.getElementById('amountFixed');
    const open = document.getElementById('amountOpen');
    const wrap = document.getElementById('requestedAmountWrap');
    const input = document.getElementById('requested_amount');
    function sync() {
        const isFixed = fixed && fixed.checked;
        if (wrap) wrap.style.display = isFixed ? '' : 'none';
        if (input) input.required = !!isFixed;
    }
    if (fixed) fixed.addEventListener('change', sync);
    if (open) open.addEventListener('change', sync);
    sync();
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
