<?php
/**
 * Хранение оценок по банковской методике (на кейс ЛК банка).
 */
declare(strict_types=1);

require_once __DIR__ . '/engine.php';

function bank_methodology_ensure_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `bank_case_methodology_assessments` (
          `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
          `bank_case_id` int UNSIGNED NOT NULL,
          `version` int UNSIGNED NOT NULL DEFAULT 1,
          `status` varchar(20) NOT NULL DEFAULT 'draft',
          `state_json` longtext NOT NULL,
          `result_json` longtext NULL,
          `total_score` decimal(8,2) NULL,
          `rating` varchar(16) NULL,
          `position_code` varchar(16) NULL,
          `hard_stop` tinyint(1) NOT NULL DEFAULT 0,
          `created_by` int UNSIGNED NOT NULL,
          `updated_by` int UNSIGNED NULL,
          `finalized_by` int UNSIGNED NULL,
          `finalized_at` datetime NULL,
          `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_case_version` (`bank_case_id`, `version`),
          KEY `idx_case_status` (`bank_case_id`, `status`),
          KEY `idx_case_updated` (`bank_case_id`, `updated_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $done = true;
}

/**
 * @return array<string,mixed>|null
 */
function bank_methodology_fetch_latest(PDO $pdo, int $bankCaseId): ?array
{
    bank_methodology_ensure_table($pdo);
    $stmt = $pdo->prepare(
        'SELECT * FROM bank_case_methodology_assessments
         WHERE bank_case_id = ?
         ORDER BY version DESC
         LIMIT 1'
    );
    $stmt->execute([$bankCaseId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? bank_methodology_hydrate_row($row) : null;
}

/**
 * @return array<string,mixed>|null
 */
function bank_methodology_fetch_latest_final(PDO $pdo, int $bankCaseId): ?array
{
    bank_methodology_ensure_table($pdo);
    $stmt = $pdo->prepare(
        'SELECT * FROM bank_case_methodology_assessments
         WHERE bank_case_id = ? AND status = ?
         ORDER BY version DESC
         LIMIT 1'
    );
    $stmt->execute([$bankCaseId, 'final']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? bank_methodology_hydrate_row($row) : null;
}

/**
 * @return list<array<string,mixed>>
 */
function bank_methodology_fetch_history(PDO $pdo, int $bankCaseId, int $limit = 20): array
{
    bank_methodology_ensure_table($pdo);
    $stmt = $pdo->prepare(
        'SELECT id, version, status, total_score, rating, position_code, hard_stop,
                created_by, updated_by, finalized_by, finalized_at, created_at, updated_at
         FROM bank_case_methodology_assessments
         WHERE bank_case_id = ?
         ORDER BY version DESC
         LIMIT ' . (int) $limit
    );
    $stmt->execute([$bankCaseId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_map(static function (array $r): array {
        return [
            'id' => (int) $r['id'],
            'version' => (int) $r['version'],
            'status' => (string) $r['status'],
            'total_score' => $r['total_score'] !== null ? (float) $r['total_score'] : null,
            'rating' => $r['rating'],
            'position_code' => $r['position_code'],
            'hard_stop' => !empty($r['hard_stop']),
            'created_by' => (int) $r['created_by'],
            'updated_by' => $r['updated_by'] !== null ? (int) $r['updated_by'] : null,
            'finalized_by' => $r['finalized_by'] !== null ? (int) $r['finalized_by'] : null,
            'finalized_at' => $r['finalized_at'],
            'created_at' => $r['created_at'],
            'updated_at' => $r['updated_at'],
        ];
    }, $rows);
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function bank_methodology_hydrate_row(array $row): array
{
    $state = json_decode((string) ($row['state_json'] ?? ''), true);
    if (!is_array($state)) {
        $state = bank_methodology_empty_state();
    }
    $result = json_decode((string) ($row['result_json'] ?? ''), true);
    if (!is_array($result)) {
        $result = null;
    }
    return [
        'id' => (int) $row['id'],
        'bank_case_id' => (int) $row['bank_case_id'],
        'version' => (int) $row['version'],
        'status' => (string) $row['status'],
        'state' => $state,
        'result' => $result,
        'total_score' => $row['total_score'] !== null ? (float) $row['total_score'] : null,
        'rating' => $row['rating'],
        'position_code' => $row['position_code'],
        'hard_stop' => !empty($row['hard_stop']),
        'created_by' => (int) $row['created_by'],
        'updated_by' => $row['updated_by'] !== null ? (int) $row['updated_by'] : null,
        'finalized_by' => $row['finalized_by'] !== null ? (int) $row['finalized_by'] : null,
        'finalized_at' => $row['finalized_at'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

/**
 * Сохранить draft: обновляет текущий draft или создаёт новый, если последняя — final.
 *
 * @param array<string,mixed> $state
 * @return array<string,mixed>
 */
function bank_methodology_save_draft(PDO $pdo, int $bankCaseId, int $userId, array $state): array
{
    bank_methodology_ensure_table($pdo);
    $evaluated = bank_methodology_evaluate($state);
    $normState = $evaluated['state'];
    $result = [
        'result' => $evaluated['result'],
        'finance' => $evaluated['finance'],
        'business' => $evaluated['business'],
    ];

    $latest = bank_methodology_fetch_latest($pdo, $bankCaseId);
    $pdo->beginTransaction();
    try {
        if ($latest && $latest['status'] === 'draft') {
            $stmt = $pdo->prepare(
                'UPDATE bank_case_methodology_assessments
                 SET state_json = ?, result_json = ?, total_score = ?, rating = ?, position_code = ?,
                     hard_stop = ?, updated_by = ?, updated_at = NOW()
                 WHERE id = ?'
            );
            $stmt->execute([
                json_encode($normState, JSON_UNESCAPED_UNICODE),
                json_encode($result, JSON_UNESCAPED_UNICODE),
                $evaluated['result']['total_score'],
                $evaluated['result']['rating'],
                $evaluated['result']['position'],
                $evaluated['result']['hard_stop'] ? 1 : 0,
                $userId,
                $latest['id'],
            ]);
            $id = (int) $latest['id'];
            $version = (int) $latest['version'];
        } else {
            $nextVersion = $latest ? ((int) $latest['version'] + 1) : 1;
            $stmt = $pdo->prepare(
                'INSERT INTO bank_case_methodology_assessments
                 (bank_case_id, version, status, state_json, result_json, total_score, rating, position_code, hard_stop, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $bankCaseId,
                $nextVersion,
                'draft',
                json_encode($normState, JSON_UNESCAPED_UNICODE),
                json_encode($result, JSON_UNESCAPED_UNICODE),
                $evaluated['result']['total_score'],
                $evaluated['result']['rating'],
                $evaluated['result']['position'],
                $evaluated['result']['hard_stop'] ? 1 : 0,
                $userId,
                $userId,
            ]);
            $id = (int) $pdo->lastInsertId();
            $version = $nextVersion;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $saved = bank_methodology_fetch_latest($pdo, $bankCaseId);
    return $saved ?? [
        'id' => $id,
        'version' => $version,
        'status' => 'draft',
        'state' => $normState,
        'result' => $result,
    ];
}

/**
 * Зафиксировать текущий draft как final (или создать final из state).
 *
 * @param array<string,mixed>|null $state
 * @return array<string,mixed>
 */
function bank_methodology_finalize(PDO $pdo, int $bankCaseId, int $userId, ?array $state = null): array
{
    bank_methodology_ensure_table($pdo);
    if ($state !== null) {
        bank_methodology_save_draft($pdo, $bankCaseId, $userId, $state);
    }
    $latest = bank_methodology_fetch_latest($pdo, $bankCaseId);
    if (!$latest) {
        throw new RuntimeException('Нет черновика для фиксации');
    }
    if ($latest['status'] === 'final') {
        return $latest;
    }

    $evaluated = bank_methodology_evaluate($latest['state']);
    if (!empty($evaluated['result']['incomplete'])) {
        throw new RuntimeException(
            'Нельзя зафиксировать: недостаточно данных для итогового рейтинга. Заполните все показатели или укажите ручной балл.'
        );
    }

    $stmt = $pdo->prepare(
        'UPDATE bank_case_methodology_assessments
         SET status = ?, finalized_by = ?, finalized_at = NOW(), updated_by = ?, updated_at = NOW()
         WHERE id = ? AND status = ?'
    );
    $stmt->execute(['final', $userId, $userId, $latest['id'], 'draft']);

    $saved = bank_methodology_fetch_latest($pdo, $bankCaseId);
    if (!$saved) {
        throw new RuntimeException('Не удалось зафиксировать оценку');
    }
    return $saved;
}
