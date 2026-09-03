<?php
/**
 * Матрица прав доступа (редактируется руководителем на странице «Права доступа»).
 * Хранится в system_settings.setting_key = access_rights.
 */
declare(strict_types=1);

const FINBUILD_ACCESS_SETTING_KEY = 'access_rights';

/** @return list<string> */
function finbuild_access_role_keys(): array
{
    return ['director', 'manager', 'case_manager', 'analyst', 'partner', 'client', 'bank'];
}

/**
 * Права (строки матрицы). Значение true = роль имеет право / возможность.
 *
 * @return array<string, array{label: string, group: string, hint?: string, lock_director?: bool}>
 */
function finbuild_access_permission_defs(): array
{
    return [
        'applications.view_all' => [
            'label' => 'Все заявки',
            'group' => 'Заявки',
            'hint' => 'Список и карточки всех заявок. Без права — только свои / назначенные.',
            'lock_director' => true,
        ],
        'applications.assign' => [
            'label' => 'Назначить ответственного',
            'group' => 'Заявки',
            'hint' => 'Смена поля «Ответственный» в карточке заявки.',
        ],
        'structure.edit' => [
            'label' => 'Структура',
            'group' => 'Заявки',
            'hint' => 'Редактирование структуры / чек-листа документов заявки.',
        ],
        'roadmap.edit' => [
            'label' => 'Дорожная карта',
            'group' => 'Заявки',
            'hint' => 'Вкладка «Дорожная карта» и изменение пунктов.',
        ],
        'admin.users' => [
            'label' => 'Пользователи',
            'group' => 'Администрирование',
            'hint' => 'Раздел пользователей: список, создание, карточка.',
        ],
        'admin.products' => [
            'label' => 'Продукты',
            'group' => 'Администрирование',
            'hint' => 'Справочник продуктов (БГ / кредиты).',
        ],
        'stats.monthly' => [
            'label' => 'Статистика',
            'group' => 'Администрирование',
            'hint' => 'Месячная статистика по заявкам.',
        ],
        'access_rights.manage' => [
            'label' => 'Права доступа',
            'group' => 'Администрирование',
            'hint' => 'Эта страница. Нельзя отключить у руководителя.',
            'lock_director' => true,
        ],
        'privacy.see_owner_identity' => [
            'label' => 'Контакты владельца и ФИО в чате',
            'group' => 'Конфиденциальность',
            'hint' => 'Видит контакты владельца заявки и реальные ФИО агента/клиента в чате. Без права — маскировка («Агент» / «Клиент» / «Менеджер»).',
        ],
    ];
}

/** @return array<string, array{label: string, description: string}> */
function finbuild_access_default_role_meta(): array
{
    return [
        'director' => [
            'label' => 'Руководитель',
            'description' => 'Полный доступ к платформе, включая статистику и настройку прав. Обычно 1–2 человека.',
        ],
        'manager' => [
            'label' => 'Менеджер',
            'description' => 'Работа со всеми заявками, структурой, дорожной картой и админкой пользователей/продуктов. Без статистики и прав доступа.',
        ],
        'case_manager' => [
            'label' => 'Менеджер по заявкам',
            'description' => 'Ведёт только назначенные ему заявки. Без админки; контакты владельца и ФИО в чате скрыты.',
        ],
        'analyst' => [
            'label' => 'Аналитик',
            'description' => 'Урезанный ЛК: список заявок, структура и документы для анализа. Без чата и админки.',
        ],
        'partner' => [
            'label' => 'Партнёр (агент)',
            'description' => 'Свои заявки, продукты и чат. Флаг «аналитик» у партнёра даёт доп. доступ к анализу чужих заявок.',
        ],
        'client' => [
            'label' => 'Клиент',
            'description' => 'Только собственные заявки, документы и переписка по ним.',
        ],
        'bank' => [
            'label' => 'Банк',
            'description' => 'Отдельный ЛК банка: кейсы, переданные в этот банк. Не видит общий кабинет FinBuild.',
        ],
    ];
}

/** @return array<string, array<string, bool>> */
function finbuild_access_default_matrix(): array
{
    $roles = finbuild_access_role_keys();
    $empty = static function () use ($roles): array {
        $row = [];
        foreach ($roles as $r) {
            $row[$r] = false;
        }
        return $row;
    };

    $matrix = [];
    foreach (array_keys(finbuild_access_permission_defs()) as $key) {
        $matrix[$key] = $empty();
    }

    // director / manager — полный операционный доступ
    foreach (['director', 'manager'] as $r) {
        $matrix['applications.view_all'][$r] = true;
        $matrix['applications.assign'][$r] = true;
        $matrix['structure.edit'][$r] = true;
        $matrix['roadmap.edit'][$r] = true;
        $matrix['admin.users'][$r] = true;
        $matrix['admin.products'][$r] = true;
        $matrix['privacy.see_owner_identity'][$r] = true;
    }
    $matrix['stats.monthly']['director'] = true;
    $matrix['access_rights.manage']['director'] = true;

    // analyst — структура (и косвенно «все заявки» для анализа)
    $matrix['applications.view_all']['analyst'] = true;
    $matrix['structure.edit']['analyst'] = true;
    $matrix['privacy.see_owner_identity']['analyst'] = true;

    // partner / client / bank — видят идентичность в своём контексте
    $matrix['privacy.see_owner_identity']['partner'] = true;
    $matrix['privacy.see_owner_identity']['client'] = true;
    $matrix['privacy.see_owner_identity']['bank'] = true;

    // case_manager — без view_all, без админки, без see_owner
    return $matrix;
}

/** @return array{roles: array, matrix: array, version: int} */
function finbuild_access_defaults(): array
{
    return [
        'version' => 1,
        'roles' => finbuild_access_default_role_meta(),
        'matrix' => finbuild_access_default_matrix(),
    ];
}

/**
 * @return array{roles: array, matrix: array, version: int}
 */
function finbuild_access_config(?PDO $pdo = null): array
{
    static $cache = null;
    if (!empty($GLOBALS['finbuild_access_config_force_reload'])) {
        unset($GLOBALS['finbuild_access_config_force_reload']);
        $cache = null;
    }
    if ($cache !== null) {
        return $cache;
    }

    $defaults = finbuild_access_defaults();
    try {
        $pdo = $pdo ?? (function_exists('getPDO') ? getPDO() : null);
        if ($pdo === null) {
            return $cache = $defaults;
        }
        $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([FINBUILD_ACCESS_SETTING_KEY]);
        $raw = $stmt->fetchColumn();
        if (!is_string($raw) || trim($raw) === '') {
            return $cache = $defaults;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $cache = $defaults;
        }
        return $cache = finbuild_access_merge_config($defaults, $decoded);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('finbuild_access_config: ' . $e->getMessage());
        }
        return $cache = $defaults;
    }
}

/** Сброс кэша после сохранения. */
function finbuild_access_config_reset(): void
{
    $GLOBALS['finbuild_access_config_force_reload'] = true;
}

/**
 * @param array{roles: array, matrix: array, version: int} $defaults
 * @param array<string, mixed> $stored
 * @return array{roles: array, matrix: array, version: int}
 */
function finbuild_access_merge_config(array $defaults, array $stored): array
{
    $out = $defaults;
    if (isset($stored['version'])) {
        $out['version'] = (int) $stored['version'];
    }
    if (!empty($stored['roles']) && is_array($stored['roles'])) {
        foreach ($defaults['roles'] as $role => $meta) {
            if (!isset($stored['roles'][$role]) || !is_array($stored['roles'][$role])) {
                continue;
            }
            $label = trim((string) ($stored['roles'][$role]['label'] ?? ''));
            $desc = trim((string) ($stored['roles'][$role]['description'] ?? ''));
            if ($label !== '') {
                $out['roles'][$role]['label'] = mb_substr($label, 0, 80);
            }
            if ($desc !== '') {
                $out['roles'][$role]['description'] = mb_substr($desc, 0, 500);
            }
        }
    }
    if (!empty($stored['matrix']) && is_array($stored['matrix'])) {
        foreach (array_keys($defaults['matrix']) as $perm) {
            if (!isset($stored['matrix'][$perm]) || !is_array($stored['matrix'][$perm])) {
                continue;
            }
            foreach (finbuild_access_role_keys() as $role) {
                if (array_key_exists($role, $stored['matrix'][$perm])) {
                    $out['matrix'][$perm][$role] = !empty($stored['matrix'][$perm][$role]);
                }
            }
        }
    }
    // Руководитель всегда может управлять правами и видеть все заявки
    $out['matrix']['access_rights.manage']['director'] = true;
    $out['matrix']['applications.view_all']['director'] = true;
    return $out;
}

/**
 * Проверка права для пользователя.
 * Партнёр с is_analyst=1 наследует отдельные права аналитика.
 */
function finbuild_can(string $permission, ?array $user = null): bool
{
    $cfg = finbuild_access_config();
    $role = function_exists('finbuild_user_role')
        ? finbuild_user_role($user)
        : (string) (($user['role'] ?? $_SESSION['role'] ?? 'client'));

    $matrix = $cfg['matrix'][$permission] ?? null;
    if (!is_array($matrix)) {
        return false;
    }

    if (!empty($matrix[$role])) {
        return true;
    }

    if (
        $role === 'partner'
        && function_exists('finbuild_user_is_analyst_flag')
        && finbuild_user_is_analyst_flag($user ?? [
            'is_analyst' => (int) ($_SESSION['is_analyst'] ?? 0),
        ])
        && in_array($permission, ['applications.view_all', 'structure.edit', 'privacy.see_owner_identity'], true)
        && !empty($matrix['analyst'])
    ) {
        return true;
    }

    return false;
}

/** Скрывать контакты владельца и маскировать ФИО в чате. */
function finbuild_should_mask_owner_identity(?array $user = null): bool
{
    return !finbuild_can('privacy.see_owner_identity', $user);
}

/**
 * @param array{roles?: array, matrix?: array} $payload
 * @return array{ok: bool, error?: string}
 */
function finbuild_access_save(PDO $pdo, array $payload, int $updatedBy): array
{
    $merged = finbuild_access_merge_config(finbuild_access_defaults(), [
        'version' => 1,
        'roles' => $payload['roles'] ?? [],
        'matrix' => $payload['matrix'] ?? [],
    ]);

    $json = json_encode($merged, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return ['ok' => false, 'error' => 'Не удалось сериализовать настройки'];
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO system_settings (setting_key, setting_value, updated_at, updated_by)
             VALUES (?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW(), updated_by = VALUES(updated_by)'
        );
        $stmt->execute([FINBUILD_ACCESS_SETTING_KEY, $json, $updatedBy]);
        finbuild_access_config_reset();
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
