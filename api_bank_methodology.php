<?php
/**
 * API: оценка по банковской методике.
 * ЛК банка — всегда; сотрудники — при праве methodology.view.
 * Отдельно от FinScore.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/bank_portal.php';
require_once __DIR__ . '/includes/bank_methodology/store.php';

header('Content-Type: application/json; charset=utf-8');

function bank_methodology_json(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Доступ к кейсу для методики: банк своего портала или сотрудник с methodology.view.
 *
 * @return array<string,mixed>|null
 */
function bank_methodology_assert_case_access(PDO $pdo, int $caseId, array $user): ?array
{
    if ($caseId <= 0) {
        return null;
    }

    $role = (string) ($user['role'] ?? '');
    $userId = (int) ($user['id'] ?? 0);

    if ($role === 'bank') {
        $bankCode = finbank_user_bank_code($pdo, $user);
        if ($bankCode === null) {
            return null;
        }
        return finbank_bank_submitted_case($pdo, $caseId, $bankCode);
    }

    if (!finbuild_can_view_methodology($user)) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT c.*, ap.application_id, ap.id AS application_product_id, ap.bank_name, ap.product_name, ap.product_type, ap.status AS product_status
         FROM application_product_bank_cases c
         INNER JOIN application_products ap ON ap.id = c.application_product_id
         WHERE c.id = ?
         LIMIT 1'
    );
    $stmt->execute([$caseId]);
    $case = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$case) {
        return null;
    }

    $applicationId = (int) ($case['application_id'] ?? 0);
    $isAnalystFlag = function_exists('finbuild_user_is_analyst_flag') && finbuild_user_is_analyst_flag($user);
    if ($applicationId <= 0 || !finbuild_can_access_application($pdo, $applicationId, $role, $userId, $isAnalystFlag)) {
        return null;
    }

    return $case;
}

if (!isset($_SESSION['user_id']) || !finbuild_sync_session_user()) {
    bank_methodology_json(['success' => false, 'error' => 'Не авторизован']);
}

$pdo = getPDO();
$userId = (int) $_SESSION['user_id'];
$role = (string) ($_SESSION['role'] ?? '');
$currentUser = getCurrentUser() ?: ['id' => $userId, 'role' => $role];
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
$caseId = (int) ($_POST['bank_case_id'] ?? $_GET['bank_case_id'] ?? 0);

if ($caseId <= 0) {
    bank_methodology_json(['success' => false, 'error' => 'Не указан кейс']);
}

$case = bank_methodology_assert_case_access($pdo, $caseId, $currentUser);
if (!$case) {
    bank_methodology_json(['success' => false, 'error' => 'Нет доступа к кейсу']);
}

$applicationId = (int) ($case['application_id'] ?? 0);
$appStmt = $pdo->prepare('SELECT id, company_name, inn, amount, contract_price FROM applications WHERE id = ?');
$appStmt->execute([$applicationId]);
$appRow = $appStmt->fetch(PDO::FETCH_ASSOC) ?: [];

try {
    if ($action === 'get') {
        $latest = bank_methodology_fetch_latest($pdo, $caseId);
        $final = bank_methodology_fetch_latest_final($pdo, $caseId);
        $history = bank_methodology_fetch_history($pdo, $caseId);
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
            ],
            'application' => [
                'id' => $applicationId,
                'company_name' => (string) ($appRow['company_name'] ?? ''),
                'inn' => (string) ($appRow['inn'] ?? ''),
                'amount' => $appRow['amount'] ?? null,
                'contract_price' => $appRow['contract_price'] ?? null,
            ],
            'bank_case' => [
                'id' => $caseId,
                'bank_name' => (string) ($case['bank_name'] ?? ''),
                'product_name' => (string) ($case['product_name'] ?? ''),
                'status' => (string) ($case['status'] ?? ''),
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
        $saved = bank_methodology_save_draft($pdo, $caseId, $userId, $state);
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
        $saved = bank_methodology_finalize($pdo, $caseId, $userId, $state);
        $evaluated = bank_methodology_evaluate($saved['state']);
        bank_methodology_json([
            'success' => true,
            'assessment' => $saved,
            'evaluated' => $evaluated,
            'message' => 'Оценка зафиксирована (версия ' . (int) $saved['version'] . ')',
        ]);
    }

    if ($action === 'new_draft' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Создать новый draft на базе последнего final/latest
        $latest = bank_methodology_fetch_latest($pdo, $caseId);
        $base = $latest['state'] ?? bank_methodology_empty_state();
        // Если latest draft — сначала «замораживаем» его как есть, затем новая версия через finalize-like clone
        if ($latest && $latest['status'] === 'draft') {
            // просто вернём текущий draft
            bank_methodology_json([
                'success' => true,
                'assessment' => $latest,
                'evaluated' => bank_methodology_evaluate($latest['state']),
                'message' => 'Уже есть черновик',
            ]);
        }
        $saved = bank_methodology_save_draft($pdo, $caseId, $userId, $base);
        bank_methodology_json([
            'success' => true,
            'assessment' => $saved,
            'evaluated' => bank_methodology_evaluate($saved['state']),
            'message' => 'Создан новый черновик на базе версии ' . (int) ($latest['version'] ?? 0),
        ]);
    }

    bank_methodology_json(['success' => false, 'error' => 'Неизвестное действие']);
} catch (Throwable $e) {
    bank_methodology_json(['success' => false, 'error' => $e->getMessage()]);
}
