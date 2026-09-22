<?php
/**
 * Движок расчёта оценки по банковской методике.
 */
declare(strict_types=1);

require_once __DIR__ . '/rules.php';

/**
 * Пустое состояние оценки (черновик).
 *
 * @return array<string,mixed>
 */
function bank_methodology_empty_state(): array
{
    $rules = bank_methodology_rules();
    $stops = [];
    foreach ($rules['stop_factors'] as $sf) {
        $stops[] = [
            'code' => $sf['code'],
            'triggered' => false,
            'source' => 'manual',
            'comment' => '',
        ];
    }

    $business = [];
    foreach ($rules['business_metrics'] as $id => $metric) {
        $business[$id] = [
            'value' => null,
            'source' => 'manual',
            'score_override' => null,
        ];
    }

    return [
        'stop_factors' => $stops,
        'finance' => [
            'inputs' => [
                'revenue' => null, // выручка текущего периода (для рентабельности)
                'revenue_last_year' => null, // выручка за последний завершённый год (для долг/выручка)
                'net_profit' => null,
                'prior_year_net_profit' => null, // прибыль за последний завершённый год (правило убытка 1 кв.)
                'income_from_participation' => null, // стр.6 ОПУ — для аналога выручки
                'interest_receivable' => null, // стр.7
                'other_income' => null, // стр.9
                'equity' => null,
                'current_assets' => null,
                'current_liabilities' => null,
                'long_term_liabilities' => null,
                'balance_total' => null,
                'short_term_borrowings' => null,
                'long_term_borrowings' => null,
                'accounts_payable' => null,
                'other_short_liabilities' => null,
                'debt_to_revenue' => null,
                'industry' => 'default',
                'reporting_period' => 'annual', // annual|q1|q2|q3|9m
                'q1_seasonal_loss_explained' => false,
                'q1_seasonal_comment' => '',
                'profitability_explained_zero' => false,
                'roe_explained_zero' => false,
            ],
            'score_overrides' => [], // устарело: ручные баллы по метрикам не используются
        ],
        'business' => $business,
        'judgment' => [
            'comment' => '',
            'upgrade_downgrade_reason' => '',
            'established_rating' => '', // пусто = расчётный; иначе буква из шкалы
            'force_not_good' => false,
            'negative_equity' => false,
        ],
    ];
}

/**
 * @param array<string,mixed> $state
 * @return array<string,mixed>
 */
function bank_methodology_evaluate(array $state): array
{
    $rules = bank_methodology_rules();
    $state = bank_methodology_normalize_state($state);

    $mandatoryStops = [];
    $conditionalStops = [];
    $stopIndex = [];
    foreach ($rules['stop_factors'] as $sf) {
        $stopIndex[$sf['code']] = $sf;
    }
    foreach ($state['stop_factors'] as $row) {
        if (empty($row['triggered'])) {
            continue;
        }
        $meta = $stopIndex[$row['code']] ?? null;
        $item = [
            'code' => $row['code'],
            'label' => $meta['label'] ?? $row['code'],
            'comment' => (string) ($row['comment'] ?? ''),
            'mandatory' => $meta ? !empty($meta['mandatory']) : true,
        ];
        if ($item['mandatory']) {
            $mandatoryStops[] = $item;
        } else {
            $conditionalStops[] = $item;
        }
    }

    $finance = bank_methodology_score_finance($state['finance'], $rules);
    $business = bank_methodology_score_business($state['business'], $rules);

    $financeScore = (float) $finance['total'];
    $businessScore = (float) $business['total'];
    $total = $financeScore + $businessScore;

    $pendingFinance = [];
    foreach ($finance['metrics'] as $id => $m) {
        if (($m['source'] ?? '') === 'pending') {
            $pendingFinance[] = $id;
        }
    }
    $pendingBusiness = [];
    foreach ($business['metrics'] as $id => $m) {
        if (($m['source'] ?? '') === 'pending') {
            $pendingBusiness[] = $id;
        }
    }
    $incomplete = $pendingFinance !== [] || $pendingBusiness !== [];

    $warnings = [];
    if ($incomplete) {
        $warnings[] = 'Недостаточно данных для итогового рейтинга: заполните все финансовые и бизнес-показатели.';
        $ratingInfo = [
            'rating' => null,
            'category' => null,
            'position' => 'incomplete',
        ];
        $position = 'incomplete';
    } else {
        $ratingInfo = bank_methodology_map_rating($total, $rules);
        $position = $ratingInfo['position'];
    }

    $calculatedRating = $ratingInfo['rating'];
    $calculatedPosition = $position;

    $equity = $state['finance']['inputs']['equity'] ?? null;
    $negativeEquity = !empty($state['judgment']['negative_equity'])
        || ($equity !== null && $equity !== '' && (float) $equity < 0);
    if (!$incomplete && $negativeEquity && $position === 'good') {
        $ratingInfo = bank_methodology_cap_rating_to_average($ratingInfo, $rules);
        $position = 'average';
        $warnings[] = 'Отрицательный собственный капитал: положение не может быть «Хорошим» (максимум «Среднее», рейтинг не выше B-).';
    }
    if (!$incomplete && !empty($state['judgment']['force_not_good']) && $position === 'good') {
        $ratingInfo = bank_methodology_cap_rating_to_average($ratingInfo, $rules);
        $position = 'average';
        $warnings[] = 'Отмечены обстоятельства, исключающие оценку «Хорошее» (по методике / 590-П).';
    }

    // Установленный рейтинг (профсуждение) — поверх расчётного
    $establishedRaw = trim((string) ($state['judgment']['established_rating'] ?? ''));
    $establishedApplied = false;
    if (!$incomplete && $establishedRaw !== '') {
        $mapped = bank_methodology_rating_by_letter($establishedRaw, $rules);
        if ($mapped !== null) {
            if ($negativeEquity && $mapped['position'] === 'good') {
                $mapped = bank_methodology_cap_rating_to_average($mapped, $rules);
                $warnings[] = 'Установленный рейтинг ограничен из‑за отрицательного СК (не выше B- / «Среднее»).';
            }
            if (!empty($state['judgment']['force_not_good']) && $mapped['position'] === 'good') {
                $mapped = bank_methodology_cap_rating_to_average($mapped, $rules);
            }
            $ratingInfo = $mapped;
            $position = $mapped['position'];
            $establishedApplied = true;
            $reason = trim((string) ($state['judgment']['upgrade_downgrade_reason'] ?? ''));
            if ($reason === '' && $establishedRaw !== (string) $calculatedRating) {
                $warnings[] = 'Укажите основания повышения / понижения рейтинга (установленный отличается от расчётного).';
            }
        }
    }

    $hardStop = $mandatoryStops !== [];

    $positionLabels = $rules['position_labels'];
    $positionLabels['incomplete'] = 'Недостаточно данных';

    return [
        'state' => $state,
        'result' => [
            'finance_score' => round($financeScore, 2),
            'business_score' => round($businessScore, 2),
            'total_score' => round($total, 2),
            'rating' => $ratingInfo['rating'],
            'calculated_rating' => $calculatedRating,
            'calculated_position' => $calculatedPosition,
            'established_applied' => $establishedApplied,
            'category' => $ratingInfo['category'],
            'position' => $position,
            'position_label' => $positionLabels[$position] ?? $position,
            'incomplete' => $incomplete,
            'pending_finance' => $pendingFinance,
            'pending_business' => $pendingBusiness,
            'hard_stop' => $hardStop,
            'mandatory_stops' => $mandatoryStops,
            'conditional_stops' => $conditionalStops,
            'warnings' => $warnings,
            'negative_equity' => $negativeEquity,
        ],
        'finance' => $finance,
        'business' => $business,
        'rules_meta' => $rules['meta'],
    ];
}

/**
 * @param array<string,mixed> $state
 * @return array<string,mixed>
 */
function bank_methodology_normalize_state(array $state): array
{
    $empty = bank_methodology_empty_state();
    $out = $empty;

    if (!empty($state['stop_factors']) && is_array($state['stop_factors'])) {
        $byCode = [];
        foreach ($state['stop_factors'] as $row) {
            if (!is_array($row) || empty($row['code'])) {
                continue;
            }
            $byCode[(string) $row['code']] = $row;
        }
        foreach ($out['stop_factors'] as &$sf) {
            $code = $sf['code'];
            if (!isset($byCode[$code])) {
                continue;
            }
            $sf['triggered'] = !empty($byCode[$code]['triggered']);
            $sf['source'] = (string) ($byCode[$code]['source'] ?? 'manual');
            $sf['comment'] = trim((string) ($byCode[$code]['comment'] ?? ''));
        }
        unset($sf);
    }

    if (!empty($state['finance']['inputs']) && is_array($state['finance']['inputs'])) {
        foreach ($out['finance']['inputs'] as $k => $v) {
            if (array_key_exists($k, $state['finance']['inputs'])) {
                $out['finance']['inputs'][$k] = $state['finance']['inputs'][$k];
            }
        }
    }
    if (!empty($state['finance']['score_overrides']) && is_array($state['finance']['score_overrides'])) {
        $out['finance']['score_overrides'] = $state['finance']['score_overrides'];
    }

    if (!empty($state['business']) && is_array($state['business'])) {
        foreach ($out['business'] as $id => $row) {
            if (!isset($state['business'][$id]) || !is_array($state['business'][$id])) {
                continue;
            }
            $src = $state['business'][$id];
            $out['business'][$id]['value'] = $src['value'] ?? null;
            $out['business'][$id]['source'] = (string) ($src['source'] ?? 'manual');
            $out['business'][$id]['score_override'] = array_key_exists('score_override', $src)
                ? $src['score_override']
                : null;
        }
    }

    if (!empty($state['judgment']) && is_array($state['judgment'])) {
        foreach ($out['judgment'] as $k => $v) {
            if (array_key_exists($k, $state['judgment'])) {
                $out['judgment'][$k] = $state['judgment'][$k];
            }
        }
    }

    return $out;
}

/**
 * @param array<string,mixed> $financeState
 * @param array<string,mixed> $rules
 * @return array{total:float,metrics:array<string,mixed>}
 */
function bank_methodology_score_finance(array $financeState, array $rules): array
{
    $inputs = $financeState['inputs'] ?? [];
    $metricsOut = [];
    $total = 0.0;

    $computed = bank_methodology_compute_ratios($inputs);

    foreach ($rules['finance_metrics'] as $id => $metric) {
        $value = $computed[$id]['value'] ?? null;
        $note = $computed[$id]['note'] ?? '';
        $autoScore = $computed[$id]['score'] ?? null;
        $max = (int) $metric['max'];

        // отраслевая шкала для долг/выручка
        if ($id === 'debt_to_revenue') {
            $industry = (string) ($inputs['industry'] ?? 'default');
            $alt = $metric['alt_industry'] ?? null;
            if (is_array($alt) && !empty($alt[$industry])) {
                $max = (int) ($alt['max'] ?? $max);
            }
        }

        $source = 'auto';
        $score = $autoScore;

        if ($score === null) {
            // Не подставляем 0: иначе пустая форма даёт рейтинг D.
            $metricsOut[$id] = [
                'id' => $id,
                'label' => $metric['label'],
                'group' => $metric['group'],
                'value' => $value,
                'auto_score' => $autoScore,
                'score' => null,
                'max' => $max,
                'weight' => $metric['weight'],
                'source' => 'pending',
                'note' => $note !== '' ? $note : 'Недостаточно данных для авторасчёта',
                'unit' => $metric['unit'] ?? '',
            ];
            continue;
        }

        $metricsOut[$id] = [
            'id' => $id,
            'label' => $metric['label'],
            'group' => $metric['group'],
            'value' => $value,
            'auto_score' => $autoScore,
            'score' => (float) $score,
            'max' => $max,
            'weight' => $metric['weight'],
            'source' => $source,
            'note' => $note,
            'unit' => $metric['unit'] ?? '',
        ];
        $total += (float) $score;
    }

    return ['total' => $total, 'metrics' => $metricsOut, 'ratios' => $computed];
}

/**
 * Ограничить ручной балл допустимым диапазоном шкалы методики.
 *
 * @param array<string,mixed> $metric
 */
function bank_methodology_clamp_score(float $score, array $metric, int $max): float
{
    $min = 0.0;
    $hasRange = false;
    $bands = $metric['bands'] ?? [];
    $industryBands = $metric['alt_industry']['bands'] ?? null;
    foreach ([$bands, is_array($industryBands) ? $industryBands : []] as $set) {
        foreach ($set as $b) {
            $hasRange = true;
            $min = min($min, (float) ($b['score'] ?? 0));
        }
    }
    if (!empty($metric['options']) && is_array($metric['options'])) {
        foreach ($metric['options'] as $opt) {
            $hasRange = true;
            $min = min($min, (float) ($opt['score'] ?? 0));
        }
    }
    if (!$hasRange) {
        $min = -1 * abs($max);
    }
    if ($score > $max) {
        return (float) $max;
    }
    if ($score < $min) {
        return $min;
    }
    return $score;
}

/**
 * @param array<string,mixed> $inputs
 * @return array<string,array{value:?float,score:?float,note:string}>
 */
function bank_methodology_compute_ratios(array $inputs): array
{
    $rules = bank_methodology_rules();
    $out = [];

    $revenue = bank_methodology_num($inputs['revenue'] ?? null);
    $revenueYear = bank_methodology_num($inputs['revenue_last_year'] ?? null);
    // Совместимость старых черновиков: если годовая выручка не задана — берём текущую
    if ($revenueYear === null && $revenue !== null) {
        $revenueYear = $revenue;
    }
    $profit = bank_methodology_num($inputs['net_profit'] ?? null);
    $priorYearProfit = bank_methodology_num($inputs['prior_year_net_profit'] ?? null);
    $equity = bank_methodology_num($inputs['equity'] ?? null);
    $ca = bank_methodology_num($inputs['current_assets'] ?? null);
    $cl = bank_methodology_num($inputs['current_liabilities'] ?? null);
    $lt = bank_methodology_num($inputs['long_term_liabilities'] ?? null);
    $balance = bank_methodology_num($inputs['balance_total'] ?? null);
    $debtRatioManual = bank_methodology_num($inputs['debt_to_revenue'] ?? null);

    $incomeParticipation = bank_methodology_num($inputs['income_from_participation'] ?? null) ?? 0.0;
    $interestRecv = bank_methodology_num($inputs['interest_receivable'] ?? null) ?? 0.0;
    $otherIncome = bank_methodology_num($inputs['other_income'] ?? null) ?? 0.0;
    $analogRevenue = $incomeParticipation + $interestRecv + $otherIncome;

    $reportingPeriod = (string) ($inputs['reporting_period'] ?? 'annual');
    $q1Explained = !empty($inputs['q1_seasonal_loss_explained']);
    $q1Comment = trim((string) ($inputs['q1_seasonal_comment'] ?? ''));
    $q1SeasonalOk = ($reportingPeriod === 'q1')
        && $q1Explained
        && ($priorYearProfit !== null && $priorYearProfit > 0)
        && ($q1Comment !== '');

    // Общая рентабельность — выручка текущего периода (или аналог при нулевой выручке)
    $tpMetric = $rules['finance_metrics']['total_profitability'];
    $tpValue = null;
    $tpScore = null;
    $tpNote = '';
    $revenueExplicitZero = ($revenue !== null && abs((float) $revenue) < 0.00001);
    $denom = null;
    if ($revenueExplicitZero) {
        if (abs($analogRevenue) > 0.00001) {
            $denom = $analogRevenue;
            $tpNote = 'Аналог выручки (доходы от участия + проценты к получению + прочие доходы)';
        } else {
            $tpScore = -4.0;
            $tpNote = 'Отсутствие выручки';
        }
    } elseif ($revenue !== null) {
        $denom = $revenue;
    }

    if ($tpScore === null && $denom !== null && $profit !== null && abs($denom) > 0.00001) {
        $tpValue = ($profit / $denom) * 100.0;
        if (abs($tpValue) < 0.00001) {
            $tpScore = 0.0;
        } else {
            $tpScore = bank_methodology_band_score($tpValue, $tpMetric['bands'], 'default');
        }
        if ($q1SeasonalOk && $tpValue < 0) {
            $tpScore = 0.0;
            $tpNote = trim(($tpNote !== '' ? $tpNote . '; ' : '') . 'Убыток 1 кв. при сезонности (прибыль за последний завершённый год, комментарий)');
        }
    }
    $out['total_profitability'] = ['value' => $tpValue, 'score' => $tpScore, 'note' => $tpNote];

    // ROE
    $roeMetric = $rules['finance_metrics']['roe'];
    $roeValue = null;
    $roeScore = null;
    $roeNote = '';
    $equityMissing = ($equity !== null && abs((float) $equity) < 0.00001);
    if ($equityMissing) {
        $roeScore = (float) ($roeMetric['missing_equity_score'] ?? -4);
        $roeNote = 'Отсутствие собственного капитала';
    } elseif ($equity !== null && abs((float) $equity) > 0.00001 && $profit !== null) {
        $roeValue = ($profit / $equity) * 100.0;
        if (abs($roeValue) < 0.00001) {
            $roeScore = 0.0;
        } else {
            $roeScore = bank_methodology_band_score($roeValue, $roeMetric['bands'], 'default');
        }
        if ($q1SeasonalOk && $roeValue < 0) {
            $roeScore = 0.0;
            $roeNote = 'Убыток 1 кв. при сезонности (прибыль за последний завершённый год, комментарий)';
        }
    }
    $out['roe'] = ['value' => $roeValue, 'score' => $roeScore, 'note' => $roeNote];

    // Ликвидность
    $liqMetric = $rules['finance_metrics']['current_liquidity'];
    $liqValue = null;
    $liqScore = null;
    $liqNote = '';
    $clMissing = ($cl !== null && abs((float) $cl) < 0.00001);
    if ($clMissing) {
        $liqScore = (float) ($liqMetric['no_current_liabilities_score'] ?? 6);
        $liqNote = 'Отсутствие текущих обязательств';
    } elseif ($ca !== null && $cl !== null && abs((float) $cl) > 0.00001) {
        $liqValue = $ca / $cl;
        $liqScore = bank_methodology_band_score($liqValue, $liqMetric['bands'], 'default');
    }
    $out['current_liquidity'] = ['value' => $liqValue, 'score' => $liqScore, 'note' => $liqNote];

    // Независимость
    $indValue = null;
    $indScore = null;
    $indNote = '';
    if ($equity !== null && $balance !== null && abs($balance) > 0.00001) {
        $equityForRatios = $equityMissing ? 0.0 : (float) $equity;
        $indValue = $equityForRatios / $balance;
        $indScore = bank_methodology_band_score($indValue, $rules['finance_metrics']['independence']['bands'], 'default');
        if ($equityMissing) {
            $indNote = 'СК = 0';
        }
    }
    $out['independence'] = ['value' => $indValue, 'score' => $indScore, 'note' => $indNote];

    // Фин. устойчивость
    $fsValue = null;
    $fsScore = null;
    $fsNote = '';
    if ($equity !== null && $lt !== null && $balance !== null && abs($balance) > 0.00001) {
        $equityForRatios = $equityMissing ? 0.0 : (float) $equity;
        $fsValue = ($equityForRatios + $lt) / $balance;
        $fsScore = bank_methodology_band_score($fsValue, $rules['finance_metrics']['financial_stability']['bands'], 'default');
        if ($equityMissing) {
            $fsNote = 'СК = 0';
        }
    }
    $out['financial_stability'] = ['value' => $fsValue, 'score' => $fsScore, 'note' => $fsNote];

    // Долг / выручка — знаменатель: выручка за последний завершённый год
    $debtMetric = $rules['finance_metrics']['debt_to_revenue'];
    $stb = bank_methodology_num($inputs['short_term_borrowings'] ?? null);
    $ap = bank_methodology_num($inputs['accounts_payable'] ?? null);
    $osl = bank_methodology_num($inputs['other_short_liabilities'] ?? null);
    $ltb = bank_methodology_num($inputs['long_term_borrowings'] ?? null);
    $debtValue = $debtRatioManual;
    $debtScore = null;
    $debtNote = '';
    $industry = (string) ($inputs['industry'] ?? 'default');
    $bands = $debtMetric['bands'];
    $mode = 'debt';
    if (in_array($industry, ['leasing', 'factoring'], true) && !empty($debtMetric['alt_industry'])) {
        $bands = $debtMetric['alt_industry']['bands'];
    }
    $revenueYearMissing = ($revenueYear !== null && abs((float) $revenueYear) < 0.00001);
    if ($revenueYearMissing) {
        $debtScore = (float) ($debtMetric['no_revenue_score'] ?? -6);
        $debtNote = 'Отсутствие выручки за последний завершённый год';
    } elseif ($debtValue === null && $revenueYear !== null && abs($revenueYear) > 0.00001
        && $stb !== null && $ltb !== null && $ap !== null && $osl !== null && $ca !== null) {
        $diff = $ap + $osl - $ca;
        if ($diff <= 0) {
            $debtValue = ($stb + $ltb) / $revenueYear;
            $debtNote = 'Авторасчёт: разница ≤ 0; знаменатель — выручка за год';
        } else {
            $debtValue = ($stb + $ltb + $diff) / $revenueYear;
            $debtNote = 'Авторасчёт: разница > 0; знаменатель — выручка за год';
        }
    } elseif ($debtValue !== null) {
        $debtNote = 'Задан вручную';
    }

    if ($debtScore === null && $debtValue !== null) {
        if (abs($debtValue) < 0.00001) {
            $debtScore = (float) $bands[0]['score'];
            $debtNote = trim($debtNote . '; значение 0 — максимальный балл');
        } else {
            $debtScore = bank_methodology_band_score($debtValue, $bands, $mode);
        }
    }
    $out['debt_to_revenue'] = ['value' => $debtValue, 'score' => $debtScore, 'note' => $debtNote];

    return $out;
}

/**
 * @param list<array{lo:?float,hi:?float,score:int|float}> $bands
 */
function bank_methodology_band_score(float $value, array $bands, string $mode = 'default'): float
{
    if ($mode === 'debt') {
        // Методика: нижняя граница строго >, верхняя ≤. Значение 0 обрабатывает вызывающий код.
        foreach ($bands as $b) {
            $lo = $b['lo'];
            $hi = $b['hi'];
            $gtLo = ($lo === null) ? true : ($value > (float) $lo);
            // Интервал «0–0.3»: фактически (0; 0.3]
            if ($lo !== null && abs((float) $lo) < 1e-12) {
                $gtLo = $value > 0;
            }
            $leHi = ($hi === null) ? true : ($value <= (float) $hi);
            if ($gtLo && $leHi) {
                return (float) $b['score'];
            }
        }
        return (float) ($bands[count($bands) - 1]['score'] ?? 0);
    }

    // По умолчанию: ≥ lo и < hi
    foreach ($bands as $b) {
        $lo = $b['lo'];
        $hi = $b['hi'];
        $geLo = ($lo === null) ? true : ($value >= (float) $lo);
        $ltHi = ($hi === null) ? true : ($value < (float) $hi);
        if ($geLo && $ltHi) {
            return (float) $b['score'];
        }
    }
    return (float) ($bands[count($bands) - 1]['score'] ?? 0);
}

/**
 * @param array<string,mixed> $businessState
 * @param array<string,mixed> $rules
 * @return array{total:float,metrics:array<string,mixed>}
 */
function bank_methodology_score_business(array $businessState, array $rules): array
{
    $metricsOut = [];
    $total = 0.0;

    foreach ($rules['business_metrics'] as $id => $metric) {
        $row = is_array($businessState[$id] ?? null) ? $businessState[$id] : [];
        $value = $row['value'] ?? null;
        $autoScore = null;
        $note = '';

        foreach ($metric['options'] as $opt) {
            if ((string) $opt['value'] === (string) $value) {
                $autoScore = (float) $opt['score'];
                break;
            }
        }
        if ($value === null || $value === '') {
            $note = 'Не выбрано';
        }

        $source = (string) ($row['source'] ?? 'manual');
        $score = $autoScore;
        // Ручные баллы по метрикам отключены: только градация из шкалы методики.
        if ($score === null) {
            $metricsOut[$id] = [
                'id' => $id,
                'label' => $metric['label'],
                'value' => $value,
                'auto_score' => $autoScore,
                'score' => null,
                'max' => (int) $metric['max'],
                'weight' => $metric['weight'],
                'source' => 'pending',
                'note' => $note !== '' ? $note : 'Не выбрано',
                'options' => $metric['options'],
                'hint' => $metric['hint'] ?? '',
                'manual_only' => !empty($metric['manual_only']),
            ];
            continue;
        }

        $metricsOut[$id] = [
            'id' => $id,
            'label' => $metric['label'],
            'value' => $value,
            'auto_score' => $autoScore,
            'score' => (float) $score,
            'max' => (int) $metric['max'],
            'weight' => $metric['weight'],
            'source' => $source === 'edited' ? 'manual' : $source,
            'note' => $note,
            'options' => $metric['options'],
            'hint' => $metric['hint'] ?? '',
            'manual_only' => !empty($metric['manual_only']),
        ];
        $total += (float) $score;
    }

    return ['total' => $total, 'metrics' => $metricsOut];
}

/**
 * @param array<string,mixed> $rules
 * @return array{rating:string,category:string,position:string}
 */
function bank_methodology_map_rating(float $total, array $rules): array
{
    // Пограничное значение → более высокий рейтинг: нижняя граница включена.
    foreach ($rules['rating_scale'] as $row) {
        $lo = $row['lo'];
        $hi = $row['hi'];
        $geLo = ($lo === null) ? true : ($total >= (float) $lo);
        $ltHi = ($hi === null) ? true : ($total < (float) $hi);
        if ($geLo && $ltHi) {
            return [
                'rating' => (string) $row['rating'],
                'category' => (string) $row['category'],
                'position' => (string) $row['position'],
            ];
        }
    }
    return ['rating' => 'D', 'category' => 'Убыточный', 'position' => 'bad'];
}

/**
 * @param array{rating:?string,category:?string,position:string} $ratingInfo
 * @param array<string,mixed> $rules
 * @return array{rating:string,category:string,position:string}
 */
function bank_methodology_cap_rating_to_average(array $ratingInfo, array $rules): array
{
    if (($ratingInfo['position'] ?? '') !== 'good') {
        return [
            'rating' => (string) ($ratingInfo['rating'] ?? 'B-'),
            'category' => (string) ($ratingInfo['category'] ?? 'Спекулятивный'),
            'position' => (string) ($ratingInfo['position'] ?? 'average'),
        ];
    }
    $mapped = bank_methodology_rating_by_letter('B-', $rules);
    if ($mapped !== null) {
        return $mapped;
    }
    return ['rating' => 'B-', 'category' => 'Спекулятивный', 'position' => 'average'];
}

/**
 * @param array<string,mixed> $rules
 * @return array{rating:string,category:string,position:string}|null
 */
function bank_methodology_rating_by_letter(string $letter, array $rules): ?array
{
    $letter = trim($letter);
    foreach ($rules['rating_scale'] as $row) {
        if ((string) $row['rating'] === $letter) {
            return [
                'rating' => (string) $row['rating'],
                'category' => (string) $row['category'],
                'position' => (string) $row['position'],
            ];
        }
    }
    return null;
}

function bank_methodology_num(mixed $v): ?float
{
    if ($v === null || $v === '') {
        return null;
    }
    if (is_string($v)) {
        $v = str_replace([' ', ','], ['', '.'], $v);
    }
    if (!is_numeric($v)) {
        return null;
    }
    return (float) $v;
}

/**
 * Возраст компании → градация business.company_age
 */
function bank_methodology_age_grade(?int $ageYears, ?int $ageMonthsTotal = null): ?string
{
    $months = $ageMonthsTotal;
    if ($months === null && $ageYears !== null) {
        $months = $ageYears * 12;
    }
    if ($months === null) {
        return null;
    }
    // верхняя граница не включается: 6 месяцев → следующая корзина начинается с 6
    if ($months < 6) {
        return 'lt_6m';
    }
    if ($months < 12) {
        return '6_12m';
    }
    if ($months < 36) {
        return '1_3y';
    }
    return 'gt_3y';
}
