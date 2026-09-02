<?php
/**
 * Справочник банков с ЛК (multi-tenant по bank_code).
 */
declare(strict_types=1);

/** @return array<string, array{code: string, name: string, label: string, bank_names: list<string>}> */
function finbank_portals_registry(): array
{
    return [
        'noosfera' => [
            'code' => 'noosfera',
            'name' => 'Ноосфера',
            'label' => 'Ноосфера',
            'bank_names' => ['Ноосфера'],
        ],
        'alfa' => [
            'code' => 'alfa',
            'name' => 'Альфа',
            'label' => 'Альфа-Банк',
            'bank_names' => ['Альфа', 'Альфа-Банк', 'АО Альфа-Банк'],
        ],
    ];
}

function finbank_portal_by_code(string $code): ?array
{
    $code = trim($code);
    if ($code === '') {
        return null;
    }
    $all = finbank_portals_registry();
    return $all[$code] ?? null;
}

function finbank_portal_resolve_bank_name(string $bankName): ?array
{
    $normalized = mb_strtolower(trim($bankName), 'UTF-8');
    if ($normalized === '') {
        return null;
    }
    foreach (finbank_portals_registry() as $portal) {
        foreach ($portal['bank_names'] as $alias) {
            if (mb_strtolower(trim($alias), 'UTF-8') === $normalized) {
                return $portal;
            }
        }
    }
    return null;
}

function finbank_portal_code_from_product_row(array $row): ?string
{
    if (($row['product_type'] ?? '') !== 'bg') {
        return null;
    }
    $portal = finbank_portal_resolve_bank_name((string) ($row['bank_name'] ?? ''));
    return $portal['code'] ?? null;
}

function finbank_user_bank_code(PDO $pdo, ?array $user): ?string
{
    if (($user['role'] ?? '') !== 'bank') {
        return null;
    }
    $code = trim((string) ($user['bank_code'] ?? ''));
    if ($code === '' && isset($user['id'])) {
        try {
            $stmt = $pdo->prepare('SELECT bank_code FROM users WHERE id = ? AND role = ? LIMIT 1');
            $stmt->execute([(int) $user['id'], 'bank']);
            $fromDb = $stmt->fetchColumn();
            if ($fromDb !== false) {
                $code = trim((string) $fromDb);
            }
        } catch (PDOException) {
            // колонка bank_code появится после миграции
        }
    }
    if ($code !== '' && finbank_portal_by_code($code) !== null) {
        return $code;
    }
    if ($code === '') {
        return 'noosfera';
    }
    return null;
}

function finbank_user_bank_label(PDO $pdo, ?array $user): string
{
    $code = finbank_user_bank_code($pdo, $user);
    if ($code !== null) {
        $portal = finbank_portal_by_code($code);
        if ($portal !== null) {
            return (string) $portal['label'];
        }
    }
    $company = trim((string) ($user['company_name'] ?? ''));
    return $company !== '' ? $company : 'Банк';
}
