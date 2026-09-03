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
 * Одобрить или отклонить intake-заявку.
 *
 * @param array{
 *   action: 'approve_amount'|'approve_limit'|'reject',
 *   principal_inn?: string,
 *   principal_company_name?: string,
 *   approved_amount?: float|null,
 *   approved_limit?: float|null,
 *   client_email?: string,
 *   client_phone?: string,
 *   client_first_name?: string,
 *   client_last_name?: string,
 *   reject_reason?: string
 * } $payload
 * @return array{ok:bool,error?:string,principal_user_id?:int,created_client?:bool,plain_password?:string}
 */
function finbuild_intake_review_application(PDO $pdo, int $applicationId, array $reviewer, array $payload): array
{
    if (!function_exists('finbuild_is_manager') || !finbuild_is_manager((string) ($reviewer['role'] ?? ''))) {
        return ['ok' => false, 'error' => 'Недостаточно прав'];
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
        if (($app['intake_status'] ?? '') !== 'pending_review') {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Заявка уже рассмотрена или не является запросом заказчика'];
        }

        $action = (string) ($payload['action'] ?? '');
        $reviewerId = (int) ($reviewer['id'] ?? 0);

        if ($action === 'reject') {
            $upd = $pdo->prepare(
                'UPDATE applications SET intake_status = ?, intake_reviewed_at = NOW(), intake_reviewed_by = ?, status = ? WHERE id = ?'
            );
            $upd->execute(['rejected', $reviewerId, 'failed', $applicationId]);
            $pdo->commit();
            return ['ok' => true];
        }

        $principalInn = preg_replace('/\D+/', '', (string) ($payload['principal_inn'] ?? $app['principal_inn'] ?? '')) ?? '';
        $principalCompany = trim((string) ($payload['principal_company_name'] ?? $app['principal_company_name'] ?? ''));
        if ($principalInn === '' || $principalCompany === '') {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Укажите ИНН и компанию принципала'];
        }

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
        } elseif ($action === 'approve_limit') {
            $approvedLimit = isset($payload['approved_limit']) ? (float) $payload['approved_limit'] : 0.0;
            if ($approvedLimit <= 0) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Укажите лимит'];
            }
            $workingAmount = $approvedLimit;
        } else {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Неизвестное действие'];
        }

        $clientResult = finbuild_find_or_create_principal_client(
            $pdo,
            $principalInn,
            $principalCompany,
            [
                'email' => (string) ($payload['client_email'] ?? ''),
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

        $principalUserId = (int) $clientResult['user_id'];
        $upd = $pdo->prepare(
            'UPDATE applications SET
                principal_inn = ?,
                principal_company_name = ?,
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
        $amountMode = $action === 'approve_limit' ? 'open' : 'fixed';
        $requested = $app['requested_amount'] ?? $app['amount'] ?? $workingAmount;
        $upd->execute([
            $principalInn,
            $principalCompany,
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
        return [
            'ok' => true,
            'principal_user_id' => $principalUserId,
            'created_client' => !empty($clientResult['created']),
            'plain_password' => (string) ($clientResult['plain_password'] ?? ''),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
