<?php
require_once 'config.php';

/**
 * Нормализация цены контракта из ответа Checko.
 */
function parseCheckoContractPrice($raw) {
    if ($raw === null || $raw === '') {
        return null;
    }
    if (is_numeric($raw)) {
        return (float)$raw;
    }
    $s = preg_replace('/\s+/u', '', (string)$raw);
    $s = str_replace(',', '.', $s);
    return is_numeric($s) ? (float)$s : null;
}

/**
 * Карточка контракта Checko по регистрационному номеру.
 *
 * @return array{success:bool,error?:string,purchase_link?:string,contract_price?:?float,contract_subject?:string,customer_inn?:string,customer_name?:string,reg_number?:string}
 */
function checko_fetch_contract_by_number(string $number): array {
    $number = preg_replace('/\D/', '', $number);
    if (strlen($number) < 10 || strlen($number) > 30) {
        return ['success' => false, 'error' => 'Некорректный регистрационный номер контракта'];
    }

    try {
        $apiKey = CHECKO_API_KEY;
        $url = 'https://api.checko.ru/v2/contracts?key=' . urlencode($apiKey) . '&number=' . urlencode($number);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return ['success' => false, 'error' => 'Сервис Checko недоступен'];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return ['success' => false, 'error' => 'Некорректный ответ API'];
        }

        if (isset($decoded['meta']['status']) && $decoded['meta']['status'] === 'error') {
            return ['success' => false, 'error' => $decoded['meta']['message'] ?? 'Ошибка запроса к Checko'];
        }

        $block = $decoded['data'] ?? null;
        if (!is_array($block)) {
            return ['success' => false, 'error' => 'Контракт с таким номером не найден в ЕИС'];
        }

        $row = null;
        if (!empty($block['Записи']) && is_array($block['Записи'])) {
            if (count($block['Записи']) === 0) {
                return ['success' => false, 'error' => 'Контракт с таким номером не найден в ЕИС'];
            }
            $row = $block['Записи'][0];
        } elseif (isset($block['РегНомер']) || isset($block['СтрЕИС']) || isset($block['Заказ'])) {
            $row = $block;
        } else {
            return ['success' => false, 'error' => 'Контракт с таким номером не найден в ЕИС'];
        }

        $link = isset($row['СтрЕИС']) ? trim((string)$row['СтрЕИС']) : '';
        $price = parseCheckoContractPrice($row['Цена'] ?? null);

        $zakaz = $row['Заказ'] ?? [];
        $customerInn = isset($zakaz['ИНН']) ? preg_replace('/\D/', '', (string)$zakaz['ИНН']) : '';
        $customerName = '';
        if (!empty($zakaz['НаимСокр'])) {
            $customerName = trim((string)$zakaz['НаимСокр']);
        } elseif (!empty($zakaz['НаимПолн'])) {
            $customerName = trim((string)$zakaz['НаимПолн']);
        }

        $objects = $row['Объекты'] ?? [];
        $subjectParts = [];
        if (is_array($objects)) {
            foreach ($objects as $obj) {
                if (!empty($obj['Наим'])) {
                    $subjectParts[] = trim((string)$obj['Наим']);
                }
            }
        }
        $contractSubject = implode('; ', $subjectParts);

        $regNom = isset($row['РегНомер']) ? (string)$row['РегНомер'] : '';

        return [
            'success' => true,
            'purchase_link' => $link,
            'contract_price' => $price,
            'contract_subject' => $contractSubject,
            'customer_inn' => $customerInn,
            'customer_name' => $customerName,
            'reg_number' => $regNom,
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authorized']);
    exit;
}

// POST: записать данные контракта в заявку (карточка заявки, менеджер)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['application_id'])) {
    if (!finbuild_is_manager((string) ($_SESSION['role'] ?? ''))) {
        echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
        exit;
    }

    $applicationId = (int)$_POST['application_id'];
    $number = preg_replace('/\D/', '', (string)($_POST['number'] ?? ''));

    if ($applicationId < 1 || strlen($number) < 10) {
        echo json_encode(['success' => false, 'error' => 'Укажите номер закупки (не менее 10 цифр).']);
        exit;
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id, product_type FROM applications WHERE id = ?');
    $stmt->execute([$applicationId]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$app || $app['product_type'] !== 'bg') {
        echo json_encode(['success' => false, 'error' => 'Заявка не найдена или не банковская гарантия']);
        exit;
    }

    $data = checko_fetch_contract_by_number($number);
    if (empty($data['success'])) {
        echo json_encode(['success' => false, 'error' => $data['error'] ?? 'Ошибка запроса к Checko']);
        exit;
    }

    $purchaseNumber = !empty($data['reg_number']) ? (string)$data['reg_number'] : $number;
    $purchaseLink = isset($data['purchase_link']) ? trim((string)$data['purchase_link']) : '';
    $contractSubject = isset($data['contract_subject']) ? trim((string)$data['contract_subject']) : '';
    $contractPrice = $data['contract_price'] ?? null;
    $customerInn = isset($data['customer_inn']) ? trim((string)$data['customer_inn']) : '';
    $customerName = isset($data['customer_name']) ? trim((string)$data['customer_name']) : '';

    $upd = $pdo->prepare('
        UPDATE applications SET
            purchase_number = ?,
            purchase_link = ?,
            contract_subject = ?,
            contract_price = ?,
            customer_inn = ?,
            customer_name = ?
        WHERE id = ?
    ');
    $upd->execute([
        $purchaseNumber,
        $purchaseLink !== '' ? $purchaseLink : null,
        $contractSubject !== '' ? $contractSubject : null,
        $contractPrice,
        $customerInn !== '' ? $customerInn : null,
        $customerName !== '' ? $customerName : null,
        $applicationId,
    ]);

    echo json_encode([
        'success' => true,
        'purchase_number' => $purchaseNumber,
        'purchase_link' => $purchaseLink,
        'contract_subject' => $contractSubject,
        'contract_price' => $contractPrice,
        'customer_inn' => $customerInn,
        'customer_name' => $customerName,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// GET: контракт по номеру (форма создания заявки и др.)
$number = isset($_GET['number']) ? preg_replace('/\D/', '', (string)$_GET['number']) : '';

if ($number !== '') {
    $result = checko_fetch_contract_by_number($number);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

$inn = $_GET['inn'] ?? '';

if (empty($inn) || !preg_match('/^\d{10,12}$/', $inn)) {
    echo json_encode(['success' => false, 'error' => 'Некорректный ИНН']);
    exit;
}

// Функция для получения данных компании по ИНН через API Checko
function getCompanyDataByInn($inn) {
    $apiKey = CHECKO_API_KEY;
    $companyApiUrl = "https://api.checko.ru/v2/company?key=$apiKey&inn=$inn";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $companyApiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['data']) && !empty($data['data'])) {
            $companyName = '';
            if (isset($data['data']['НаимСокр']) && !empty($data['data']['НаимСокр'])) {
                $companyName = $data['data']['НаимСокр'];
            } elseif (isset($data['data']['НаимПолн'])) {
                $companyName = $data['data']['НаимПолн'];
            }

            if (!empty($companyName)) {
                return [
                    'type' => 'company',
                    'name' => $companyName,
                    'full_data' => $data['data'],
                ];
            }
        }
    }

    return null;
}

// Функция для получения данных ИП по ИНН через API Checko
function getIndividualEntrepreneurDataByInn($inn) {
    $apiKey = CHECKO_API_KEY;
    $personApiUrl = "https://api.checko.ru/v2/person?key=$apiKey&inn=$inn";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $personApiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);

        if (isset($data['data']) && !empty($data['data'])) {
            $ipData = null;

            if (isset($data['data'][0])) {
                $ipData = $data['data'][0];
            } else {
                $ipData = $data['data'];
            }

            if (isset($ipData['ФИО']) && !empty($ipData['ФИО'])) {
                return [
                    'type' => 'ip',
                    'name' => 'ИП ' . $ipData['ФИО'],
                    'fio' => $ipData['ФИО'],
                    'full_data' => $ipData,
                ];
            }
        }
    }

    return null;
}

try {
    $companyData = getCompanyDataByInn($inn);

    if ($companyData) {
        echo json_encode([
            'success' => true,
            'name' => $companyData['name'],
            'type' => $companyData['type'],
            'full_data' => $companyData['full_data'],
        ]);
    } else {
        $ipData = getIndividualEntrepreneurDataByInn($inn);

        if ($ipData) {
            echo json_encode([
                'success' => true,
                'name' => $ipData['name'],
                'type' => $ipData['type'],
                'fio' => $ipData['fio'] ?? '',
                'full_data' => $ipData['full_data'],
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Organization or individual entrepreneur not found',
            ]);
        }
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
