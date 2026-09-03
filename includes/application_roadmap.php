<?php
/**
 * Дорожная карта заявки — для director и manager (не case_manager).
 */
declare(strict_types=1);

require_once __DIR__ . '/application_structure.php';

const FINBUILD_ROADMAP_GROUP_CHANNELS = 'channels_banks';
const FINBUILD_ROADMAP_GROUP_TASKS = 'tasks';

/** @var array<string, string> */
const FINBUILD_ROADMAP_SYSTEM_GROUP_TITLES = [
    FINBUILD_ROADMAP_GROUP_CHANNELS => 'Отправка в каналы и банки',
    FINBUILD_ROADMAP_GROUP_TASKS => 'Задачи',
];

/** @var list<string> */
const FINBUILD_ROADMAP_SYSTEM_GROUP_ORDER = [
    FINBUILD_ROADMAP_GROUP_CHANNELS,
    FINBUILD_ROADMAP_GROUP_TASKS,
];

function finbuild_roadmap_send_json(array $data): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function finbuild_roadmap_tables_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $pdo->query('SELECT 1 FROM application_roadmap_groups LIMIT 1');
        $ready = true;
    } catch (PDOException) {
        $ready = false;
    }
    return $ready;
}

function finbuild_roadmap_group_key_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (!finbuild_roadmap_tables_ready($pdo)) {
        $ready = false;
        return false;
    }
    try {
        $pdo->query('SELECT group_key FROM application_roadmap_groups LIMIT 0');
        $ready = true;
    } catch (PDOException) {
        $ready = false;
    }
    return $ready;
}

function finbuild_roadmap_updated_by_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (!finbuild_roadmap_tables_ready($pdo)) {
        $ready = false;
        return false;
    }
    try {
        $pdo->query('SELECT updated_by FROM application_roadmap_items LIMIT 0');
        $ready = true;
    } catch (PDOException) {
        $ready = false;
    }
    return $ready;
}

function finbuild_roadmap_can_access(?array $user): bool
{
    return finbuild_can('roadmap.edit', $user);
}

function finbuild_roadmap_assert_application(PDO $pdo, int $applicationId): bool
{
    if ($applicationId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT id FROM applications WHERE id = ? LIMIT 1');
    $stmt->execute([$applicationId]);
    return (bool) $stmt->fetchColumn();
}

function finbuild_roadmap_is_system_group_key(?string $groupKey): bool
{
    return $groupKey !== null && $groupKey !== '' && isset(FINBUILD_ROADMAP_SYSTEM_GROUP_TITLES[$groupKey]);
}

function finbuild_roadmap_group_title(?string $groupKey, string $dbTitle): string
{
    if (finbuild_roadmap_is_system_group_key($groupKey)) {
        return FINBUILD_ROADMAP_SYSTEM_GROUP_TITLES[$groupKey];
    }
    return $dbTitle;
}

function finbuild_roadmap_item_was_edited(array $item): bool
{
    if (!empty($item['updated_by']) || !empty($item['updated_by_name'])) {
        return true;
    }
    return false;
}

/**
 * @return list<string>
 */
function finbuild_roadmap_item_meta_lines(array $item): array
{
    $lines = [];
    if ($item['is_done'] ?? false) {
        $done = [];
        if (!empty($item['done_by_name'])) {
            $done[] = (string) $item['done_by_name'];
        }
        if (!empty($item['done_at_label'])) {
            $done[] = (string) $item['done_at_label'];
        }
        if ($done !== []) {
            $lines[] = 'Выполнено: ' . implode(' · ', $done);
        }
    }
    if (finbuild_roadmap_item_was_edited($item)) {
        $updated = [];
        if (!empty($item['updated_by_name'])) {
            $updated[] = (string) $item['updated_by_name'];
        }
        if (!empty($item['updated_at_label'])) {
            $updated[] = (string) $item['updated_at_label'];
        }
        if ($updated !== []) {
            $lines[] = 'Изменено: ' . implode(' · ', $updated);
        }
    }
    return $lines;
}

function finbuild_roadmap_map_item(array $row): array
{
    $doneBy = trim(((string) ($row['done_first_name'] ?? '')) . ' ' . ((string) ($row['done_last_name'] ?? '')));
    $updatedBy = trim(((string) ($row['updated_first_name'] ?? '')) . ' ' . ((string) ($row['updated_last_name'] ?? '')));
    $item = [
        'id' => (int) ($row['id'] ?? 0),
        'group_id' => (int) ($row['group_id'] ?? 0),
        'title' => (string) ($row['title'] ?? ''),
        'is_done' => (int) ($row['is_done'] ?? 0) === 1,
        'done_at' => $row['done_at'] ?? null,
        'done_at_label' => finbuild_structure_format_datetime(isset($row['done_at']) ? (string) $row['done_at'] : null),
        'done_by_name' => $doneBy !== '' ? $doneBy : null,
        'sort_order' => (int) ($row['sort_order'] ?? 0),
        'updated_by' => isset($row['updated_by']) ? (int) $row['updated_by'] : null,
        'updated_by_name' => $updatedBy !== '' ? $updatedBy : null,
        'updated_at' => $row['updated_at'] ?? null,
        'updated_at_label' => finbuild_structure_format_datetime(isset($row['updated_at']) ? (string) $row['updated_at'] : null),
    ];
    $item['meta_lines'] = finbuild_roadmap_item_meta_lines($item);
    return $item;
}

function finbuild_roadmap_ensure_defaults(PDO $pdo, int $applicationId, int $userId): void
{
    if (!finbuild_roadmap_tables_ready($pdo)) {
        return;
    }

    $hasGroupKey = finbuild_roadmap_group_key_ready($pdo);

    $pdo->beginTransaction();
    try {
        $findByKey = $hasGroupKey
            ? $pdo->prepare('SELECT id, title FROM application_roadmap_groups WHERE application_id = ? AND group_key = ? LIMIT 1')
            : null;
        $findByTitle = $pdo->prepare(
            'SELECT id, title FROM application_roadmap_groups WHERE application_id = ? AND title = ? LIMIT 1'
        );
        $insGroup = $hasGroupKey
            ? $pdo->prepare(
                'INSERT INTO application_roadmap_groups (application_id, title, group_key, sort_order, created_by) VALUES (?, ?, ?, ?, ?)'
            )
            : $pdo->prepare(
                'INSERT INTO application_roadmap_groups (application_id, title, sort_order, created_by) VALUES (?, ?, ?, ?)'
            );
        $updGroupKey = $hasGroupKey
            ? $pdo->prepare('UPDATE application_roadmap_groups SET group_key = ?, title = ? WHERE id = ? AND application_id = ?')
            : null;
        $updGroupTitle = $pdo->prepare('UPDATE application_roadmap_groups SET title = ? WHERE id = ? AND application_id = ?');
        $countItems = $pdo->prepare('SELECT COUNT(*) FROM application_roadmap_items WHERE group_id = ? AND application_id = ?');
        $insItem = $pdo->prepare(
            'INSERT INTO application_roadmap_items (group_id, application_id, title, sort_order, created_by) VALUES (?, ?, ?, ?, ?)'
        );

        foreach (FINBUILD_ROADMAP_SYSTEM_GROUP_ORDER as $sortOrder => $groupKey) {
            $canonicalTitle = FINBUILD_ROADMAP_SYSTEM_GROUP_TITLES[$groupKey];
            $groupId = 0;

            if ($hasGroupKey && $findByKey) {
                $findByKey->execute([$applicationId, $groupKey]);
                $row = $findByKey->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $groupId = (int) $row['id'];
                    if (($row['title'] ?? '') !== $canonicalTitle) {
                        $updGroupTitle->execute([$canonicalTitle, $groupId, $applicationId]);
                    }
                }
            }

            if ($groupId <= 0) {
                $legacyTitles = $groupKey === FINBUILD_ROADMAP_GROUP_CHANNELS
                    ? ['Отправка в каналы и банки', 'Отправка в банки']
                    : [$canonicalTitle];
                foreach ($legacyTitles as $legacyTitle) {
                    $findByTitle->execute([$applicationId, $legacyTitle]);
                    $row = $findByTitle->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $groupId = (int) $row['id'];
                        if ($hasGroupKey && $updGroupKey) {
                            $updGroupKey->execute([$groupKey, $canonicalTitle, $groupId, $applicationId]);
                        } elseif (($row['title'] ?? '') !== $canonicalTitle) {
                            $updGroupTitle->execute([$canonicalTitle, $groupId, $applicationId]);
                        }
                        break;
                    }
                }
            }

            if ($groupId <= 0) {
                if ($hasGroupKey) {
                    $insGroup->execute([$applicationId, $canonicalTitle, $groupKey, $sortOrder, $userId]);
                } else {
                    $insGroup->execute([$applicationId, $canonicalTitle, $sortOrder, $userId]);
                }
                $groupId = (int) $pdo->lastInsertId();
            }

            if ($groupKey !== FINBUILD_ROADMAP_GROUP_TASKS) {
                continue;
            }

            $countItems->execute([$groupId, $applicationId]);
            if ((int) $countItems->fetchColumn() > 0) {
                continue;
            }

            $insItem->execute([$groupId, $applicationId, 'Собрать пакет документов', 0, $userId]);
            $insItem->execute([$groupId, $applicationId, 'Согласовать условия с клиентом', 1, $userId]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * @return array{groups: list<array<string, mixed>>, stats: array{total: int, done: int}}
 */
function finbuild_roadmap_fetch(PDO $pdo, int $applicationId): array
{
    if (!finbuild_roadmap_tables_ready($pdo)) {
        return ['groups' => [], 'stats' => ['total' => 0, 'done' => 0]];
    }

    $hasGroupKey = finbuild_roadmap_group_key_ready($pdo);
    $trackUpdatedBy = finbuild_roadmap_updated_by_ready($pdo);

    $groupCols = $hasGroupKey ? 'id, title, group_key, sort_order' : 'id, title, sort_order';
    $stmt = $pdo->prepare(
        "SELECT {$groupCols} FROM application_roadmap_groups
         WHERE application_id = ? ORDER BY sort_order ASC, id ASC"
    );
    $stmt->execute([$applicationId]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $itemJoin = $trackUpdatedBy
        ? 'LEFT JOIN users uu ON uu.id = i.updated_by'
        : '';
    $itemCols = $trackUpdatedBy
        ? ', uu.first_name AS updated_first_name, uu.last_name AS updated_last_name'
        : '';
    $itemStmt = $pdo->prepare(
        "SELECT i.*, u.first_name AS done_first_name, u.last_name AS done_last_name{$itemCols}
         FROM application_roadmap_items i
         LEFT JOIN users u ON u.id = i.done_by
         {$itemJoin}
         WHERE i.application_id = ? AND i.group_id = ?
         ORDER BY i.sort_order ASC, i.id ASC"
    );

    $total = 0;
    $done = 0;
    $result = [];

    foreach ($groups as $group) {
        $gid = (int) $group['id'];
        $groupKey = $hasGroupKey ? (string) ($group['group_key'] ?? '') : '';
        if ($groupKey === '') {
            $groupKey = null;
        }
        $itemStmt->execute([$applicationId, $gid]);
        $items = [];
        foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $mapped = finbuild_roadmap_map_item($row);
            $items[] = $mapped;
            $total++;
            if ($mapped['is_done']) {
                $done++;
            }
        }
        $groupDone = 0;
        foreach ($items as $it) {
            if ($it['is_done']) {
                $groupDone++;
            }
        }
        $isSystem = finbuild_roadmap_is_system_group_key($groupKey);
        $result[] = [
            'id' => $gid,
            'title' => finbuild_roadmap_group_title($groupKey, (string) $group['title']),
            'group_key' => $groupKey,
            'is_system' => $isSystem,
            'sort_order' => (int) $group['sort_order'],
            'items' => $items,
            'stats' => [
                'total' => count($items),
                'done' => $groupDone,
            ],
        ];
    }

    return [
        'groups' => $result,
        'stats' => ['total' => $total, 'done' => $done],
    ];
}

function finbuild_roadmap_next_group_sort(PDO $pdo, int $applicationId): int
{
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM application_roadmap_groups WHERE application_id = ?');
    $stmt->execute([$applicationId]);
    return (int) $stmt->fetchColumn();
}

function finbuild_roadmap_next_item_sort(PDO $pdo, int $groupId): int
{
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM application_roadmap_items WHERE group_id = ?');
    $stmt->execute([$groupId]);
    return (int) $stmt->fetchColumn();
}

function finbuild_roadmap_get_group(PDO $pdo, int $groupId, int $applicationId): ?array
{
    $hasGroupKey = finbuild_roadmap_group_key_ready($pdo);
    $cols = $hasGroupKey ? 'id, title, group_key, sort_order' : 'id, title, sort_order';
    $stmt = $pdo->prepare("SELECT {$cols} FROM application_roadmap_groups WHERE id = ? AND application_id = ? LIMIT 1");
    $stmt->execute([$groupId, $applicationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function finbuild_roadmap_group_belongs(PDO $pdo, int $groupId, int $applicationId): bool
{
    return finbuild_roadmap_get_group($pdo, $groupId, $applicationId) !== null;
}

function finbuild_roadmap_group_is_system(PDO $pdo, int $groupId, int $applicationId): bool
{
    $group = finbuild_roadmap_get_group($pdo, $groupId, $applicationId);
    if (!$group) {
        return false;
    }
    if (!finbuild_roadmap_group_key_ready($pdo)) {
        $title = (string) ($group['title'] ?? '');
        return in_array($title, ['Отправка в каналы и банки', 'Отправка в банки', 'Задачи'], true);
    }
    return finbuild_roadmap_is_system_group_key((string) ($group['group_key'] ?? '') ?: null);
}

function finbuild_roadmap_item_belongs(PDO $pdo, int $itemId, int $applicationId): ?array
{
    $trackUpdatedBy = finbuild_roadmap_updated_by_ready($pdo);
    $join = $trackUpdatedBy ? 'LEFT JOIN users uu ON uu.id = i.updated_by' : '';
    $cols = $trackUpdatedBy ? ', uu.first_name AS updated_first_name, uu.last_name AS updated_last_name' : '';
    $stmt = $pdo->prepare(
        "SELECT i.*, u.first_name AS done_first_name, u.last_name AS done_last_name{$cols}
         FROM application_roadmap_items i
         LEFT JOIN users u ON u.id = i.done_by
         {$join}
         WHERE i.id = ? AND i.application_id = ?
         LIMIT 1"
    );
    $stmt->execute([$itemId, $applicationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function finbuild_roadmap_add_template(PDO $pdo, int $applicationId, int $userId): void
{
    finbuild_roadmap_ensure_defaults($pdo, $applicationId, $userId);
}

function finbuild_roadmap_dispatch(
    PDO $pdo,
    ?array $user,
    int $userId,
    int $applicationId,
    string $action,
    string $method
): void {
    if (!finbuild_roadmap_can_access($user)) {
        finbuild_roadmap_send_json(['success' => false, 'error' => 'Доступ запрещён']);
    }
    if ($applicationId <= 0 || !finbuild_roadmap_assert_application($pdo, $applicationId)) {
        finbuild_roadmap_send_json(['success' => false, 'error' => 'Заявка не найдена']);
    }
    if (!finbuild_roadmap_tables_ready($pdo)) {
        finbuild_roadmap_send_json([
            'success' => false,
            'error' => 'Таблицы дорожной карты не созданы. Выполните миграцию add_application_roadmap.sql',
        ]);
    }

    try {
        if ($action === 'get' && $method === 'GET') {
            finbuild_roadmap_ensure_defaults($pdo, $applicationId, $userId);
            $payload = finbuild_roadmap_fetch($pdo, $applicationId);
            finbuild_roadmap_send_json(['success' => true] + $payload);
        }

        if ($method !== 'POST') {
            finbuild_roadmap_send_json(['success' => false, 'error' => 'Неверный метод']);
        }

        if ($action === 'add_group') {
            $title = trim((string) ($_POST['title'] ?? ''));
            if ($title === '') {
                finbuild_roadmap_send_json(['success' => false, 'error' => 'Укажите название раздела']);
            }
            if (mb_strlen($title) > 255) {
                finbuild_roadmap_send_json(['success' => false, 'error' => 'Слишком длинное название']);
            }
            $sort = finbuild_roadmap_next_group_sort($pdo, $applicationId);
            $pdo->prepare(
                'INSERT INTO application_roadmap_groups (application_id, title, sort_order, created_by) VALUES (?, ?, ?, ?)'
            )->execute([$applicationId, $title, $sort, $userId]);
            finbuild_roadmap_send_json([
                'success' => true,
                'group' => [
                    'id' => (int) $pdo->lastInsertId(),
                    'title' => $title,
                    'group_key' => null,
                    'is_system' => false,
                    'sort_order' => $sort,
                    'items' => [],
                    'stats' => ['total' => 0, 'done' => 0],
                ],
                'stats' => finbuild_roadmap_fetch($pdo, $applicationId)['stats'],
            ]);
        }

        if ($action === 'rename_group') {
            $groupId = (int) ($_POST['group_id'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));
            if ($groupId <= 0 || $title === '') {
                finbuild_roadmap_send_json(['success' => false, 'error' => 'Неверные параметры']);
            }
            if (!finbuild_roadmap_group_belongs($pdo, $groupId, $applicationId)) {
                finbuild_roadmap_send_json(['success' => false, 'error' => 'Раздел не найден']);
            }
            if (finbuild_roadmap_group_is_system($pdo, $groupId, $applicationId)) {
                finbuild_roadmap_send_json(['success' => false, 'error' => 'Нельзя переименовать системный раздел']);
            }
            $pdo->prepare('UPDATE application_roadmap_groups SET title = ? WHERE id = ? AND application_id = ?')
                ->execute([$title, $groupId, $applicationId]);
            finbuild_roadmap_send_json(['success' => true, 'group_id' => $groupId, 'title' => $title]);
        }

        if ($action === 'delete_group') {
            $groupId = (int) ($_POST['group_id'] ?? 0);
            if ($groupId <= 0 || !finbuild_roadmap_group_belongs($pdo, $groupId, $applicationId)) {
                finbuild_roadmap_send_json(['success' => false, 'error' => 'Раздел не найден']);
            }
            if (finbuild_roadmap_group_is_system($pdo, $groupId, $applicationId)) {
                finbuild_roadmap_send_json(['success' => false, 'error' => 'Нельзя удалить системный раздел']);
            }
            $pdo->prepare('DELETE FROM application_roadmap_items WHERE group_id = ? AND application_id = ?')
                ->execute([$groupId, $applicationId]);
            $pdo->prepare('DELETE FROM application_roadmap_groups WHERE id = ? AND application_id = ?')
                ->execute([$groupId, $applicationId]);
            finbuild_roadmap_send_json([
                'success' => true,
                'stats' => finbuild_roadmap_fetch($pdo, $applicationId)['stats'],
            ]);
        }

        if ($action === 'add_item') {
            $groupId = (int) ($_POST['group_id'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));
            if ($groupId <= 0 || $title === '') {
                finbuild_roadmap_send_json(['success' => false, 'error' => 'Укажите пункт']);
            }
            if (!finbuild_roadmap_group_belongs($pdo, $groupId, $applicationId)) {
                finbuild_roadmap_send_json(['success' => false, 'error' => 'Раздел не найден']);
            }
            if (mb_strlen($title) > 500) {
                finbuild_roadmap_send_json(['success' => false, 'error' => 'Слишком длинный текст']);
            }
            $sort = finbuild_roadmap_next_item_sort($pdo, $groupId);
            $pdo->prepare(
                'INSERT INTO application_roadmap_items (group_id, application_id, title, sort_order, created_by) VALUES (?, ?, ?, ?, ?)'
            )->execute([$groupId, $applicationId, $title, $sort, $userId]);
            $itemId = (int) $pdo->lastInsertId();
            $row = finbuild_roadmap_item_belongs($pdo, $itemId, $applicationId);
            finbuild_roadmap_send_json([
                'success' => true,
                'item' => $row ? finbuild_roadmap_map_item($row) : null,
                'stats' => finbuild_roadmap_fetch($pdo, $applicationId)['stats'],
            ]);
        }

        if ($action === 'rename_item') {
            $itemId = (int) ($_POST['item_id'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));
            if ($itemId <= 0 || $title === '') {
                finbuild_roadmap_send_json(['success' => false, 'error' => 'Неверные параметры']);
            }
            if (mb_strlen($title) > 500) {
                finbuild_roadmap_send_json(['success' => false, 'error' => 'Слишком длинный текст']);
            }
            $row = finbuild_roadmap_item_belongs($pdo, $itemId, $applicationId);
            if (!$row) {
                finbuild_roadmap_send_json(['success' => false, 'error' => 'Пункт не найден']);
            }
            $oldTitle = trim((string) ($row['title'] ?? ''));
            if ($oldTitle === $title) {
                finbuild_roadmap_send_json(['success' => true, 'item' => finbuild_roadmap_map_item($row)]);
            }
            $trackUpdatedBy = finbuild_roadmap_updated_by_ready($pdo);
            if ($trackUpdatedBy) {
                $pdo->prepare(
                    'UPDATE application_roadmap_items SET title = ?, updated_by = ?, updated_at = NOW() WHERE id = ? AND application_id = ?'
                )->execute([$title, $userId, $itemId, $applicationId]);
            } else {
                $pdo->prepare('UPDATE application_roadmap_items SET title = ? WHERE id = ? AND application_id = ?')
                    ->execute([$title, $itemId, $applicationId]);
            }
            $updated = finbuild_roadmap_item_belongs($pdo, $itemId, $applicationId);
            finbuild_roadmap_send_json([
                'success' => true,
                'item' => $updated ? finbuild_roadmap_map_item($updated) : null,
            ]);
        }

        if ($action === 'toggle_item') {
            $itemId = (int) ($_POST['item_id'] ?? 0);
            $isDone = !empty($_POST['is_done']) ? 1 : 0;
            $row = finbuild_roadmap_item_belongs($pdo, $itemId, $applicationId);
            if (!$row) {
                finbuild_roadmap_send_json(['success' => false, 'error' => 'Пункт не найден']);
            }
            if ($isDone) {
                $pdo->prepare(
                    'UPDATE application_roadmap_items SET is_done = 1, done_at = NOW(), done_by = ? WHERE id = ? AND application_id = ?'
                )->execute([$userId, $itemId, $applicationId]);
            } else {
                $pdo->prepare(
                    'UPDATE application_roadmap_items SET is_done = 0, done_at = NULL, done_by = NULL WHERE id = ? AND application_id = ?'
                )->execute([$itemId, $applicationId]);
            }
            $updated = finbuild_roadmap_item_belongs($pdo, $itemId, $applicationId);
            finbuild_roadmap_send_json([
                'success' => true,
                'item' => $updated ? finbuild_roadmap_map_item($updated) : null,
                'stats' => finbuild_roadmap_fetch($pdo, $applicationId)['stats'],
            ]);
        }

        if ($action === 'delete_item') {
            $itemId = (int) ($_POST['item_id'] ?? 0);
            if ($itemId <= 0 || !finbuild_roadmap_item_belongs($pdo, $itemId, $applicationId)) {
                finbuild_roadmap_send_json(['success' => false, 'error' => 'Пункт не найден']);
            }
            $pdo->prepare('DELETE FROM application_roadmap_items WHERE id = ? AND application_id = ?')
                ->execute([$itemId, $applicationId]);
            finbuild_roadmap_send_json([
                'success' => true,
                'stats' => finbuild_roadmap_fetch($pdo, $applicationId)['stats'],
            ]);
        }

        if ($action === 'add_template') {
            finbuild_roadmap_add_template($pdo, $applicationId, $userId);
            finbuild_roadmap_send_json(['success' => true] + finbuild_roadmap_fetch($pdo, $applicationId));
        }

        finbuild_roadmap_send_json(['success' => false, 'error' => 'Неизвестное действие']);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('application_roadmap dispatch: ' . $e->getMessage());
        }
        finbuild_roadmap_send_json(['success' => false, 'error' => 'Ошибка сервера']);
    }
}
