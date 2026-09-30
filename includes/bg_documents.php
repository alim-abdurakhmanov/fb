<?php
/**
 * Генерация DOCX (Решение / Предложение / Договор) при статусе «БГ выдана».
 * Документы доступны только в ЛК банка.
 */
declare(strict_types=1);

require_once __DIR__ . '/bank_portal.php';
require_once __DIR__ . '/bank_methodology/store.php';
require_once __DIR__ . '/upload_access.php';

/** Сгенерированные DOCX и автозаполнение — только для портала Камкомбанка. */
function finbank_bg_docs_enabled_for_bank_code(?string $bankCode): bool
{
    return finbank_portal_is_kamcom($bankCode);
}

function finbank_bg_docs_autoload(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $autoload = rtrim((string) FINBUILD_ROOT, "/\\") . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }
    $done = true;
}

function finbank_bg_docs_ensure_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `application_product_bank_case_generated_docs` (
          `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
          `bank_case_id` int UNSIGNED NOT NULL,
          `doc_type` varchar(32) NOT NULL,
          `file_path` varchar(500) NOT NULL,
          `original_name` varchar(255) NOT NULL,
          `file_size` int UNSIGNED NOT NULL DEFAULT 0,
          `file_type` varchar(50) NOT NULL DEFAULT 'word',
          `generated_by` int UNSIGNED NULL,
          `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_case_doc_type` (`bank_case_id`, `doc_type`),
          KEY `idx_bg_docs_case` (`bank_case_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $done = true;
}

/** @return list<string> */
function finbank_bg_doc_types(): array
{
    return ['decision', 'offer', 'agreement'];
}

function finbank_bg_doc_label(string $docType): string
{
    return match ($docType) {
        'decision' => 'Решение',
        'offer' => 'Предложение',
        'agreement' => 'Договор о выдаче независимой (банковской) гарантии',
        default => $docType,
    };
}

function finbank_bg_doc_template_path(string $docType): ?string
{
    $root = rtrim((string) FINBUILD_ROOT, "/\\");
    $rel = match ($docType) {
        'decision' => 'templates/bg/common/decision.docx',
        'offer' => 'templates/bg/common/offer.docx',
        'agreement' => 'templates/bg/variant/agreement.docx',
        default => null,
    };
    if ($rel === null) {
        return null;
    }
    $abs = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    return is_file($abs) ? $abs : null;
}

/** @return list<string> */
function finbank_bg_month_genitive(): array
{
    return [
        1 => 'января',
        2 => 'февраля',
        3 => 'марта',
        4 => 'апреля',
        5 => 'мая',
        6 => 'июня',
        7 => 'июля',
        8 => 'августа',
        9 => 'сентября',
        10 => 'октября',
        11 => 'ноября',
        12 => 'декабря',
    ];
}

function finbank_bg_parse_date(mixed $value): ?DateTimeImmutable
{
    if ($value === null) {
        return null;
    }
    $s = trim((string) $value);
    if ($s === '' || $s === '0000-00-00' || str_starts_with($s, '0000-00-00')) {
        return null;
    }
    try {
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
            return new DateTimeImmutable(substr($s, 0, 10));
        }
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $s, $m)) {
            return new DateTimeImmutable($m[3] . '-' . $m[2] . '-' . $m[1]);
        }
        return new DateTimeImmutable($s);
    } catch (Throwable) {
        return null;
    }
}

function finbank_bg_format_date_dotted(?DateTimeImmutable $dt): string
{
    return $dt ? $dt->format('d.m.Y') : '';
}

/**
 * @return array{day:string,month:string,year:string}
 */
function finbank_bg_date_parts(?DateTimeImmutable $dt): array
{
    if (!$dt) {
        return ['day' => '', 'month' => '', 'year' => ''];
    }
    $months = finbank_bg_month_genitive();
    $m = (int) $dt->format('n');
    return [
        'day' => $dt->format('d'),
        'month' => $months[$m] ?? '',
        'year' => $dt->format('Y'),
    ];
}

function finbank_bg_format_amount_digits(mixed $amount): string
{
    if ($amount === null || $amount === '') {
        return '';
    }
    if (!is_numeric($amount)) {
        return trim((string) $amount);
    }
    return number_format((float) $amount, 2, ',', ' ');
}

function finbank_bg_amount_kopecks(mixed $amount): string
{
    if ($amount === null || $amount === '' || !is_numeric($amount)) {
        return '';
    }
    $cents = (int) round((abs((float) $amount) - floor(abs((float) $amount))) * 100);
    return str_pad((string) $cents, 2, '0', STR_PAD_LEFT);
}

/** Прописью для целой части числа (0…999). */
function finbank_bg_words_triplet(int $n, int $gender /* 0=masc,1=fem */): string
{
    $n = max(0, min(999, $n));
    $onesM = ['', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'];
    $onesF = ['', 'одна', 'две', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'];
    $ones = $gender === 1 ? $onesF : $onesM;
    $teens = [
        10 => 'десять', 11 => 'одиннадцать', 12 => 'двенадцать', 13 => 'тринадцать', 14 => 'четырнадцать',
        15 => 'пятнадцать', 16 => 'шестнадцать', 17 => 'семнадцать', 18 => 'восемнадцать', 19 => 'девятнадцать',
    ];
    $tens = ['', '', 'двадцать', 'тридцать', 'сорок', 'пятьдесят', 'шестьдесят', 'семьдесят', 'восемьдесят', 'девяносто'];
    $hundreds = ['', 'сто', 'двести', 'триста', 'четыреста', 'пятьсот', 'шестьсот', 'семьсот', 'восемьсот', 'девятьсот'];

    $h = intdiv($n, 100);
    $t = intdiv($n % 100, 10);
    $o = $n % 10;
    $parts = [];
    if ($h > 0) {
        $parts[] = $hundreds[$h];
    }
    if ($t === 1) {
        $parts[] = $teens[10 + $o];
    } else {
        if ($t > 1) {
            $parts[] = $tens[$t];
        }
        if ($o > 0) {
            $parts[] = $ones[$o];
        }
    }
    return trim(implode(' ', $parts));
}

function finbank_bg_plural(int $n, string $one, string $few, string $many): string
{
    $n = abs($n) % 100;
    $n1 = $n % 10;
    if ($n > 10 && $n < 20) {
        return $many;
    }
    if ($n1 > 1 && $n1 < 5) {
        return $few;
    }
    if ($n1 === 1) {
        return $one;
    }
    return $many;
}

/** Сумма прописью (рубли, без копеек в тексте). */
function finbank_bg_amount_words(mixed $amount): string
{
    if ($amount === null || $amount === '' || !is_numeric($amount)) {
        return '';
    }
    $rub = (int) floor(abs((float) $amount));
    if ($rub === 0) {
        return 'ноль рублей';
    }

    $scales = [
        [1000000000, 0, 'миллиард', 'миллиарда', 'миллиардов'],
        [1000000, 0, 'миллион', 'миллиона', 'миллионов'],
        [1000, 1, 'тысяча', 'тысячи', 'тысяч'],
    ];
    $parts = [];
    $rest = $rub;
    foreach ($scales as [$div, $gender, $one, $few, $many]) {
        $chunk = intdiv($rest, $div);
        $rest %= $div;
        if ($chunk <= 0) {
            continue;
        }
        $w = finbank_bg_words_triplet($chunk, $gender);
        if ($w !== '') {
            $parts[] = $w . ' ' . finbank_bg_plural($chunk, $one, $few, $many);
        }
    }
    if ($rest > 0 || $parts === []) {
        $w = finbank_bg_words_triplet($rest, 0);
        if ($w !== '') {
            $parts[] = $w;
        }
    }
    $parts[] = finbank_bg_plural($rub, 'рубль', 'рубля', 'рублей');
    return implode(' ', $parts);
}

/**
 * @param array<string,mixed> $application
 * @param array<string,mixed>|null $methodology
 * @return array<string,string>
 */
function finbank_bg_build_placeholders(array $application, ?array $methodology, DateTimeImmutable $issuedAt): array
{
    $principalName = trim((string) ($application['principal_company_name'] ?? ''));
    if ($principalName === '') {
        $principalName = trim((string) ($application['company_name'] ?? ''));
    }
    $principalInn = trim((string) ($application['principal_inn'] ?? ''));
    if ($principalInn === '') {
        $principalInn = trim((string) ($application['inn'] ?? ''));
    }

    $issueParts = finbank_bg_date_parts($issuedAt);
    $termDt = finbank_bg_parse_date($application['term_bg'] ?? null);
    $termAt = finbank_bg_format_date_dotted($termDt);
    $termParts = finbank_bg_date_parts($termDt);

    $amountRaw = $application['amount'] ?? null;
    $amountDigits = finbank_bg_format_amount_digits($amountRaw);
    $amountWords = finbank_bg_amount_words($amountRaw);
    $amountKopecks = finbank_bg_amount_kopecks($amountRaw);

    $established = '';
    $financialPosition = '';
    $stopFactors = '';
    if (is_array($methodology)) {
        $result = is_array($methodology['result'] ?? null) ? $methodology['result'] : [];
        // result_json stores {result, finance, business}
        if (isset($result['result']) && is_array($result['result'])) {
            $result = $result['result'];
        }
        $established = trim((string) ($result['rating'] ?? $methodology['rating'] ?? ''));
        $financialPosition = trim((string) ($result['position_label'] ?? ''));
        if ($financialPosition === '') {
            $pos = (string) ($result['position'] ?? $methodology['position_code'] ?? '');
            $labels = ['good' => 'Хорошее', 'average' => 'Среднее', 'bad' => 'Плохое', 'incomplete' => 'Недостаточно данных'];
            $financialPosition = $labels[$pos] ?? '';
        }
        $hard = !empty($result['hard_stop']) || !empty($methodology['hard_stop']);
        $mandatory = $result['mandatory_stops'] ?? [];
        if ($hard || (is_array($mandatory) && $mandatory !== [])) {
            $stopFactors = 'да';
        } elseif (isset($result['hard_stop']) || isset($result['mandatory_stops'])) {
            $stopFactors = 'нет';
        } else {
            $stopFactors = '';
        }
    }

    $empty = [
        'decision_number' => '',
        'offer_number' => '',
        'agreement_number' => '',
        'poa_day' => '',
        'poa_month' => '',
        'poa_year' => '',
        'poa_number' => '',
        'principal_kpp' => '',
        'principal_ogrn' => '',
        'beneficiary_kpp' => '',
        'beneficiary_ogrn' => '',
        'commission_base' => '',
        'commission_extra' => '',
        'commission_reissue' => '',
        'commission_total' => '',
        'commission_total_words' => '',
        'agent_name' => '',
        'agent_fee' => '',
        'reserve_portfolio' => '',
        'reserve_percent' => '',
        'specialist_name' => '',
        'guarantor_signatory' => '',
        'guarantor_basis' => '',
        'principal_signatory' => '',
        'principal_basis' => '',
        'principal_signatory_title' => '',
        'principal_signatory_short' => '',
        'principal_legal_address' => '',
        'principal_actual_address' => '',
        'principal_account' => '',
        'principal_bank_name' => '',
        'principal_bik' => '',
        'guarantee_start_date' => '',
    ];

    $values = array_merge($empty, [
        'decision_day' => $issueParts['day'],
        'decision_month' => $issueParts['month'],
        'decision_year' => $issueParts['year'],
        'offer_day' => $issueParts['day'],
        'offer_month' => $issueParts['month'],
        'offer_year' => $issueParts['year'],
        'agreement_day' => $issueParts['day'],
        'agreement_month' => $issueParts['month'],
        'agreement_year' => $issueParts['year'],

        'product' => 'Банковская гарантия экспресс',
        'guarantee_type' => trim((string) ($application['guarantee_type'] ?? '')),
        'purchase_number' => trim((string) ($application['purchase_number'] ?? '')),
        'contract_subject' => trim((string) ($application['contract_subject'] ?? '')),

        'principal_name' => $principalName,
        'principal_inn' => $principalInn,
        'principal_email' => trim((string) ($application['principal_email'] ?? '')),

        'beneficiary_name' => trim((string) ($application['customer_name'] ?? '')),
        'beneficiary_inn' => trim((string) ($application['customer_inn'] ?? '')),

        'amount' => $amountDigits,
        'amount_words' => $amountWords,
        'amount_kopecks' => $amountKopecks,
        'term_bg' => $termAt !== '' ? $termAt : ($termParts['day'] !== ''
            ? trim($termParts['day'] . ' ' . $termParts['month'] . ' ' . $termParts['year'])
            : ''),

        'established_rating' => $established,
        'financial_position' => $financialPosition,
        'stop_factors' => $stopFactors,
    ]);

    // PhpWord: все значения — строки
    foreach ($values as $k => $v) {
        $values[$k] = (string) $v;
    }
    return $values;
}

/**
 * @param array<string,string> $values
 */
function finbank_bg_fill_template(string $templatePath, array $values, string $targetPath): void
{
    finbank_bg_docs_autoload();
    if (!class_exists(\PhpOffice\PhpWord\TemplateProcessor::class)) {
        throw new RuntimeException('PhpWord не установлен (composer install)');
    }
    $processor = new \PhpOffice\PhpWord\TemplateProcessor($templatePath);
    foreach ($processor->getVariables() as $name) {
        $processor->setValue($name, $values[$name] ?? '');
    }
    $dir = dirname($targetPath);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось создать каталог для документов');
    }
    $processor->saveAs($targetPath);
}

/**
 * Сгенерировать (или пересоздать) комплект документов по кейсу.
 *
 * @return list<array<string,mixed>> сохранённые строки
 */
function finbank_bg_docs_generate_for_case(PDO $pdo, int $caseId, int $applicationId, int $userId): array
{
    finbank_bg_docs_ensure_table($pdo);
    finbank_bg_docs_autoload();

    $caseStmt = $pdo->prepare('SELECT bank_code FROM application_product_bank_cases WHERE id = ? LIMIT 1');
    $caseStmt->execute([$caseId]);
    $caseBankCode = trim((string) ($caseStmt->fetchColumn() ?: ''));
    if (!finbank_bg_docs_enabled_for_bank_code($caseBankCode)) {
        return [];
    }

    $appStmt = $pdo->prepare('SELECT * FROM applications WHERE id = ? LIMIT 1');
    $appStmt->execute([$applicationId]);
    $application = $appStmt->fetch(PDO::FETCH_ASSOC);
    if (!$application) {
        throw new RuntimeException('Заявка не найдена');
    }

    $methodology = null;
    try {
        $methodology = bank_methodology_fetch_latest($pdo, $applicationId);
    } catch (Throwable) {
        $methodology = null;
    }

    $issuedAt = new DateTimeImmutable('now');
    $values = finbank_bg_build_placeholders($application, $methodology, $issuedAt);
    $uploadDir = 'uploads/bank_case_generated/' . $caseId . '/';
    $saved = [];

    foreach (finbank_bg_doc_types() as $docType) {
        $template = finbank_bg_doc_template_path($docType);
        if ($template === null) {
            continue;
        }
        $safe = uniqid('bg_' . $docType . '_', true) . '.docx';
        $relPath = $uploadDir . $safe;
        $absPath = rtrim((string) FINBUILD_ROOT, "/\\") . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
        finbank_bg_fill_template($template, $values, $absPath);
        $size = is_file($absPath) ? (int) filesize($absPath) : 0;
        $original = finbank_bg_doc_label($docType) . '.docx';

        // удалить предыдущий файл этого типа
        $prev = $pdo->prepare(
            'SELECT id, file_path FROM application_product_bank_case_generated_docs
             WHERE bank_case_id = ? AND doc_type = ? LIMIT 1'
        );
        $prev->execute([$caseId, $docType]);
        $old = $prev->fetch(PDO::FETCH_ASSOC);
        if ($old) {
            $oldRel = (string) ($old['file_path'] ?? '');
            if ($oldRel !== '') {
                $oldAbs = rtrim((string) FINBUILD_ROOT, "/\\") . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $oldRel);
                if (is_file($oldAbs)) {
                    @unlink($oldAbs);
                }
            }
            $pdo->prepare('DELETE FROM application_product_bank_case_generated_docs WHERE id = ?')
                ->execute([(int) $old['id']]);
        }

        $pdo->prepare(
            'INSERT INTO application_product_bank_case_generated_docs
             (bank_case_id, doc_type, file_path, original_name, file_size, file_type, generated_by)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$caseId, $docType, $relPath, $original, $size, 'word', $userId > 0 ? $userId : null]);

        $id = (int) $pdo->lastInsertId();
        $row = [
            'id' => $id,
            'bank_case_id' => $caseId,
            'doc_type' => $docType,
            'file_path' => $relPath,
            'original_name' => $original,
            'file_size' => $size,
            'file_type' => 'word',
            'generated_by' => $userId > 0 ? $userId : null,
        ];
        $saved[] = finbuild_upload_row_with_url($row, 'bank_bg');
    }

    return $saved;
}

/**
 * @return list<array<string,mixed>>
 */
function finbank_bg_docs_list_for_case(PDO $pdo, int $caseId): array
{
    finbank_bg_docs_ensure_table($pdo);
    $caseStmt = $pdo->prepare('SELECT bank_code FROM application_product_bank_cases WHERE id = ? LIMIT 1');
    $caseStmt->execute([$caseId]);
    $caseBankCode = trim((string) ($caseStmt->fetchColumn() ?: ''));
    if (!finbank_bg_docs_enabled_for_bank_code($caseBankCode)) {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM application_product_bank_case_generated_docs
         WHERE bank_case_id = ?
         ORDER BY FIELD(doc_type, \'decision\', \'offer\', \'agreement\'), id ASC'
    );
    $stmt->execute([$caseId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_map(
        static fn (array $r): array => finbuild_upload_row_with_url($r, 'bank_bg'),
        $rows
    );
}
