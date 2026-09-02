<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
checkAuth();

header('Content-Type: application/json; charset=utf-8');

$pdo = getPDO();
$userId = (int)($_SESSION['user_id'] ?? 0);

function saved_filters_fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function saved_filters_ok(array $payload = []): void
{
    echo json_encode(array_merge(['success' => true], $payload), JSON_UNESCAPED_UNICODE);
    exit;
}

$action = (string)($_POST['action'] ?? $_GET['action'] ?? 'get');
$type = (string)($_POST['type'] ?? $_GET['type'] ?? '');
if ($type === '') {
    saved_filters_fail(400, 'Не указан type');
}

// Read current saved_filters JSON blob
try {
    $stmt = $pdo->prepare('SELECT saved_filters FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $raw = $stmt->fetchColumn();
} catch (Throwable $e) {
    saved_filters_fail(500, 'Колонка saved_filters не найдена. Примените миграцию.');
}

$all = [];
if (is_string($raw) && trim($raw) !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $all = $decoded;
    }
}

if ($action === 'get') {
    $payload = $all[$type] ?? null;
    saved_filters_ok(['type' => $type, 'payload' => $payload]);
}

if ($action === 'clear') {
    unset($all[$type]);
    try {
        $stmt = $pdo->prepare('UPDATE users SET saved_filters = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([empty($all) ? null : json_encode($all, JSON_UNESCAPED_UNICODE), $userId]);
    } catch (Throwable $e) {
        saved_filters_fail(500, 'Не удалось очистить');
    }
    saved_filters_ok(['type' => $type, 'payload' => null]);
}

if ($action === 'save') {
    $payloadRaw = $_POST['payload'] ?? null;
    if ($payloadRaw === null) {
        saved_filters_fail(400, 'Не указан payload');
    }
    $payload = is_string($payloadRaw) ? json_decode($payloadRaw, true) : $payloadRaw;
    if (!is_array($payload)) {
        saved_filters_fail(400, 'payload должен быть JSON-объектом');
    }

    // Basic allowlist for applications filters
    if ($type === 'applications') {
        $allowed = ['status', 'assigned_to', 'search', 'per_page'];
        $filtered = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $payload)) {
                $filtered[$k] = $payload[$k];
            }
        }
        $payload = $filtered;
    }

    $all[$type] = $payload;
    try {
        $stmt = $pdo->prepare('UPDATE users SET saved_filters = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([json_encode($all, JSON_UNESCAPED_UNICODE), $userId]);
    } catch (Throwable $e) {
        saved_filters_fail(500, 'Не удалось сохранить');
    }
    saved_filters_ok(['type' => $type, 'payload' => $payload]);
}

saved_filters_fail(400, 'Неизвестное действие');

