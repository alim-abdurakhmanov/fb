<?php
/**
 * Единый клиент Checko API v2.
 */
declare(strict_types=1);

function finbuild_checko_api_key(): string
{
    if (defined('CHECKO_API_KEY') && is_string(CHECKO_API_KEY) && trim(CHECKO_API_KEY) !== '') {
        return trim(CHECKO_API_KEY);
    }
    return '';
}

/**
 * @return array{ok:bool,http_code:int,data?:array,error?:string,raw?:mixed}
 */
function finbuild_checko_request(string $endpoint, array $params = [], int $timeout = 15): array
{
    $key = finbuild_checko_api_key();
    if ($key === '') {
        return ['ok' => false, 'http_code' => 0, 'error' => 'CHECKO_API_KEY не задан'];
    }

    $endpoint = ltrim($endpoint, '/');
    $params = array_merge(['key' => $key], $params);
    $url = 'https://api.checko.ru/v2/' . $endpoint . '?' . http_build_query($params);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'http_code' => $httpCode, 'error' => $curlError !== '' ? $curlError : 'Ошибка сети Checko'];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'http_code' => $httpCode, 'error' => 'Некорректный JSON от Checko'];
    }

    if (isset($decoded['meta']['status']) && $decoded['meta']['status'] === 'error') {
        return [
            'ok' => false,
            'http_code' => $httpCode,
            'error' => (string) ($decoded['meta']['message'] ?? 'Ошибка Checko'),
            'raw' => $decoded,
        ];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return [
            'ok' => false,
            'http_code' => $httpCode,
            'error' => 'HTTP ' . $httpCode,
            'raw' => $decoded,
        ];
    }

    return [
        'ok' => true,
        'http_code' => $httpCode,
        'data' => is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded,
        'raw' => $decoded,
    ];
}

/**
 * Пакет данных для скоринга / аналитики.
 *
 * @return array{
 *   inn:string,
 *   company:?array,
 *   finance:?array,
 *   enforcements:?array,
 *   lawsuits:?array,
 *   warnings:list<string>
 * }
 */
function finbuild_checko_fetch_intelligence(string $inn, bool $extendedFinance = true): array
{
    $inn = preg_replace('/\D+/', '', $inn) ?? '';
    $warnings = [];

    $companyRes = finbuild_checko_request('company', ['inn' => $inn]);
    // Для скоринга достаточно обычной отчётности (числа). Extended — fallback.
    $financeRes = finbuild_checko_request('finances', ['inn' => $inn]);
    if (!$financeRes['ok'] && $extendedFinance) {
        $financeRes = finbuild_checko_request('finances', ['inn' => $inn, 'extended' => 'true']);
    }
    $enfRes = finbuild_checko_request('enforcements', ['inn' => $inn]);
    $lawRes = finbuild_checko_request('legal-cases', ['inn' => $inn]);

    if (!$companyRes['ok']) {
        $warnings[] = 'company: ' . ($companyRes['error'] ?? 'error');
    }
    if (!$financeRes['ok']) {
        $warnings[] = 'finances: ' . ($financeRes['error'] ?? 'error');
    }
    if (!$enfRes['ok']) {
        $warnings[] = 'enforcements: ' . ($enfRes['error'] ?? 'error');
    }
    if (!$lawRes['ok']) {
        $warnings[] = 'legal-cases: ' . ($lawRes['error'] ?? 'error');
    }

    return [
        'inn' => $inn,
        'company' => $companyRes['ok'] ? ($companyRes['data'] ?? null) : null,
        'finance' => $financeRes['ok'] ? ($financeRes['data'] ?? null) : null,
        'enforcements' => $enfRes['ok'] ? ($enfRes['data'] ?? null) : null,
        'lawsuits' => $lawRes['ok'] ? ($lawRes['data'] ?? null) : null,
        'warnings' => $warnings,
    ];
}
