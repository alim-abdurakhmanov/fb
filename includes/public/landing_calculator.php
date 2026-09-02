<?php

declare(strict_types=1);

/**
 * Публичный калькулятор подбора БГ (как product_selection.php, только банковские гарантии).
 * Скрываем банки с сегментом «КОРП» и запись «Другой банк».
 */

require_once __DIR__ . '/landing_data.php';

/** @param mixed $jsonString */
function finbuild_landing_format_catalog_json_field($jsonString): string
{
    if ($jsonString === null || $jsonString === '' || $jsonString === '[]') {
        return '';
    }

    $array = json_decode((string) $jsonString, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($array)) {
        return (string) $jsonString;
    }

    return implode(', ', $array);
}

/**
 * @param array<string, mixed> $post
 * @return array{searched: true, product_type: 'bg', products: list<array<string, mixed>>, params: array<string, string>, error: ?string}
 */
function finbuild_landing_execute_calculator(PDO $pdo, array $post): array
{
    $params = [
        'bg_type' => trim((string) ($post['bg_type'] ?? '')),
        'fz_type' => trim((string) ($post['fz_type'] ?? '')),
        'amount' => trim((string) ($post['amount'] ?? '')),
        'term' => trim((string) ($post['term'] ?? '')),
    ];

    $bgType = $params['bg_type'];
    $fzType = $params['fz_type'];
    $amount = floatval($params['amount'] !== '' ? $params['amount'] : 0);
    $term = intval($params['term'] !== '' ? $params['term'] : 0);

    $sql = 'SELECT * FROM bank_products WHERE 1=1';
    $bind = [];

    if ($amount > 0) {
        $sql .= ' AND (max_amount >= ? OR max_amount IS NULL)';
        $bind[] = $amount;
    }

    if ($term > 0) {
        $sql .= ' AND (max_term >= ? OR max_term IS NULL)';
        $bind[] = $term;
    }

    if ($bgType !== '') {
        $sql .= " AND (JSON_CONTAINS(bg_types, ?) OR bg_types IS NULL OR bg_types = '[]')";
        $bind[] = json_encode($bgType);
    }

    if ($fzType !== '') {
        $sql .= " AND (JSON_CONTAINS(fz_types, ?) OR fz_types IS NULL OR fz_types = '[]')";
        $bind[] = json_encode($fzType);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $groupedProducts = [];
    foreach ($rows as $product) {
        $bankName = (string) ($product['bank_name'] ?? '');
        if (finbuild_landing_bank_name_excluded_from_public($bankName)) {
            continue;
        }

        if (!isset($groupedProducts[$bankName])) {
            $groupedProducts[$bankName] = $product;
        } else {
            $currentAmount = (float) ($groupedProducts[$bankName]['max_amount'] ?? 0);
            $newAmount = (float) ($product['max_amount'] ?? 0);

            if ($newAmount > 0 && ($currentAmount == 0 || $newAmount < $currentAmount)) {
                $groupedProducts[$bankName] = $product;
            }
        }
    }

    $products = array_values($groupedProducts);

    usort(
        $products,
        static function (array $a, array $b): int {
            return strcasecmp((string) ($a['bank_name'] ?? ''), (string) ($b['bank_name'] ?? ''));
        }
    );

    return [
        'searched' => true,
        'product_type' => 'bg',
        'products' => $products,
        'params' => $params,
        'error' => null,
    ];
}
