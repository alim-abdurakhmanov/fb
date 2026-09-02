<?php
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authorized']);
    exit;
}

$application_id = $_GET['application_id'] ?? 0;

if (!$application_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid application ID']);
    exit;
}

$pdo = getPDO();

$user = getCurrentUser();
if (($user['role'] ?? '') === 'bank') {
    require_once __DIR__ . '/includes/bank_portal.php';
    $bankCode = finbank_user_bank_code($pdo, $user);
    if ($bankCode === null || !finbank_bank_can_view_application($pdo, (int) $application_id, $bankCode)) {
        echo json_encode(['success' => false, 'error' => 'Доступ запрещён']);
        exit;
    }
}

// Получаем ИНН из заявки
$stmt = $pdo->prepare("SELECT inn FROM applications WHERE id = ?");
$stmt->execute([$application_id]);
$application = $stmt->fetch();

if (!$application || empty($application['inn'])) {
    echo json_encode(['success' => false, 'error' => 'Application or INN not found']);
    exit;
}

$inn = $application['inn'];

// Функция для получения данных из API Checko
function getApiData($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FAILONERROR    => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($response === false) {
        return ['success' => false, 'error' => $error];
    }
    
    if ($httpCode !== 200) {
        return ['success' => false, 'error' => "HTTP Error: $httpCode"];
    }
    
    return json_decode($response, true);
}

try {
    $apiKey = "BXLApjLYuoc0nGvM";
    $baseUrl = "https://api.checko.ru/v2/";
    
    // Получаем данные из всех источников
    $companyData = getApiData("$baseUrl/company?key=$apiKey&inn=$inn");
    $financeData = getApiData("$baseUrl/finances?key=$apiKey&inn=$inn");
    $enforcementsData = getApiData("$baseUrl/enforcements?key=$apiKey&inn=$inn");
    $lawsuitsData = getApiData("$baseUrl/legal-cases?key=$apiKey&inn=$inn");
    
    // Проверяем на ошибки
    $errors = [];
    if (isset($companyData['success']) && !$companyData['success']) {
        $errors[] = 'Company data: ' . ($companyData['error'] ?? 'Unknown error');
        $companyData = ['data' => []];
    }
    
    if (isset($financeData['success']) && !$financeData['success']) {
        $errors[] = 'Finance data: ' . ($financeData['error'] ?? 'Unknown error');
        $financeData = ['data' => []];
    }
    
    if (isset($enforcementsData['success']) && !$enforcementsData['success']) {
        $errors[] = 'Enforcements data: ' . ($enforcementsData['error'] ?? 'Unknown error');
        $enforcementsData = ['data' => []];
    }
    
    if (isset($lawsuitsData['success']) && !$lawsuitsData['success']) {
        $errors[] = 'Lawsuits data: ' . ($lawsuitsData['error'] ?? 'Unknown error');
        $lawsuitsData = ['data' => []];
    }
    
    // Формируем ответ
    $result = [
        'success' => true,
        'inn' => $inn,
        'data' => [
            'company' => is_array($companyData) ? $companyData : ['data' => []],
            'finance' => is_array($financeData) ? $financeData : ['data' => []],
            'enforcements' => is_array($enforcementsData) ? $enforcementsData : ['data' => []],
            'lawsuits' => is_array($lawsuitsData) ? $lawsuitsData : ['data' => []]
        ]
    ];
    
    if (!empty($errors)) {
        $result['warnings'] = $errors;
    }
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>