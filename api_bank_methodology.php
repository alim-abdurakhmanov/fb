<?php
/**
 * API: оценка по банковской методике.
 * ЛК банка — всегда; сотрудники — при праве methodology.view.
 * Оценка хранится на заявку (application_id), без привязки к отправке в банк.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/bank_portal.php';
require_once __DIR__ . '/includes/bank_methodology/store.php';
require_once __DIR__ . '/includes/bank_methodology/checko_autofill.php';

header('Content-Type: application/json; charset=utf-8');

function bank_methodology_json(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Доступ к заявке для методики: банк своего портала или сотрудник с methodology.view.
 *
 * @return array{application_id:int}|null
 */
function bank_methodology_assert_application_access(PDO $pdo, int $applicationId, array $user): ?array
{
    if ($applicationId <= 0) {
        return null;
    }

    $role = (string) ($user['role'] ?? '');
    $userId = (int) ($user['id'] ?? 0);

    $stmt = $pdo->prepare('SELECT id FROM applications WHERE id = ? LIMIT 1');
    $stmt->execute([$applicationId]);
    if (!(int) $stmt->fetchColumn()) {
        return null;
    }

    if ($role === 'bank') {
        $bankCode = finbank_user_bank_code($pdo, $user);
        if ($bankCode === null || !finbank_bank_can_view_application($pdo, $applicationId, $bankCode)) {
            return null;
        }
        return ['application_id' => $applicationId];
    }

    if (!finbuild_can_view_methodology($user)) {
        return null;
    }

    $isAnalystFlag = function_exists('finbuild_user_is_analyst_flag') && finbuild_user_is_analyst_flag($user);
    if (!finbuild_can_access_application($pdo, $applicationId, $role, $userId, $isAnalystFlag)) {
        return null;
    }

    return ['application_id' => $applicationId];
}

if (!isset($_SESSION['user_id']) || !finbuild_sync_session_user()) {
    bank_methodology_json(['success' => false, 'error' => 'Не авторизован']);
}

$pdo = getPDO();
$userId = (int) $_SESSION['user_id'];
$role = (string) ($_SESSION['role'] ?? '');
$currentUser = getCurrentUser() ?: ['id' => $userId, 'role' => $role];
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
$applicationId = (int) ($_POST['application_id'] ?? $_GET['application_id'] ?? 0);

// Совместимость: ЛК банка мог передавать bank_case_id — берём application_id из кейса
if ($applicationId <= 0) {
    $legacyCaseId = (int) ($_POST['bank_case_id'] ?? $_GET['bank_case_id'] ?? 0);
    if ($legacyCaseId > 0) {
        $stmt = $pdo->prepare(
            'SELECT ap.application_id
             FROM application_product_bank_cases c
             INNER JOIN application_products ap ON ap.id = c.application_product_id
             WHERE c.id = ?
             LIMIT 1'
        );
        $stmt->execute([$legacyCaseId]);
        $applicationId = (int) $stmt->fetchColumn();
    }
}

if ($applicationId <= 0) {
    bank_methodology_json(['success' => false, 'error' => 'Не указана заявка']);
}

$access = bank_methodology_assert_application_access($pdo, $applicationId, $currentUser);
if (!$access) {
    bank_methodology_json(['success' => false, 'error' => 'Нет доступа к заявке']);
}

$appStmt = $pdo->prepare('SELECT id, company_name, inn, amount, contract_price FROM applications WHERE id = ?');
$appStmt->execute([$applicationId]);
$appRow = $appStmt->fetch(PDO::FETCH_ASSOC) ?: [];

try {
    if ($action === 'get') {
        $latest = bank_methodology_fetch_latest($pdo, $applicationId);
        $final = bank_methodology_fetch_latest_final($pdo, $applicationId);
        $history = bank_methodology_fetch_history($pdo, $applicationId);
        $state = $latest['state'] ?? bank_methodology_empty_state();
        $evaluated = bank_methodology_evaluate($state);
        bank_methodology_json([
            'success' => true,
            'assessment' => $latest,
            'final_assessment' => $final,
            'history' => $history,
            'evaluated' => $evaluated,
            'rules' => [
                'meta' => bank_methodology_rules()['meta'],
                'stop_factors' => bank_methodology_rules()['stop_factors'],
                'stop_groups' => bank_methodology_rules()['stop_groups'],
                'finance_groups' => bank_methodology_rules()['finance_groups'],
                'finance_metrics' => array_map(static function (array $m): array {
                    return [
                        'id' => $m['id'],
                        'group' => $m['group'],
                        'label' => $m['label'],
                        'max' => $m['max'],
                        'weight' => $m['weight'],
                        'unit' => $m['unit'] ?? '',
                    ];
                }, bank_methodology_rules()['finance_metrics']),
                'business_metrics' => bank_methodology_rules()['business_metrics'],
                'position_labels' => bank_methodology_rules()['position_labels'],
                'rating_scale' => array_map(static function (array $r): array {
                    return [
                        'rating' => $r['rating'],
                        'category' => $r['category'],
                        'position' => $r['position'],
                    ];
                }, bank_methodology_rules()['rating_scale']),
            ],
            'application' => [
                'id' => $applicationId,
                'company_name' => (string) ($appRow['company_name'] ?? ''),
                'inn' => (string) ($appRow['inn'] ?? ''),
                'amount' => $appRow['amount'] ?? null,
                'contract_price' => $appRow['contract_price'] ?? null,
            ],
        ]);
    }

    if ($action === 'recalculate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = (string) ($_POST['state'] ?? '');
        $state = json_decode($raw, true);
        if (!is_array($state)) {
            bank_methodology_json(['success' => false, 'error' => 'Некорректное состояние']);
        }
        $evaluated = bank_methodology_evaluate($state);
        bank_methodology_json(['success' => true, 'evaluated' => $evaluated]);
    }

    if ($action === 'save_draft' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = (string) ($_POST['state'] ?? '');
        $state = json_decode($raw, true);
        if (!is_array($state)) {
            bank_methodology_json(['success' => false, 'error' => 'Некорректное состояние']);
        }
        $saved = bank_methodology_save_draft($pdo, $applicationId, $userId, $state);
        $evaluated = bank_methodology_evaluate($saved['state']);
        bank_methodology_json([
            'success' => true,
            'assessment' => $saved,
            'evaluated' => $evaluated,
            'message' => 'Черновик сохранён',
        ]);
    }

    if ($action === 'finalize' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = (string) ($_POST['state'] ?? '');
        $state = null;
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                bank_methodology_json(['success' => false, 'error' => 'Некорректное состояние']);
            }
            $state = $decoded;
        }
        $saved = bank_methodology_finalize($pdo, $applicationId, $userId, $state);
        $evaluated = bank_methodology_evaluate($saved['state']);
        bank_methodology_json([
            'success' => true,
            'assessment' => $saved,
            'evaluated' => $evaluated,
            'message' => 'Оценка зафиксирована (версия ' . (int) $saved['version'] . ')',
        ]);
    }

    if ($action === 'new_draft' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $latest = bank_methodology_fetch_latest($pdo, $applicationId);
        $base = $latest['state'] ?? bank_methodology_empty_state();
        if ($latest && $latest['status'] === 'draft') {
            bank_methodology_json([
                'success' => true,
                'assessment' => $latest,
                'evaluated' => bank_methodology_evaluate($latest['state']),
                'message' => 'Уже есть черновик',
            ]);
        }
        $saved = bank_methodology_save_draft($pdo, $applicationId, $userId, $base);
        bank_methodology_json([
            'success' => true,
            'assessment' => $saved,
            'evaluated' => bank_methodology_evaluate($saved['state']),
            'message' => 'Создан новый черновик на базе версии ' . (int) ($latest['version'] ?? 0),
        ]);
    }

    if ($action === 'reset_draft' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $latest = bank_methodology_fetch_latest($pdo, $applicationId);
        if (!$latest || ($latest['status'] ?? '') !== 'draft') {
            bank_methodology_json([
                'success' => false,
                'error' => 'Нет сохранённого черновика для сброса',
            ]);
        }
        $empty = bank_methodology_empty_state();
        $saved = bank_methodology_save_draft($pdo, $applicationId, $userId, $empty);
        $evaluated = bank_methodology_evaluate($saved['state']);
        bank_methodology_json([
            'success' => true,
            'assessment' => $saved,
            'evaluated' => $evaluated,
            'message' => 'Черновик сброшен',
        ]);
    }

    if ($action === 'pull_checko' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $inn = preg_replace('/\D+/', '', (string) ($appRow['inn'] ?? '')) ?? '';
        if (strlen($inn) < 10) {
            bank_methodology_json(['success' => false, 'error' => 'У заявки нет корректного ИНН для Checko']);
        }

        $latest = bank_methodology_fetch_latest($pdo, $applicationId);
        if ($latest && ($latest['status'] ?? '') === 'final') {
            bank_methodology_json([
                'success' => false,
                'error' => 'Оценка зафиксирована. Создайте новый черновик, чтобы подтянуть Checko.',
            ]);
        }

        $raw = (string) ($_POST['state'] ?? '');
        $state = null;
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                bank_methodology_json(['success' => false, 'error' => 'Некорректное состояние']);
            }
            $state = $decoded;
        } else {
            $state = $latest['state'] ?? bank_methodology_empty_state();
        }

        $forceFinance = !empty($_POST['force_finance']);
        $proposal = bank_methodology_checko_propose($inn);
        if (empty($proposal['ok'])) {
            bank_methodology_json([
                'success' => false,
                'error' => (string) ($proposal['error'] ?? 'Не удалось получить данные Checko'),
            ]);
        }

        $merged = bank_methodology_checko_apply($state, $proposal, $forceFinance);
        $saved = bank_methodology_save_draft($pdo, $applicationId, $userId, $merged['state']);
        $evaluated = bank_methodology_evaluate($saved['state']);

        $on = $merged['applied']['stops_on'] ?? [];
        $fin = $merged['applied']['finance'] ?? [];
        $biz = $merged['applied']['business'] ?? [];
        $parts = [];
        if ($on !== []) {
            $parts[] = 'стопы: ' . implode(', ', $on);
        }
        if ($fin !== []) {
            $parts[] = 'финансы: ' . count($fin) . ' полей';
        }
        if ($biz !== []) {
            $parts[] = 'бизнес: ' . implode(', ', $biz);
        }
        $message = $parts !== []
            ? ('Checko: ' . implode('; ', $parts))
            : 'Checko: уверенных изменений нет (поля уже заполнены или стопы без сигналов)';

        bank_methodology_json([
            'success' => true,
            'assessment' => $saved,
            'evaluated' => $evaluated,
            'checko' => [
                'meta' => $proposal['meta'] ?? [],
                'warnings' => $proposal['warnings'] ?? [],
                'applied' => $merged['applied'],
                'proposal_stops' => $proposal['stops'] ?? [],
            ],
            'message' => $message,
        ]);
    }

    bank_methodology_json(['success' => false, 'error' => 'Неизвестное действие']);
} catch (Throwable $e) {
    bank_methodology_json(['success' => false, 'error' => $e->getMessage()]);
}
