<?php
/**
 * Автозаполнение банковской методики из Checko (только уверенные сигналы).
 *
 * Стоп-факторы: 1.1, 1.3, 1.5, 3, 4, C1
 * Финансы: выручка/прибыль/СК/ОА/КО/ДО/валюта баланса
 * Бизнес: company_age
 */
declare(strict_types=1);

require_once __DIR__ . '/../checko_client.php';
require_once __DIR__ . '/engine.php';

/**
 * @return list<string>
 */
function bank_methodology_checko_auto_stop_codes(): array
{
    return ['1.1', '1.3', '1.5', '3', '4', 'C1'];
}

/**
 * Число из строки отчётности Checko (обычной или extended).
 */
function bank_methodology_checko_metric(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_numeric($value)) {
        return (float) $value;
    }
    if (is_array($value)) {
        foreach (['СумОтч', 'value', 'amount'] as $k) {
            if (isset($value[$k]) && is_numeric($value[$k])) {
                return (float) $value[$k];
            }
        }
    }
    return null;
}

/**
 * @param array<string,mixed>|null $finance
 * @return list<array{year:int,src:array<string,mixed>}>
 */
function bank_methodology_checko_finance_years(?array $finance): array
{
    if (!is_array($finance) || $finance === []) {
        return [];
    }
    $years = [];
    foreach ($finance as $yearKey => $row) {
        if (!is_array($row)) {
            continue;
        }
        $year = (int) $yearKey;
        if ($year < 2015 || $year > 2100) {
            continue;
        }
        $src = $row;
        if (isset($row['БухОтчет']) && is_array($row['БухОтчет'])) {
            $src = $row['БухОтчет'];
        }
        $years[] = ['year' => $year, 'src' => $src];
    }
    usort($years, static fn(array $a, array $b): int => $a['year'] <=> $b['year']);
    return $years;
}

/**
 * @param list<array{year:int,src:array<string,mixed>}> $years
 * @return array{year:int,src:array<string,mixed>}|null
 */
function bank_methodology_checko_pick_year(array $years, bool $preferRevenue = true): ?array
{
    if ($years === []) {
        return null;
    }
    if ($preferRevenue) {
        for ($i = count($years) - 1; $i >= 0; $i--) {
            $rev = bank_methodology_checko_metric($years[$i]['src']['2110'] ?? $years[$i]['src']['Выручка'] ?? null);
            if ($rev !== null && $rev > 0) {
                return $years[$i];
            }
        }
    }
    return $years[count($years) - 1];
}

/**
 * @param array<string,mixed>|null $company
 * @return array{months:?int,grade:?string,reg_date:?string}
 */
function bank_methodology_checko_company_age(?array $company): array
{
    $regRaw = trim((string) ($company['ДатаРег'] ?? ''));
    if ($regRaw === '') {
        return ['months' => null, 'grade' => null, 'reg_date' => null];
    }
    try {
        $reg = new DateTimeImmutable(substr($regRaw, 0, 10));
        $diff = $reg->diff(new DateTimeImmutable('today'));
        $months = ((int) $diff->y) * 12 + (int) $diff->m;
        if ((int) $diff->d > 0 && $months === 0) {
            // меньше месяца, но уже зарегистрирована — считаем 0 полных месяцев
        }
        return [
            'months' => $months,
            'grade' => bank_methodology_age_grade(null, $months),
            'reg_date' => $reg->format('Y-m-d'),
        ];
    } catch (Throwable $e) {
        return ['months' => null, 'grade' => null, 'reg_date' => null];
    }
}

/**
 * Есть ли признаки ликвидации / банкротства.
 *
 * @param array<string,mixed>|null $company
 */
function bank_methodology_checko_is_liquidation_or_bankrupt(?array $company): bool
{
    if (!is_array($company) || $company === []) {
        return false;
    }
    if (!empty($company['Ликвид']) || !empty($company['Банкрот'])) {
        return true;
    }
    if (!empty($company['ЕФРСБ']) && is_array($company['ЕФРСБ'])) {
        return true;
    }
    $statusName = mb_strtolower(trim((string) ($company['Статус']['Наим'] ?? '')), 'UTF-8');
    if ($statusName === '') {
        return false;
    }
    foreach (['ликвидац', 'банкрот', 'конкурсн'] as $needle) {
        if (mb_strpos($statusName, $needle) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Недействующее юрлицо без явной ликвидации/банкротства, либо нет карточки в ЕГРЮЛ.
 *
 * @param array<string,mixed>|null $company
 */
function bank_methodology_checko_is_inactive(?array $company, bool $companyFound): bool
{
    if (!$companyFound || !is_array($company) || $company === []) {
        return true;
    }
    if (bank_methodology_checko_is_liquidation_or_bankrupt($company)) {
        return false; // покрывается стопом 1.3
    }
    if (!empty($company['Статус']['Недейств'])) {
        return true;
    }
    $statusName = mb_strtolower(trim((string) ($company['Статус']['Наим'] ?? '')), 'UTF-8');
    if ($statusName === '') {
        return false;
    }
    foreach (['недейств', 'прекратил', 'исключен', 'исключён'] as $needle) {
        if (mb_strpos($statusName, $needle) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * @param array<string,mixed>|null $company
 */
function bank_methodology_checko_has_disqualified(?array $company): bool
{
    if (!is_array($company)) {
        return false;
    }
    if (!empty($company['ДисквЛица'])) {
        return true;
    }
    $leaders = $company['Руковод'] ?? null;
    if (!is_array($leaders)) {
        return false;
    }
    foreach ($leaders as $leader) {
        if (is_array($leader) && !empty($leader['ДисквЛицо'])) {
            return true;
        }
    }
    return false;
}

/**
 * @param array<string,mixed>|null $company
 */
function bank_methodology_checko_tax_debt(?array $company): float
{
    if (!is_array($company)) {
        return 0.0;
    }
    $raw = $company['Налоги']['СумНедоим'] ?? 0;
    if (is_numeric($raw)) {
        return (float) $raw;
    }
    return 0.0;
}

/**
 * Собрать предложения автозаполнения (без слияния в state).
 *
 * @return array{
 *   ok:bool,
 *   inn:string,
 *   error?:string,
 *   warnings:list<string>,
 *   stops:array<string,array{triggered:bool,comment:string}>,
 *   finance_inputs:array<string,mixed>,
 *   business:array<string,array{value:mixed,comment?:string}>,
 *   meta:array<string,mixed>
 * }
 */
function bank_methodology_checko_propose(string $inn): array
{
    $inn = preg_replace('/\D+/', '', $inn) ?? '';
    if (strlen($inn) < 10) {
        return [
            'ok' => false,
            'inn' => $inn,
            'error' => 'ИНН заявки не задан или некорректен',
            'warnings' => [],
            'stops' => [],
            'finance_inputs' => [],
            'business' => [],
            'meta' => [],
        ];
    }

    $bundle = finbuild_checko_fetch_intelligence($inn, true);
    $warnings = is_array($bundle['warnings'] ?? null) ? $bundle['warnings'] : [];
    $company = is_array($bundle['company'] ?? null) ? $bundle['company'] : null;
    $finance = is_array($bundle['finance'] ?? null) ? $bundle['finance'] : null;
    $companyFound = $company !== null;

    $age = bank_methodology_checko_company_age($company);
    $isLiq = bank_methodology_checko_is_liquidation_or_bankrupt($company);
    $isInactive = bank_methodology_checko_is_inactive($company, $companyFound);
    $isDisq = bank_methodology_checko_has_disqualified($company);
    $isRnp = $companyFound && !empty($company['НедобПост']);
    $taxDebt = bank_methodology_checko_tax_debt($company);
    $ageLt6 = $age['months'] !== null && $age['months'] < 6;

    $stops = [];
    if ($isInactive) {
        $stops['1.1'] = [
            'triggered' => true,
            'comment' => $companyFound
                ? 'Checko: статус недействующего юрлица'
                : 'Checko: карточка организации по ИНН не найдена',
        ];
    } else {
        $stops['1.1'] = ['triggered' => false, 'comment' => ''];
    }

    if ($isLiq) {
        $detail = [];
        if (!empty($company['Ликвид'])) {
            $detail[] = 'ликвидация';
        }
        if (!empty($company['Банкрот']) || (!empty($company['ЕФРСБ']) && is_array($company['ЕФРСБ']))) {
            $detail[] = 'банкротство';
        }
        $stops['1.3'] = [
            'triggered' => true,
            'comment' => 'Checko: ' . ($detail !== [] ? implode(', ', $detail) : 'ликвидация/банкротство'),
        ];
    } else {
        $stops['1.3'] = ['triggered' => false, 'comment' => ''];
    }

    if ($ageLt6) {
        $stops['1.5'] = [
            'triggered' => true,
            'comment' => 'Checko: дата регистрации ' . ($age['reg_date'] ?? '—')
                . ' (полный месяцев: ' . (string) $age['months'] . ')',
        ];
    } else {
        $stops['1.5'] = ['triggered' => false, 'comment' => ''];
    }

    $stops['3'] = $isDisq
        ? ['triggered' => true, 'comment' => 'Checko: дисквалифицированные лица']
        : ['triggered' => false, 'comment' => ''];

    $stops['4'] = $isRnp
        ? ['triggered' => true, 'comment' => 'Checko: реестр недобросовестных поставщиков']
        : ['triggered' => false, 'comment' => ''];

    $stops['C1'] = $taxDebt > 0
        ? [
            'triggered' => true,
            'comment' => 'Checko: недоимка ' . number_format($taxDebt, 2, '.', ' ') . ' ₽',
        ]
        : ['triggered' => false, 'comment' => ''];

    $financeInputs = [];
    $years = bank_methodology_checko_finance_years($finance);
    $latest = bank_methodology_checko_pick_year($years, true);
    if ($latest !== null) {
        $src = $latest['src'];
        // Checko отдаёт только годовую отчётность (ГИР БО) → период = annual.
        // «Период» и «год» при annual — один и тот же последний завершённый год.
        // Квартальные цифры Checko не даёт: при смене периода на q1/q2/9m
        // поля «период» нужно править вручную, «год» оставлять годовым.
        $map = [
            'revenue' => $src['2110'] ?? $src['Выручка'] ?? null,
            'revenue_last_year' => $src['2110'] ?? $src['Выручка'] ?? null,
            'net_profit' => $src['2400'] ?? $src['ЧистПриб'] ?? null,
            'prior_year_net_profit' => $src['2400'] ?? $src['ЧистПриб'] ?? null,
            'equity' => $src['1300'] ?? $src['Капитал'] ?? null,
            'current_assets' => $src['1200'] ?? null,
            'current_liabilities' => $src['1500'] ?? null,
            'long_term_liabilities' => $src['1400'] ?? null,
            'balance_total' => $src['1600'] ?? $src['Актив'] ?? null,
        ];
        foreach ($map as $key => $raw) {
            $num = bank_methodology_checko_metric($raw);
            if ($num !== null) {
                $financeInputs[$key] = $num;
            }
        }
        $financeInputs['reporting_period'] = 'annual';
    }

    $business = [];
    if ($age['grade'] !== null) {
        $business['company_age'] = [
            'value' => $age['grade'],
            'comment' => 'Checko: регистрация ' . ($age['reg_date'] ?? '—'),
        ];
    }

    return [
        'ok' => true,
        'inn' => $inn,
        'warnings' => $warnings,
        'stops' => $stops,
        'finance_inputs' => $financeInputs,
        'business' => $business,
        'meta' => [
            'company_found' => $companyFound,
            'company_name' => $companyFound
                ? trim((string) ($company['НаимСокр'] ?? $company['НаимПолн'] ?? ''))
                : '',
            'finance_year' => $latest['year'] ?? null,
            'reg_date' => $age['reg_date'],
            'age_months' => $age['months'],
            'tax_debt' => $taxDebt,
            'triggered_stops' => array_values(array_filter(
                array_keys($stops),
                static fn(string $code): bool => !empty($stops[$code]['triggered'])
            )),
        ],
    ];
}

/**
 * Пустое ли поле финансов (можно перезаписать из Checko).
 */
function bank_methodology_finance_input_empty(mixed $v): bool
{
    return $v === null || $v === '';
}

/**
 * Слить предложения Checko в state методики.
 *
 * Политика (кнопка «Из Checko»):
 * - авто-стопы: включаем по сигналу Checko; снимаем только если source=checko
 *   (ручной triggered=true не трогаем);
 * - финансы: только пустые поля (или все при $forceFinance);
 * - business.company_age: только если value пустой или source=checko.
 *
 * @param array<string,mixed> $state
 * @param array<string,mixed> $proposal результат bank_methodology_checko_propose
 * @return array{state:array<string,mixed>,applied:array<string,mixed>}
 */
function bank_methodology_checko_apply(array $state, array $proposal, bool $forceFinance = false): array
{
    $state = bank_methodology_normalize_state($state);
    $applied = [
        'stops_on' => [],
        'stops_off' => [],
        'stops_skipped' => [],
        'finance' => [],
        'business' => [],
    ];

    $autoCodes = bank_methodology_checko_auto_stop_codes();
    $propStops = is_array($proposal['stops'] ?? null) ? $proposal['stops'] : [];

    foreach ($state['stop_factors'] as &$sf) {
        $code = (string) $sf['code'];
        if (!in_array($code, $autoCodes, true) || !isset($propStops[$code])) {
            continue;
        }
        $want = !empty($propStops[$code]['triggered']);
        $comment = trim((string) ($propStops[$code]['comment'] ?? ''));
        $source = (string) ($sf['source'] ?? 'manual');
        $now = !empty($sf['triggered']);

        if ($want) {
            if ($now && $source === 'manual') {
                // Уже отмечен вручную — только допишем source/комментарий при пустом комментарии
                if (trim((string) ($sf['comment'] ?? '')) === '' && $comment !== '') {
                    $sf['comment'] = $comment;
                }
                $applied['stops_skipped'][] = $code;
                continue;
            }
            $sf['triggered'] = true;
            $sf['source'] = 'checko';
            $sf['comment'] = $comment !== '' ? $comment : (string) ($sf['comment'] ?? '');
            $applied['stops_on'][] = $code;
            continue;
        }

        // want=false: снимаем только автопроставленные
        if ($now && $source === 'checko') {
            $sf['triggered'] = false;
            $sf['source'] = 'checko';
            $sf['comment'] = '';
            $applied['stops_off'][] = $code;
        } elseif ($now && $source === 'manual') {
            $applied['stops_skipped'][] = $code;
        }
    }
    unset($sf);

    $finProp = is_array($proposal['finance_inputs'] ?? null) ? $proposal['finance_inputs'] : [];
    foreach ($finProp as $key => $val) {
        if (!array_key_exists($key, $state['finance']['inputs'])) {
            continue;
        }
        $cur = $state['finance']['inputs'][$key];
        if (!$forceFinance && !bank_methodology_finance_input_empty($cur)) {
            continue;
        }
        $state['finance']['inputs'][$key] = $val;
        $applied['finance'][] = $key;
    }

    $bizProp = is_array($proposal['business'] ?? null) ? $proposal['business'] : [];
    foreach ($bizProp as $id => $row) {
        if (!isset($state['business'][$id]) || !is_array($row)) {
            continue;
        }
        $cur = $state['business'][$id];
        $curVal = $cur['value'] ?? null;
        $curSrc = (string) ($cur['source'] ?? 'manual');
        $empty = $curVal === null || $curVal === '';
        if (!$empty && $curSrc !== 'checko') {
            continue;
        }
        $state['business'][$id]['value'] = $row['value'] ?? null;
        $state['business'][$id]['source'] = 'checko';
        $applied['business'][] = $id;
    }

    return [
        'state' => bank_methodology_normalize_state($state),
        'applied' => $applied,
    ];
}
