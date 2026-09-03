<?php
/**
 * Intake-заявки от заказчика (бенефициара): одобрение суммы/лимита и привязка принципала.
 */
declare(strict_types=1);

/**
 * @return 'beneficiary'|'principal'|null
 */
function finbuild_chat_thread_for_viewer(?array $user, ?array $application = null): ?string
{
    $role = function_exists('finbuild_user_role')
        ? finbuild_user_role($user)
        : (string) ($user['role'] ?? '');
    if ($role === 'beneficiary') {
        return 'beneficiary';
    }
    if ($role === 'client') {
        return 'principal';
    }
    if (function_exists('finbuild_is_manager') && finbuild_is_manager($role)) {
        return null; // staff выбирает thread сам
    }
    if ($role === 'partner' || $role === 'analyst') {
        return 'principal';
    }
    return 'principal';
}

function finbuild_chat_normalize_thread(string $thread): string
{
    return $thread === 'beneficiary' ? 'beneficiary' : 'principal';
}

/** Чаты продуктов только с клиентом, не с заказчиком. */
function finbuild_chat_thread_has_product_chats(string $thread): bool
{
    return finbuild_chat_normalize_thread($thread) === 'principal';
}

/**
 * Метка заявки, пришедшей от заказчика (бенефициара).
 *
 * @param array<string, mixed> $application
 * @return array{is_intake:bool, badge:string, class:string, hint:string, customer:string}
 */
function finbuild_application_intake_mark(array $application): array
{
    $status = (string) ($application['intake_status'] ?? '');
    if ($status === '') {
        return ['is_intake' => false, 'badge' => '', 'class' => '', 'hint' => '', 'customer' => ''];
    }
    $customer = trim((string) ($application['customer_name'] ?? ''));
    if ($status === 'pending_review') {
        return [
            'is_intake' => true,
            'badge' => 'от заказчика',
            'class' => 'bg-warning text-dark',
            'hint' => 'Заявка от заказчика, на рассмотрении',
            'customer' => $customer,
        ];
    }
    if ($status === 'rejected') {
        return [
            'is_intake' => true,
            'badge' => 'от заказчика',
            'class' => 'bg-secondary',
            'hint' => 'Заявка от заказчика отклонена',
            'customer' => $customer,
        ];
    }
    return [
        'is_intake' => true,
        'badge' => 'от заказчика',
        'class' => 'bg-warning text-dark',
        'hint' => 'Заявка пришла от заказчика',
        'customer' => $customer,
    ];
}

/**
 * Понятное описание суммы/лимита после одобрения (или запроса до него).
 *
 * @param array<string, mixed> $application
 */
function finbuild_application_intake_amount_summary(array $application): string
{
    $status = (string) ($application['intake_status'] ?? '');
    $mode = (string) ($application['amount_mode'] ?? 'fixed');
    $approvedAmount = isset($application['approved_amount']) ? (float) $application['approved_amount'] : 0.0;
    $approvedLimit = isset($application['approved_limit']) ? (float) $application['approved_limit'] : 0.0;
    $requested = isset($application['requested_amount']) ? (float) $application['requested_amount'] : 0.0;
    $amount = isset($application['amount']) ? (float) $application['amount'] : 0.0;

    $fmt = static function (float $v): string {
        return number_format($v, 0, '.', ' ') . ' ₽';
    };

    if ($status === 'approved') {
        if ($mode === 'open' && $approvedLimit > 0) {
            return 'Установлен лимит: ' . $fmt($approvedLimit);
        }
        if ($approvedAmount > 0) {
            return 'Запрашиваемая сумма: ' . $fmt($approvedAmount);
        }
        if ($amount > 0) {
            return 'Запрашиваемая сумма: ' . $fmt($amount);
        }
        return 'Одобрено';
    }

    if ($mode === 'open') {
        return 'Без конкретной суммы — нужен лимит';
    }
    if ($requested > 0) {
        return 'Запрашиваемая сумма: ' . $fmt($requested);
    }
    if ($amount > 0) {
        return 'Запрашиваемая сумма: ' . $fmt($amount);
    }
    return '';
}

function finbuild_intake_store_created_client_credentials(int $applicationId, string $email, string $password): void
{
    if ($applicationId <= 0 || $email === '' || $password === '') {
        return;
    }
    if (!isset($_SESSION['intake_client_credentials']) || !is_array($_SESSION['intake_client_credentials'])) {
        $_SESSION['intake_client_credentials'] = [];
    }
    $_SESSION['intake_client_credentials'][$applicationId] = [
        'email' => $email,
        'password' => $password,
        'saved_at' => time(),
    ];
}

/**
 * @return array{email:string,password:string}|null
 */
function finbuild_intake_peek_created_client_credentials(int $applicationId): ?array
{
    $row = $_SESSION['intake_client_credentials'][$applicationId] ?? null;
    if (!is_array($row)) {
        return null;
    }
    $email = trim((string) ($row['email'] ?? ''));
    $password = (string) ($row['password'] ?? '');
    if ($email === '' || $password === '') {
        return null;
    }
    return ['email' => $email, 'password' => $password];
}

function finbuild_intake_clear_created_client_credentials(int $applicationId): void
{
    if (isset($_SESSION['intake_client_credentials'][$applicationId])) {
        unset($_SESSION['intake_client_credentials'][$applicationId]);
    }
}

function finbuild_application_intake_badge_html(array $application): string
{
    $mark = finbuild_application_intake_mark($application);
    if (!$mark['is_intake']) {
        return '';
    }
    return '<span class="badge ' . htmlspecialchars($mark['class']) . '" title="'
        . htmlspecialchars($mark['hint']) . '">' . htmlspecialchars($mark['badge']) . '</span>';
}

/**
 * Threads, доступные зрителю для заявки.
 *
 * @return list<string>
 */
function finbuild_chat_allowed_threads_for_viewer(?array $user, ?array $application): array
{
    $role = function_exists('finbuild_user_role')
        ? finbuild_user_role($user)
        : (string) ($user['role'] ?? '');
    $intake = (string) ($application['intake_status'] ?? '');

    if (function_exists('finbuild_is_manager') && finbuild_is_manager($role)) {
        if ($intake === '' || $intake === null) {
            return ['principal'];
        }
        if ($intake === 'rejected') {
            return ['beneficiary'];
        }
        if ($intake === 'pending_review' && empty($application['principal_user_id'])) {
            return ['beneficiary'];
        }
        return ['beneficiary', 'principal'];
    }
    if ($role === 'beneficiary') {
        return ['beneficiary'];
    }
    return ['principal'];
}

/**
 * Найти или создать клиента-принципала по ИНН.
 *
 * @param array{email?:string,phone?:string,first_name?:string,last_name?:string,password?:string} $contact
 * @return array{ok:bool,user_id?:int,created?:bool,plain_password?:string,error?:string}
 */
function finbuild_find_or_create_principal_client(PDO $pdo, string $inn, string $companyName, array $contact = [], ?int $createdBy = null): array
{
    $inn = preg_replace('/\D+/', '', $inn) ?? '';
    if (!preg_match('/^\d{10,12}$/', $inn)) {
        return ['ok' => false, 'error' => 'Некорректный ИНН принципала'];
    }
    $companyName = trim($companyName);
    if ($companyName === '') {
        return ['ok' => false, 'error' => 'Укажите название компании принципала'];
    }

    $stmt = $pdo->prepare(
        "SELECT id, email FROM users WHERE role = 'client' AND inn = ? AND is_active = 1 ORDER BY id ASC LIMIT 1"
    );
    $stmt->execute([$inn]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        return ['ok' => true, 'user_id' => (int) $existing['id'], 'created' => false];
    }

    $email = trim((string) ($contact['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Для нового клиента нужен корректный e-mail'];
    }
    $dup = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $dup->execute([$email]);
    if ($dup->fetch()) {
        return ['ok' => false, 'error' => 'Пользователь с таким e-mail уже существует'];
    }

    $first = trim((string) ($contact['first_name'] ?? '')) ?: 'Клиент';
    $last = trim((string) ($contact['last_name'] ?? '')) ?: $companyName;
    $phone = trim((string) ($contact['phone'] ?? ''));
    $plain = (string) ($contact['password'] ?? '');
    if ($plain === '') {
        $plain = bin2hex(random_bytes(4));
    }
    $hash = password_hash($plain, PASSWORD_DEFAULT);

    $ins = $pdo->prepare(
        'INSERT INTO users (email, password, inn, first_name, last_name, phone, company_name, role, registration_date, is_active, email_notifications_enabled, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, \'client\', CURDATE(), 1, 1, ?)'
    );
    $ins->execute([$email, $hash, $inn, $first, $last, $phone, $companyName, $createdBy]);
    return [
        'ok' => true,
        'user_id' => (int) $pdo->lastInsertId(),
        'created' => true,
        'plain_password' => $plain,
    ];
}

/**
 * Одобрить, отклонить или снять одобрение intake-заявки.
 *
 * @param array{
 *   action: 'approve_amount'|'approve_limit'|'approve'|'reject'|'reopen'|'dismiss_credentials',
 *   amount_mode?: 'fixed'|'open',
 *   principal_inn?: string,
 *   principal_company_name?: string,
 *   principal_email?: string,
 *   approved_amount?: float|null,
 *   approved_limit?: float|null,
 *   client_email?: string,
 *   client_phone?: string,
 *   client_first_name?: string,
 *   client_last_name?: string,
 *   reject_reason?: string
 * } $payload
 * @return array{ok:bool,error?:string,principal_user_id?:int,created_client?:bool,plain_password?:string,client_email?:string}
 */
function finbuild_intake_review_application(PDO $pdo, int $applicationId, array $reviewer, array $payload): array
{
    if (!function_exists('finbuild_is_manager') || !finbuild_is_manager((string) ($reviewer['role'] ?? ''))) {
        return ['ok' => false, 'error' => 'Недостаточно прав'];
    }

    $action = (string) ($payload['action'] ?? '');
    if ($action === 'dismiss_credentials') {
        finbuild_intake_clear_created_client_credentials($applicationId);
        return ['ok' => true];
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM applications WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$applicationId]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$app) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Заявка не найдена'];
        }

        $intakeStatus = (string) ($app['intake_status'] ?? '');
        if ($intakeStatus === '') {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Это не заявка от заказчика'];
        }

        $reviewerId = (int) ($reviewer['id'] ?? 0);

        if ($action === 'reopen') {
            if ($intakeStatus !== 'approved') {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Снять одобрение можно только у одобренной заявки'];
            }
            $upd = $pdo->prepare(
                'UPDATE applications SET
                    intake_status = ?,
                    approved_amount = NULL,
                    approved_limit = NULL,
                    intake_reviewed_at = NULL,
                    intake_reviewed_by = NULL
                 WHERE id = ?'
            );
            $upd->execute(['pending_review', $applicationId]);
            $pdo->commit();
            return ['ok' => true];
        }

        if ($intakeStatus !== 'pending_review') {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Заявка уже рассмотрена или не ожидает одобрения'];
        }

        if ($action === 'reject') {
            $upd = $pdo->prepare(
                'UPDATE applications SET intake_status = ?, intake_reviewed_at = NOW(), intake_reviewed_by = ?, status = ? WHERE id = ?'
            );
            $upd->execute(['rejected', $reviewerId, 'failed', $applicationId]);
            $pdo->commit();
            return ['ok' => true];
        }

        // Единое одобрение: action=approve + amount_mode, либо старые approve_amount / approve_limit
        $amountMode = (string) ($payload['amount_mode'] ?? '');
        if ($action === 'approve') {
            $action = $amountMode === 'open' ? 'approve_limit' : 'approve_amount';
        }

        $principalInn = preg_replace('/\D+/', '', (string) ($payload['principal_inn'] ?? $app['principal_inn'] ?? '')) ?? '';
        $principalCompany = trim((string) ($payload['principal_company_name'] ?? $app['principal_company_name'] ?? ''));
        if ($principalInn === '' || $principalCompany === '') {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Укажите ИНН и компанию принципала'];
        }

        $principalEmail = trim((string) ($payload['principal_email'] ?? $payload['client_email'] ?? $app['principal_email'] ?? ''));

        $approvedAmount = null;
        $approvedLimit = null;
        $workingAmount = null;

        if ($action === 'approve_amount') {
            $approvedAmount = isset($payload['approved_amount'])
                ? (float) $payload['approved_amount']
                : (float) ($app['requested_amount'] ?? $app['amount'] ?? 0);
            if ($approvedAmount <= 0) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Укажите одобряемую сумму'];
            }
            $workingAmount = $approvedAmount;
            $amountMode = 'fixed';
        } elseif ($action === 'approve_limit') {
            $approvedLimit = isset($payload['approved_limit']) ? (float) $payload['approved_limit'] : 0.0;
            if ($approvedLimit <= 0) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Укажите лимит'];
            }
            $workingAmount = $approvedLimit;
            $amountMode = 'open';
        } else {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Неизвестное действие'];
        }

        $existingPrincipalId = (int) ($app['principal_user_id'] ?? 0);
        if ($existingPrincipalId > 0) {
            $clientResult = [
                'ok' => true,
                'user_id' => $existingPrincipalId,
                'created' => false,
                'plain_password' => '',
            ];
            // Обновим email принципала на заявке, если передали
        } else {
            $clientResult = finbuild_find_or_create_principal_client(
                $pdo,
                $principalInn,
                $principalCompany,
                [
                    'email' => $principalEmail,
                    'phone' => (string) ($payload['client_phone'] ?? ''),
                    'first_name' => (string) ($payload['client_first_name'] ?? ''),
                    'last_name' => (string) ($payload['client_last_name'] ?? ''),
                ],
                $reviewerId > 0 ? $reviewerId : null
            );
            if (empty($clientResult['ok'])) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => (string) ($clientResult['error'] ?? 'Не удалось создать клиента')];
            }
        }

        $principalUserId = (int) $clientResult['user_id'];
        $upd = $pdo->prepare(
            'UPDATE applications SET
                principal_inn = ?,
                principal_company_name = ?,
                principal_email = COALESCE(NULLIF(?, \'\'), principal_email),
                principal_user_id = ?,
                company_name = ?,
                inn = ?,
                amount = ?,
                requested_amount = COALESCE(requested_amount, ?),
                approved_amount = ?,
                approved_limit = ?,
                amount_mode = ?,
                intake_status = ?,
                intake_reviewed_at = NOW(),
                intake_reviewed_by = ?,
                status = ?
             WHERE id = ?'
        );
        $requested = $app['requested_amount'] ?? $app['amount'] ?? $workingAmount;
        $upd->execute([
            $principalInn,
            $principalCompany,
            $principalEmail,
            $principalUserId,
            $principalCompany,
            $principalInn,
            $workingAmount,
            $requested,
            $approvedAmount,
            $approvedLimit,
            $amountMode,
            'approved',
            $reviewerId,
            'in_progress',
            $applicationId,
        ]);

        $pdo->commit();

        $plainPassword = (string) ($clientResult['plain_password'] ?? '');
        $created = !empty($clientResult['created']);
        if ($created && $plainPassword !== '') {
            $emailForCreds = $principalEmail;
            if ($emailForCreds === '') {
                $eStmt = $pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
                $eStmt->execute([$principalUserId]);
                $emailForCreds = (string) ($eStmt->fetchColumn() ?: '');
            }
            finbuild_intake_store_created_client_credentials($applicationId, $emailForCreds, $plainPassword);
        }

        return [
            'ok' => true,
            'principal_user_id' => $principalUserId,
            'created_client' => $created,
            'plain_password' => $plainPassword,
            'client_email' => $principalEmail !== '' ? $principalEmail : (string) ($app['principal_email'] ?? ''),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
