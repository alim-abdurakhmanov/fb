<?php
header('Content-Type: application/json');

$inn = $_GET['inn'] ?? '';

if (empty($inn) || !preg_match('/^\d{10,12}$/', $inn)) {
    echo json_encode(['success' => false, 'error' => 'Некорректный ИНН']);
    exit;
}

function fetchCheckoData($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        return json_decode($response, true);
    }

    return null;
}

try {
    $apiKey = "BXLApjLYuoc0nGvM";

    $companyData = fetchCheckoData("https://api.checko.ru/v2/company?key=$apiKey&inn=$inn");
    if ($companyData && isset($companyData['data'])) {
        $companyName = '';
        if (!empty($companyData['data']['НаимСокр'])) {
            $companyName = $companyData['data']['НаимСокр'];
        } elseif (!empty($companyData['data']['НаимПолн'])) {
            $companyName = $companyData['data']['НаимПолн'];
        }

        if (!empty($companyName)) {
            echo json_encode(['success' => true, 'name' => $companyName, 'type' => 'company']);
            exit;
        }
    }

    $ipData = fetchCheckoData("https://api.checko.ru/v2/person?key=$apiKey&inn=$inn");
    if ($ipData && isset($ipData['data'])) {
        $person = isset($ipData['data'][0]) ? $ipData['data'][0] : $ipData['data'];
        if (!empty($person['ФИО'])) {
            echo json_encode(['success' => true, 'name' => 'ИП ' . $person['ФИО'], 'type' => 'ip']);
            exit;
        }
    }

    echo json_encode(['success' => false, 'error' => 'Организация или ИП не найдены']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
