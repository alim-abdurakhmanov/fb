<?php
/**
 * CRUD продуктов каталога (bank_products / credit_products) — только для менеджеров.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !finbuild_can('admin.products')) {
    echo json_encode(['success' => false, 'error' => 'Доступ запрещён'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_POST['action'] ?? '';

function json_arr_from_text(string $raw): string
{
    $parts = preg_split('/[\r\n,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
    $vals = [];
    foreach ($parts as $p) {
        $t = trim($p);
        if ($t !== '') {
            $vals[] = $t;
        }
    }
    return json_encode(array_values($vals), JSON_UNESCAPED_UNICODE);
}

/** Пустая строка → null (для необязательных полей в БД). */
function product_nv(?string $v): ?string
{
    $v = trim((string) $v);
    return $v === '' ? null : $v;
}

try {
    $pdo = getPDO();

    if ($action === 'delete') {
        $type = $_POST['type'] ?? '';
        $id = (int) ($_POST['id'] ?? 0);
        if (!in_array($type, ['bg', 'credit'], true) || $id < 1) {
            echo json_encode(['success' => false, 'error' => 'Неверные параметры'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $pt = $type === 'bg' ? 'bg' : 'credit';
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM application_products WHERE product_id = ? AND product_type = ?');
        $cnt->execute([$id, $pt]);
        if ((int) $cnt->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'error' => 'Продукт уже добавлен в заявки — удаление невозможно'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $table = $type === 'bg' ? 'bank_products' : 'credit_products';
        $pdo->prepare("DELETE FROM {$table} WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action !== 'save') {
        echo json_encode(['success' => false, 'error' => 'Неизвестное действие'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $type = $_POST['type'] ?? '';
    if (!in_array($type, ['bg', 'credit'], true)) {
        echo json_encode(['success' => false, 'error' => 'Укажите тип продукта'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $id = (int) ($_POST['id'] ?? 0);

    $bank_name = trim((string) ($_POST['bank_name'] ?? ''));
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($bank_name === '' || $name === '') {
        echo json_encode(['success' => false, 'error' => 'Заполните банк и название продукта'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $features = trim((string) ($_POST['features'] ?? ''));
    $features = $features === '' ? null : $features;

    if ($type === 'bg') {
        $max_amount = trim((string) ($_POST['max_amount'] ?? ''));
        $max_amount = $max_amount === '' ? null : (float) str_replace([' ', ','], ['', '.'], $max_amount);
        $max_term = trim((string) ($_POST['max_term'] ?? ''));
        $max_term = $max_term === '' ? null : (int) $max_term;
        $bg_types = json_arr_from_text((string) ($_POST['bg_types'] ?? ''));
        $fz_types = json_arr_from_text((string) ($_POST['fz_types'] ?? ''));

        $limit_amount = product_nv($_POST['limit_amount'] ?? null);
        $company_age = product_nv($_POST['company_age'] ?? null);
        $spfs = isset($_POST['spfs_yes']) ? 'Да' : null;
        $third_party_payment = isset($_POST['third_party_yes']) ? 'Да' : null;
        $customers = product_nv($_POST['customers'] ?? null);
        $works_with_individual_entrepreneurs = isset($_POST['works_ie_yes']) ? 'Да' : null;
        $works_with_state_enterprises = isset($_POST['works_gos_yes']) ? 'Да' : null;
        $stop_regions_principal = product_nv($_POST['stop_regions_principal'] ?? null);
        $stop_regions_beneficiary = product_nv($_POST['stop_regions_beneficiary'] ?? null);
        $stop_factors = product_nv($_POST['stop_factors'] ?? null);
        $product_passport_link = product_nv($_POST['product_passport_link'] ?? null);
        $curator = product_nv($_POST['curator_bg'] ?? null);
        $platform_access = product_nv($_POST['platform_access_bg'] ?? null);

        $bgSql = 'bank_name=?, name=?, max_amount=?, max_term=?, bg_types=?, fz_types=?, features=?,
            limit_amount=?, company_age=?, spfs=?, third_party_payment=?, customers=?,
            works_with_individual_entrepreneurs=?, works_with_state_enterprises=?,
            stop_regions_principal=?, stop_regions_beneficiary=?, stop_factors=?,
            product_passport_link=?, curator=?, platform_access=?';

        $bgParams = [
            $bank_name, $name, $max_amount, $max_term, $bg_types, $fz_types, $features,
            $limit_amount, $company_age, $spfs, $third_party_payment, $customers,
            $works_with_individual_entrepreneurs, $works_with_state_enterprises,
            $stop_regions_principal, $stop_regions_beneficiary, $stop_factors,
            $product_passport_link, $curator, $platform_access,
        ];

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE bank_products SET {$bgSql} WHERE id=?");
            $stmt->execute(array_merge($bgParams, [$id]));
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO bank_products (bank_name, name, max_amount, max_term, bg_types, fz_types, features,
                    limit_amount, company_age, spfs, third_party_payment, customers,
                    works_with_individual_entrepreneurs, works_with_state_enterprises,
                    stop_regions_principal, stop_regions_beneficiary, stop_factors,
                    product_passport_link, curator, platform_access)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute($bgParams);
            $id = (int) $pdo->lastInsertId();
        }
    } else {
        $amount = trim((string) ($_POST['amount'] ?? ''));
        $amount = $amount === '' ? null : (float) str_replace([' ', ','], ['', '.'], $amount);
        $termRaw = trim((string) ($_POST['term'] ?? ''));
        if ($termRaw === '') {
            $term = null;
        } elseif (is_numeric($termRaw)) {
            $term = (strpos($termRaw, '.') !== false) ? (float) $termRaw : (int) $termRaw;
        } else {
            $term = $termRaw;
        }
        $interest_rate = product_nv($_POST['interest_rate'] ?? null);

        // Поле «Вид» в БД — type; в форме credit_kind (не путать со скрытым type=bg|credit)
        $ptype = product_nv($_POST['credit_kind'] ?? null);
        $credit_line_type = product_nv($_POST['credit_line_type'] ?? null);
        $tranch_term = product_nv($_POST['tranch_term'] ?? null);
        $collateral = product_nv($_POST['collateral'] ?? null);
        $individual_entrepreneur = isset($_POST['individual_entrepreneur']) ? 1 : 0;
        $documents = product_nv($_POST['documents'] ?? null);
        $stops = product_nv($_POST['stops'] ?? null);
        $consideration_term = product_nv($_POST['consideration_term'] ?? null);
        $comments = product_nv($_POST['comments'] ?? null);
        $passport_link = product_nv($_POST['passport_link'] ?? null);
        $curator_cr = product_nv($_POST['curator_credit'] ?? null);
        $work_stages = product_nv($_POST['work_stages'] ?? null);
        $platform_access_cr = product_nv($_POST['platform_access_credit'] ?? null);

        $crSql = 'bank_name=?, name=?, amount=?, term=?, interest_rate=?, features=?,
            type=?, credit_line_type=?, tranch_term=?, collateral=?, individual_entrepreneur=?,
            documents=?, stops=?, consideration_term=?, comments=?,
            passport_link=?, curator=?, work_stages=?, platform_access=?';

        $crParams = [
            $bank_name, $name, $amount, $term, $interest_rate, $features,
            $ptype, $credit_line_type, $tranch_term, $collateral, $individual_entrepreneur,
            $documents, $stops, $consideration_term, $comments,
            $passport_link, $curator_cr, $work_stages, $platform_access_cr,
        ];

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE credit_products SET {$crSql} WHERE id=?");
            $stmt->execute(array_merge($crParams, [$id]));
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO credit_products (bank_name, name, amount, term, interest_rate, features,
                    type, credit_line_type, tranch_term, collateral, individual_entrepreneur,
                    documents, stops, consideration_term, comments,
                    passport_link, curator, work_stages, platform_access)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute($crParams);
            $id = (int) $pdo->lastInsertId();
        }
    }

    echo json_encode(['success' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
