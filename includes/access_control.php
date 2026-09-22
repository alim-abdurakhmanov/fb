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
    return ['director', 'manager', 'case_manager', 'analyst', 'partner', 'client', 'beneficiary', 'bank'];
}

/**
 * Права (строки матрицы). Значение true = роль имеет право / возможность.
 *
 * applicable_roles — если задано, для остальных ролей в UI «—» (не применяется).
 *
 * @return array<string, array{label: string, group: string, hint?: string, lock_director?: bool, applicable_roles?: list<string>}>
 */
function finbuild_access_permission_defs(): array
{
    return [
        'applications.view_all' => [
            'label' => 'Все заявки',
            'group' => 'Заявки',
            'hint' => 'Список и карточки всех заявок. Без права — только свои / назначенные.',
            'lock_director' => true,
            'applicable_roles' => ['director', 'manager', 'case_manager', 'analyst'],
        ],
        'applications.assign' => [
            'label' => 'Назначить ответственного',
            'group' => 'Заявки',
            'hint' => 'Смена поля «Ответственный» в карточке заявки.',
            'applicable_roles' => ['director', 'manager', 'case_manager', 'analyst'],
        ],
        'chat.access' => [
            'label' => 'Чат',
            'group' => 'Заявки',
            'hint' => 'Чат заявки на карточке и чат продукта на странице банка. У партнёра, клиента и заказчика всегда включён; у банка — отдельный чат с менеджером.',
            'applicable_roles' => ['director', 'manager', 'case_manager', 'analyst'],
        ],
        'banks.work' => [
            'label' => 'Работа с банками',
            'group' => 'Заявки',
            'hint' => 'Вкладка «Работа с банком»: отправка пакета в банк и чат с банком. ЛК банка не затрагивается.',
            'applicable_roles' => ['director', 'manager', 'case_manager', 'analyst'],
        ],
        'methodology.view' => [
            'label' => 'Методика',
            'group' => 'Заявки',
            'hint' => 'Вкладка «Методика» на карточке заявки: оценка по банковской методике (отдельно от FinScore). В ЛК банка вкладка доступна всегда.',
            'applicable_roles' => ['director', 'manager', 'case_manager', 'analyst'],
        ],
        'structure.view' => [
            'label' => 'Структура — просмотр',
            'group' => 'Заявки',
            'hint' => 'Вкладка «Структура» только для чтения.',
        ],
        'structure.edit' => [
            'label' => 'Структура — редактирование',
            'group' => 'Заявки',
            'hint' => 'Добавление, изменение и удаление пунктов структуры.',
        ],
        'roadmap.view' => [
            'label' => 'Дорожная карта — просмотр',
            'group' => 'Заявки',
            'hint' => 'Вкладка «Дорожная карта» только для чтения.',
        ],
        'roadmap.edit' => [
            'label' => 'Дорожная карта — редактирование',
            'group' => 'Заявки',
            'hint' => 'Изменение разделов и пунктов дорожной карты.',
        ],
        'admin.users' => [
            'label' => 'Пользователи',
            'group' => 'Администрирование',
            'hint' => 'Раздел пользователей: список, создание, карточка. Заказчиков можно создать только здесь (саморегистрация недоступна).',
            'applicable_roles' => ['director', 'manager', 'case_manager', 'analyst'],
        ],
        'admin.products' => [
            'label' => 'Продукты',
            'group' => 'Администрирование',
            'hint' => 'Справочник продуктов (БГ / кредиты).',
            'applicable_roles' => ['director', 'manager', 'case_manager', 'analyst'],
        ],
        'stats.monthly' => [
            'label' => 'Статистика',
            'group' => 'Администрирование',
            'hint' => 'Месячная статистика по заявкам.',
            'applicable_roles' => ['director', 'manager', 'case_manager', 'analyst'],
        ],
        'access_rights.manage' => [
            'label' => 'Права доступа',
            'group' => 'Администрирование',
            'hint' => 'Эта страница. Нельзя отключить у руководителя.',
            'lock_director' => true,
            'applicable_roles' => ['director', 'manager', 'case_manager', 'analyst'],
        ],
        'privacy.see_owner_identity' => [
            'label' => 'Контакты владельца и ФИО в чате',
            'group' => 'Конфиденциальность',
            'hint' => 'Только для сотрудников: видит контакты владельца заявки и ФИО агента/клиента в чате. Без права — маскировка («Агент» / «Клиент»).',
            'applicable_roles' => ['director', 'manager', 'case_manager', 'analyst'],
        ],
        'privacy.staff_identity_visible_to_owner' => [
            'label' => 'Владелец и банк видят ФИО в чате',
            'group' => 'Конфиденциальность',
            'hint' => 'Если включено — партнёр, клиент, заказчик и банк видят ФИО этой роли в чатах. Если выключено — только бейдж («Менеджер», «Руководитель», «Аналитик») без ФИО.',
            'applicable_roles' => ['director', 'manager', 'case_manager', 'analyst'],
        ],
    ];
}

/** @return array<string, array{label: string, description: string}> */
function finbuild_access_default_role_meta(): array
{
    return [
        'director' => ['label' => 'Руководитель', 'description' => ''],
        'manager' => ['label' => 'Менеджер', 'description' => ''],
        'case_manager' => ['label' => 'Менеджер по заявкам', 'description' => ''],
        'analyst' => ['label' => 'Аналитик', 'description' => ''],
        'partner' => ['label' => 'Партнёр', 'description' => ''],
        'client' => ['label' => 'Клиент', 'description' => ''],
        'beneficiary' => ['label' => 'Заказчик', 'description' => 'Создаётся только через раздел «Пользователи»'],
        'bank' => ['label' => 'Банк', 'description' => ''],
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

    foreach (['director', 'manager'] as $r) {
        $matrix['applications.view_all'][$r] = true;
        $matrix['applications.assign'][$r] = true;
        $matrix['chat.access'][$r] = true;
        $matrix['banks.work'][$r] = true;
        $matrix['methodology.view'][$r] = true;
        $matrix['structure.view'][$r] = true;
        $matrix['structure.edit'][$r] = true;
        $matrix['roadmap.view'][$r] = true;
        $matrix['roadmap.edit'][$r] = true;
        $matrix['admin.users'][$r] = true;
        $matrix['admin.products'][$r] = true;
        $matrix['privacy.see_owner_identity'][$r] = true;
        $matrix['privacy.staff_identity_visible_to_owner'][$r] = true;
    }
    $matrix['stats.monthly']['director'] = true;
    $matrix['access_rights.manage']['director'] = true;

    // Менеджер по заявкам: чат, работа с банками, методика и структура на просмотр; без дорожной карты и без идентичности владельца;
    // ФИО в чате для владельца/банка по умолчанию скрыто
    $matrix['chat.access']['case_manager'] = true;
    $matrix['banks.work']['case_manager'] = true;
    $matrix['methodology.view']['case_manager'] = true;
    $matrix['structure.view']['case_manager'] = true;

    $matrix['applications.view_all']['analyst'] = true;
    $matrix['structure.view']['analyst'] = true;
    $matrix['structure.edit']['analyst'] = true;
    $matrix['privacy.see_owner_identity']['analyst'] = true;
    $matrix['privacy.staff_identity_visible_to_owner']['analyst'] = true;

    return $matrix;
}

/** @return array{roles: array, matrix: array, version: int} */
function finbuild_access_defaults(): array
{
    return [
        'version' => 9,
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
        // Миграция со старой матрицы: был только *.edit → включаем и *.view
        foreach (finbuild_access_role_keys() as $role) {
            if (!empty($out['matrix']['structure.edit'][$role])) {
                $out['matrix']['structure.view'][$role] = true;
            }
            if (!empty($out['matrix']['roadmap.edit'][$role])) {
                $out['matrix']['roadmap.view'][$role] = true;
            }
        }
    }

    // Права сотрудников: у partner/client/beneficiary/bank в матрице всегда выкл. (в UI — «—»)
    $staffOnlyPerms = [
        'applications.view_all',
        'applications.assign',
        'chat.access',
        'banks.work',
        'methodology.view',
        'admin.users',
        'admin.products',
        'stats.monthly',
        'access_rights.manage',
        'privacy.see_owner_identity',
        'privacy.staff_identity_visible_to_owner',
    ];
    foreach (['partner', 'client', 'beneficiary', 'bank'] as $r) {
        foreach ($staffOnlyPerms as $perm) {
            if (isset($out['matrix'][$perm])) {
                $out['matrix'][$perm][$r] = false;
            }
        }
    }

    // Руководитель всегда может управлять правами и видеть все заявки
    $out['matrix']['access_rights.manage']['director'] = true;
    $out['matrix']['applications.view_all']['director'] = true;
    $out['version'] = max(9, (int) ($out['version'] ?? 9));
    return $out;
}

/**
 * Проверка права для пользователя.
 * Партнёр с is_analyst=1 наследует отдельные права аналитика.
 * Редактирование подразумевает просмотр (structure/roadmap).
 */
function finbuild_can(string $permission, ?array $user = null): bool
{
    if ($permission === 'structure.view' && finbuild_can('structure.edit', $user)) {
        return true;
    }
    if ($permission === 'roadmap.view' && finbuild_can('roadmap.edit', $user)) {
        return true;
    }

    $cfg = finbuild_access_config();
    $role = function_exists('finbuild_user_role')
        ? finbuild_user_role($user)
        : (string) (($user['role'] ?? $_SESSION['role'] ?? 'client'));

    $defs = finbuild_access_permission_defs();
    if (!empty($defs[$permission]['applicable_roles']) && is_array($defs[$permission]['applicable_roles'])) {
        if (!in_array($role, $defs[$permission]['applicable_roles'], true)) {
            // N/A: право не настраивается для роли
            if ($permission === 'privacy.see_owner_identity') {
                return true; // маскировку сотрудников не включаем
            }
            if ($permission === 'chat.access') {
                // Партнёр/клиент/заказчик всегда в чате с менеджерами; банк — только банковский чат
                return $role === 'partner' || $role === 'client' || $role === 'beneficiary';
            }
            return false;
        }
    }

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
        && in_array($permission, ['applications.view_all', 'structure.view', 'structure.edit', 'privacy.see_owner_identity'], true)
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

/** Доступ к чату заявки и чату продукта. */
function finbuild_can_use_product_chat(?array $user = null): bool
{
    return finbuild_can('chat.access', $user);
}

/** Работа с банками: вкладка отправки/чата у сотрудников (ЛК банка — отдельно). */
function finbuild_can_work_with_banks(?array $user = null): bool
{
    return finbuild_can('banks.work', $user);
}

/** Вкладка «Методика» на карточке заявки (сотрудники). ЛК банка — отдельно, всегда. */
function finbuild_can_view_methodology(?array $user = null): bool
{
    return finbuild_can('methodology.view', $user);
}

/**
 * Нормализация роли отправителя для строки «Владелец и банк видят ФИО».
 */
function finbuild_staff_privacy_role(string $role, bool $isSubmanager = false): string
{
    if ($role === 'manager' && $isSubmanager) {
        return 'case_manager';
    }
    return $role;
}

/**
 * Видят ли партнёр/клиент/банк ФИО сотрудника данной роли в чате.
 */
function finbuild_staff_fio_visible_to_owner_and_bank(string $staffRole, bool $isSubmanager = false): bool
{
    $role = finbuild_staff_privacy_role($staffRole, $isSubmanager);
    $applicable = ['director', 'manager', 'case_manager', 'analyst'];
    if (!in_array($role, $applicable, true)) {
        return true;
    }
    $cfg = finbuild_access_config();
    return !empty($cfg['matrix']['privacy.staff_identity_visible_to_owner'][$role]);
}

/**
 * Маскирует ФИО сотрудников в сообщениях чата для владельца/банка.
 *
 * @param list<array<string, mixed>> $messages
 * @return list<array<string, mixed>>
 */
function finbuild_mask_staff_identity_in_chat_messages(array $messages): array
{
    foreach ($messages as &$m) {
        $role = (string) ($m['role'] ?? '');
        $isSub = !empty($m['is_submanager']);
        $effective = finbuild_staff_privacy_role($role, $isSub);
        if (!in_array($effective, ['director', 'manager', 'case_manager', 'analyst'], true)) {
            continue;
        }
        if (finbuild_staff_fio_visible_to_owner_and_bank($role, $isSub)) {
            continue;
        }
        $m['first_name'] = '';
        $m['last_name'] = '';
        $m['identity_masked'] = true;
    }
    unset($m);
    return $messages;
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
