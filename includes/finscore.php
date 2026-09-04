<?php
/**
 * FinScore: рейтинг компании, факторы и ориентир лимита БГ/кредита.
 */
declare(strict_types=1);

require_once __DIR__ . '/checko_client.php';

/**
 * Значение показателя из обычной или extended-отчётности Checko.
 */
function finscore_metric_value(mixed $value): float
{
    if (is_numeric($value)) {
        return (float) $value;
    }
    if (is_array($value)) {
        if (isset($value['СумОтч']) && is_numeric($value['СумОтч'])) {
            return (float) $value['СумОтч'];
        }
        if (isset($value['value']) && is_numeric($value['value'])) {
            return (float) $value['value'];
        }
        if (isset($value['amount']) && is_numeric($value['amount'])) {
            return (float) $value['amount'];
        }
    }
    return 0.0;
}

/**
 * @param array<string,mixed>|null $finance
 * @return list<array{year:int,revenue:float,profit:float,assets:float,equity:float}>
 */
function finscore_finance_series(?array $finance): array
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
        // extended может класть показатели вложенно — поддержим оба варианта
        $src = $row;
        if (isset($row['БухОтчет']) && is_array($row['БухОтчет'])) {
            $src = $row['БухОтчет'];
        }
        $years[] = [
            'year' => $year,
            'revenue' => finscore_metric_value($src['2110'] ?? $src['Выручка'] ?? 0),
            'profit' => finscore_metric_value($src['2400'] ?? $src['ЧистПриб'] ?? 0),
            'assets' => finscore_metric_value($src['1600'] ?? $src['Актив'] ?? 0),
            'equity' => finscore_metric_value($src['1300'] ?? $src['Капитал'] ?? 0),
        ];
    }

    usort($years, static fn(array $a, array $b): int => $a['year'] <=> $b['year']);
    return $years;
}

/**
 * @param list<array{year:int,revenue:float,profit:float,assets:float,equity:float}> $series
 * @return array{year:int,revenue:float,profit:float,assets:float,equity:float}|null
 */
function finscore_latest_finance_year(array $series): ?array
{
    if ($series === []) {
        return null;
    }
    // Берём последний год с ненулевой выручкой, иначе последний вообще
    for ($i = count($series) - 1; $i >= 0; $i--) {
        if (($series[$i]['revenue'] ?? 0) > 0) {
            return $series[$i];
        }
    }
    return $series[count($series) - 1];
}

/**
 * @return array{score:int,grade:string,label:string,color:string}
 */
function finscore_grade(int $score): array
{
    $score = max(0, min(100, $score));
    // Подписи — вывод для менеджера, не абстрактный «профиль».
    if ($score >= 80) {
        return [
            'score' => $score,
            'grade' => 'A',
            'label' => 'Можно рассматривать в работу',
            'color' => '#1a7f4b',
        ];
    }
    if ($score >= 65) {
        return [
            'score' => $score,
            'grade' => 'B',
            'label' => 'Можно рассматривать после стандартной проверки',
            'color' => '#2f6fed',
        ];
    }
    if ($score >= 45) {
        return [
            'score' => $score,
            'grade' => 'C',
            'label' => 'Нужна дополнительная проверка',
            'color' => '#c48a00',
        ];
    }
    if ($score >= 25) {
        return [
            'score' => $score,
            'grade' => 'D',
            'label' => 'Высокий риск — только индивидуально',
            'color' => '#d9480f',
        ];
    }
    return [
        'score' => $score,
        'grade' => 'E',
        'label' => 'Автооценка не подходит — нужен ручной разбор',
        'color' => '#c92a2a',
    ];
}

/**
 * @param array{
 *   company?:?array,
 *   finance?:?array,
 *   enforcements?:?array,
 *   lawsuits?:?array,
 *   inn?:string
 * } $bundle
 * @param array{product_type?:string,existing_credits?:float} $options
 * @return array<string,mixed>
 */
function finscore_evaluate(array $bundle, array $options = []): array
{
    $company = is_array($bundle['company'] ?? null) ? $bundle['company'] : [];
    $finance = is_array($bundle['finance'] ?? null) ? $bundle['finance'] : [];
    $enforcements = is_array($bundle['enforcements'] ?? null) ? $bundle['enforcements'] : [];
    $lawsuits = is_array($bundle['lawsuits'] ?? null) ? $bundle['lawsuits'] : [];

    $productType = ($options['product_type'] ?? 'bg') === 'credit' ? 'credit' : 'bg';
    $existingCredits = max(0.0, (float) ($options['existing_credits'] ?? 0));

    $series = finscore_finance_series($finance);
    $latest = finscore_latest_finance_year($series);
    $revenue = (float) ($latest['revenue'] ?? 0);
    $profit = (float) ($latest['profit'] ?? 0);
    $assets = (float) ($latest['assets'] ?? 0);
    $equity = (float) ($latest['equity'] ?? 0);
    $financeYear = (int) ($latest['year'] ?? 0);

    $name = trim((string) ($company['НаимСокр'] ?? $company['НаимПолн'] ?? ''));
    $regDateRaw = (string) ($company['ДатаРег'] ?? '');
    $companyAge = null;
    if ($regDateRaw !== '') {
        try {
            $reg = new DateTimeImmutable(substr($regDateRaw, 0, 10));
            $companyAge = (int) $reg->diff(new DateTimeImmutable('today'))->y;
        } catch (Throwable $e) {
            $companyAge = null;
        }
    }

    $taxDebt = (float) ($company['Налоги']['СумНедоим'] ?? 0);
    $fsspDebt = (float) ($enforcements['ОстЗадолж'] ?? $enforcements['ОбщСум'] ?? 0);
    $fsspCount = (int) ($enforcements['КолвоИП'] ?? $enforcements['ОбщКолич'] ?? 0);
    $lawCount = (int) ($lawsuits['ЗапВсего'] ?? 0);
    $lawClaim = (float) ($lawsuits['ОбщСуммИск'] ?? $lawsuits['СуммИск'] ?? 0);

    $statusBad = !empty($company['Статус']['Недейств'])
        || !empty($company['Ликвид'])
        || !empty($company['Банкрот']);
    $rnp = !empty($company['НедобПост']);
    $massCeo = !empty($company['МассРуковод']);
    $massFounder = !empty($company['МассУчред']);
    $disqualified = !empty($company['ДисквЛица']);
    $illegalFin = !empty($company['НелегалФин']);
    $sanctions = !empty($company['Санкции']);
    $badAddress = !empty($company['ЮрАдрес']['Недост']);

    /** @var list<array{id:string,label:string,tone:string,detail:string}> $factors */
    $factors = [];
    $hardStops = [];

    // --- Finance score 0..35 ---
    $financeScore = 0.0;
    $dataPoints = 0;
    $dataPointsMax = 6;

    if ($revenue > 0) {
        $dataPoints++;
        if ($revenue >= 100_000_000) {
            $financeScore += 14;
            $factors[] = ['id' => 'revenue_high', 'label' => 'Высокая выручка', 'tone' => 'good', 'detail' => finscore_format_money($revenue) . ' ₽' . ($financeYear ? " ({$financeYear})" : '')];
        } elseif ($revenue >= 30_000_000) {
            $financeScore += 11;
            $factors[] = ['id' => 'revenue_ok', 'label' => 'Выручка в норме', 'tone' => 'good', 'detail' => finscore_format_money($revenue) . ' ₽' . ($financeYear ? " ({$financeYear})" : '')];
        } elseif ($revenue >= 5_000_000) {
            $financeScore += 7;
            $factors[] = ['id' => 'revenue_mid', 'label' => 'Умеренная выручка', 'tone' => 'warn', 'detail' => finscore_format_money($revenue) . ' ₽' . ($financeYear ? " ({$financeYear})" : '')];
        } else {
            $financeScore += 3;
            $factors[] = ['id' => 'revenue_low', 'label' => 'Низкая выручка', 'tone' => 'warn', 'detail' => finscore_format_money($revenue) . ' ₽' . ($financeYear ? " ({$financeYear})" : '')];
        }
    } else {
        $factors[] = ['id' => 'revenue_missing', 'label' => 'Нет выручки в отчётности', 'tone' => 'bad', 'detail' => 'Нужен ручной/индивидуальный расчёт'];
    }

    if ($latest !== null) {
        $dataPoints++;
        if ($profit > 0) {
            $financeScore += 8;
            $factors[] = ['id' => 'profit_ok', 'label' => 'Прибыль', 'tone' => 'good', 'detail' => finscore_format_money($profit) . ' ₽'];
        } elseif ($profit < 0) {
            $financeScore += 1;
            $factors[] = ['id' => 'profit_loss', 'label' => 'Убыток', 'tone' => 'bad', 'detail' => finscore_format_money($profit) . ' ₽'];
        } else {
            $financeScore += 3;
            $factors[] = ['id' => 'profit_zero', 'label' => 'Нулевая прибыль', 'tone' => 'warn', 'detail' => '0 ₽'];
        }
    }

    // Динамика выручки
    if (count($series) >= 2) {
        $dataPoints++;
        $prev = $series[count($series) - 2];
        $curr = $series[count($series) - 1];
        if (($prev['revenue'] ?? 0) > 0) {
            $growth = (($curr['revenue'] - $prev['revenue']) / $prev['revenue']) * 100;
            if ($growth >= 10) {
                $financeScore += 8;
                $factors[] = ['id' => 'growth_up', 'label' => 'Рост выручки', 'tone' => 'good', 'detail' => sprintf('%+.0f%%', $growth)];
            } elseif ($growth >= -10) {
                $financeScore += 5;
                $factors[] = ['id' => 'growth_flat', 'label' => 'Стабильная выручка', 'tone' => 'good', 'detail' => sprintf('%+.0f%%', $growth)];
            } else {
                $financeScore += 1;
                $factors[] = ['id' => 'growth_down', 'label' => 'Падение выручки', 'tone' => 'bad', 'detail' => sprintf('%+.0f%%', $growth)];
            }
        }
    }

    if ($equity > 0) {
        $dataPoints++;
        $financeScore += min(5, 2 + ($equity / 50_000_000) * 3);
    }

    $financeScore = min(35, $financeScore);

    // --- Stability 0..20 ---
    $stabilityScore = 10.0;
    if ($companyAge !== null) {
        $dataPoints++;
        if ($companyAge >= 5) {
            $stabilityScore += 8;
            $factors[] = ['id' => 'age_ok', 'label' => 'Возраст компании', 'tone' => 'good', 'detail' => $companyAge . ' лет'];
        } elseif ($companyAge >= 2) {
            $stabilityScore += 4;
            $factors[] = ['id' => 'age_mid', 'label' => 'Возраст компании', 'tone' => 'warn', 'detail' => $companyAge . ' лет'];
        } else {
            $stabilityScore -= 4;
            $factors[] = ['id' => 'age_young', 'label' => 'Молодая компания', 'tone' => 'warn', 'detail' => $companyAge . ' лет'];
        }
    }
    if (!empty($company['РМСП']['Кат'])) {
        $stabilityScore += 2;
        $factors[] = ['id' => 'msp', 'label' => 'Субъект МСП', 'tone' => 'good', 'detail' => (string) $company['РМСП']['Кат']];
    }
    if ($badAddress) {
        $stabilityScore -= 6;
        $factors[] = ['id' => 'bad_address', 'label' => 'Недостоверный адрес', 'tone' => 'bad', 'detail' => (string) ($company['ЮрАдрес']['НедостОпис'] ?? 'Да')];
    }
    $stabilityScore = max(0, min(20, $stabilityScore));

    // --- Debts 0..20 ---
    $debtScore = 20.0;
    $dataPoints++;
    if ($taxDebt > 1_000_000) {
        $debtScore -= 12;
        $factors[] = ['id' => 'tax_high', 'label' => 'Задолженность по налогам', 'tone' => 'bad', 'detail' => finscore_format_money($taxDebt) . ' ₽'];
    } elseif ($taxDebt > 0) {
        $debtScore -= 5;
        $factors[] = ['id' => 'tax_low', 'label' => 'Задолженность по налогам', 'tone' => 'warn', 'detail' => finscore_format_money($taxDebt) . ' ₽'];
    } else {
        $factors[] = ['id' => 'tax_ok', 'label' => 'Задолженности по налогам нет', 'tone' => 'good', 'detail' => '0 ₽'];
    }

    $dataPoints++;
    if ($fsspDebt > 1_000_000 || $fsspCount >= 5) {
        $debtScore -= 12;
        $factors[] = ['id' => 'fssp_high', 'label' => 'Долги ФССП', 'tone' => 'bad', 'detail' => finscore_format_money($fsspDebt) . ' ₽ · ИП: ' . $fsspCount];
    } elseif ($fsspDebt > 0 || $fsspCount > 0) {
        $debtScore -= 5;
        $factors[] = ['id' => 'fssp_low', 'label' => 'Есть ФССП', 'tone' => 'warn', 'detail' => finscore_format_money($fsspDebt) . ' ₽ · ИП: ' . $fsspCount];
    } else {
        $factors[] = ['id' => 'fssp_ok', 'label' => 'ФССП чисто', 'tone' => 'good', 'detail' => 'Нет производств'];
    }
    $debtScore = max(0, min(20, $debtScore));

    // --- Courts 0..10 ---
    $courtScore = 10.0;
    if ($lawCount > 20 || $lawClaim > 10_000_000) {
        $courtScore = 2;
        $factors[] = ['id' => 'law_high', 'label' => 'Активный арбитраж', 'tone' => 'bad', 'detail' => $lawCount . ' дел · ' . finscore_format_money($lawClaim) . ' ₽'];
    } elseif ($lawCount > 5 || $lawClaim > 1_000_000) {
        $courtScore = 5;
        $factors[] = ['id' => 'law_mid', 'label' => 'Есть арбитраж', 'tone' => 'warn', 'detail' => $lawCount . ' дел · ' . finscore_format_money($lawClaim) . ' ₽'];
    } else {
        $factors[] = ['id' => 'law_ok', 'label' => 'Арбитраж умеренный', 'tone' => 'good', 'detail' => $lawCount . ' дел'];
    }

    // --- Reputation 0..15 ---
    $repScore = 15.0;
    if ($rnp) {
        $repScore -= 10;
        $hardStops[] = 'Компания в реестре недобросовестных поставщиков';
        $factors[] = ['id' => 'rnp', 'label' => 'РНП', 'tone' => 'bad', 'detail' => 'Реестр недобросовестных поставщиков'];
    }
    if ($massCeo || $massFounder) {
        $repScore -= 4;
        $factors[] = ['id' => 'mass', 'label' => 'Массовый руководитель/учредитель', 'tone' => 'warn', 'detail' => 'Признак риска'];
    }
    if ($disqualified) {
        $repScore -= 6;
        $factors[] = ['id' => 'disq', 'label' => 'Дисквалифицированные лица', 'tone' => 'bad', 'detail' => 'Да'];
    }
    if ($illegalFin) {
        $repScore -= 8;
        $hardStops[] = 'Признаки финансовой нелегальности';
        $factors[] = ['id' => 'illegal', 'label' => 'Финансовая нелегалка', 'tone' => 'bad', 'detail' => (string) ($company['НелегалФинСтатус'] ?? 'Да')];
    }
    if ($sanctions) {
        $repScore -= 8;
        $hardStops[] = 'Санкционные ограничения';
        $factors[] = ['id' => 'sanctions', 'label' => 'Санкции', 'tone' => 'bad', 'detail' => 'Да'];
    }
    if ($statusBad) {
        $repScore = 0;
        $hardStops[] = 'Компания ликвидируется / недействующая / банкротство';
        $factors[] = ['id' => 'status_bad', 'label' => 'Критический статус', 'tone' => 'bad', 'detail' => 'Ликвидация / банкротство'];
    }
    $repScore = max(0, min(15, $repScore));

    $rawScore = (int) round($financeScore + $stabilityScore + $debtScore + $courtScore + $repScore);
    if ($hardStops !== []) {
        $rawScore = min($rawScore, 24);
    }
    $gradeInfo = finscore_grade($rawScore);

    // Полнота данных: предупреждение только если чего-то не хватает.
    $confidenceRatio = $dataPointsMax > 0 ? $dataPoints / $dataPointsMax : 0;
    if ($confidenceRatio >= 0.75) {
        $confidence = [
            'level' => 'high',
            'label' => '',
            'short' => '',
            'ratio' => $confidenceRatio,
        ];
    } elseif ($confidenceRatio >= 0.45) {
        $confidence = [
            'level' => 'medium',
            'label' => 'Часть данных недоступна — оценка предварительная',
            'short' => 'оценка предварительная',
            'ratio' => $confidenceRatio,
        ];
    } else {
        $confidence = [
            'level' => 'low',
            'label' => 'Мало данных — оценка ориентировочная',
            'short' => 'оценка ориентировочная',
            'ratio' => $confidenceRatio,
        ];
    }

    // Limit calculation
    $base = $revenue > 0 ? $revenue / 3 : 0.0;
    if ($profit < 0) {
        $base *= 0.7;
    }
    if ($companyAge !== null && $companyAge < 2) {
        $base *= 0.6;
    }

    $scoreMult = 0.35 + ($rawScore / 100) * 0.9; // 0.35..1.25
    $bgLimit = max(0, round($base * $scoreMult, -3));
    $creditLimit = max(0, round(($base * $scoreMult) - $existingCredits - ($fsspDebt * 0.5), -3));

    if ($hardStops !== [] || $revenue <= 0 || $rawScore < 25) {
        $bgLimit = min($bgLimit, 0);
        $creditLimit = min($creditLimit, 0);
        $individualOnly = true;
    } else {
        $individualOnly = $bgLimit < 50000;
    }

    $activeLimit = $productType === 'credit' ? $creditLimit : $bgLimit;
    $rangeLow = $activeLimit > 0 ? max(0, round($activeLimit * 0.75, -3)) : 0;
    $rangeHigh = $activeLimit > 0 ? round($activeLimit * 1.25, -3) : 0;

    // Keep top factors: prefer bad/warn first then good, max 8
    usort($factors, static function (array $a, array $b): int {
        $order = ['bad' => 0, 'warn' => 1, 'good' => 2];
        return ($order[$a['tone']] ?? 9) <=> ($order[$b['tone']] ?? 9);
    });
    $factors = array_slice($factors, 0, 8);

    $resultDraft = [
        'inn' => (string) ($bundle['inn'] ?? ''),
        'company_name' => $name !== '' ? $name : 'Компания',
        'product_type' => $productType,
        'score' => $gradeInfo['score'],
        'grade' => $gradeInfo['grade'],
        'grade_label' => $gradeInfo['label'],
        'grade_color' => $gradeInfo['color'],
        'confidence' => $confidence,
        'factors' => $factors,
        'hard_stops' => $hardStops,
        'individual_only' => $individualOnly,
        'finance' => [
            'year' => $financeYear,
            'revenue' => $revenue,
            'profit' => $profit,
            'assets' => $assets,
            'equity' => $equity,
            'series' => $series,
        ],
        'metrics' => [
            'company_age' => $companyAge,
            'tax_debt' => $taxDebt,
            'fssp_debt' => $fsspDebt,
            'fssp_count' => $fsspCount,
            'law_count' => $lawCount,
            'law_claim' => $lawClaim,
        ],
        'limits' => [
            'bg' => [
                'value' => (float) $bgLimit,
                'low' => (float) ($bgLimit > 0 ? max(0, round($bgLimit * 0.75, -3)) : 0),
                'high' => (float) ($bgLimit > 0 ? round($bgLimit * 1.25, -3) : 0),
            ],
            'credit' => [
                'value' => (float) $creditLimit,
                'low' => (float) ($creditLimit > 0 ? max(0, round($creditLimit * 0.75, -3)) : 0),
                'high' => (float) ($creditLimit > 0 ? round($creditLimit * 1.25, -3) : 0),
            ],
            'active' => [
                'value' => (float) $activeLimit,
                'low' => (float) $rangeLow,
                'high' => (float) $rangeHigh,
            ],
        ],
        'existing_credits' => $existingCredits,
        'recommendation' => $individualOnly
            ? 'Автолимит недоступен — запросите индивидуальный расчёт'
            : ($gradeInfo['label'] ?? 'Нужна дополнительная проверка'),
    ];

    $summaries = finscore_build_summaries($resultDraft);
    $resultDraft['summary'] = $summaries['summary'];
    $resultDraft['summary_items'] = $summaries['summary_items'];
    $resultDraft['summary_bank'] = $summaries['summary_bank'];

    return $resultDraft;
}

function finscore_format_money(float $value): string
{
    return number_format($value, 0, '.', ' ');
}

/**
 * Короткое резюме для UI и текст для копирования в банк.
 *
 * @param array<string,mixed> $result
 * @return array{summary:string,summary_items:list<array{label:string,text:string}>,summary_bank:string}
 */
function finscore_build_summaries(array $result): array
{
    $name = (string) ($result['company_name'] ?? 'Компания');
    $inn = (string) ($result['inn'] ?? '');
    $score = (int) ($result['score'] ?? 0);
    $grade = (string) ($result['grade'] ?? '—');
    $gradeLabel = (string) ($result['grade_label'] ?? '');
    $individual = !empty($result['individual_only']);
    $hardStops = is_array($result['hard_stops'] ?? null) ? $result['hard_stops'] : [];
    $factors = is_array($result['factors'] ?? null) ? $result['factors'] : [];
    $finance = is_array($result['finance'] ?? null) ? $result['finance'] : [];
    $metrics = is_array($result['metrics'] ?? null) ? $result['metrics'] : [];
    $bg = is_array($result['limits']['bg'] ?? null) ? $result['limits']['bg'] : [];
    $confidenceLabel = (string) ($result['confidence']['label'] ?? '');

    $negatives = [];
    $positives = [];
    foreach ($factors as $factor) {
        if (!is_array($factor)) {
            continue;
        }
        $label = trim((string) ($factor['label'] ?? ''));
        $detail = trim((string) ($factor['detail'] ?? ''));
        if ($label === '') {
            continue;
        }
        $piece = $detail !== '' ? $label . ' (' . $detail . ')' : $label;
        $tone = (string) ($factor['tone'] ?? '');
        if ($tone === 'bad' || $tone === 'warn') {
            $negatives[] = $piece;
        } elseif ($tone === 'good') {
            $positives[] = $piece;
        }
    }

    $items = [];
    $items[] = [
        'label' => 'Оценка',
        'text' => sprintf('FinScore %d из 100 · класс %s — %s', $score, $grade, rtrim($gradeLabel, '.')),
    ];

    if ($hardStops !== []) {
        $items[] = [
            'label' => 'Стоп-факторы',
            'text' => implode('; ', array_slice($hardStops, 0, 3)),
        ];
    } elseif ($negatives !== []) {
        $items[] = [
            'label' => 'На что обратить внимание',
            'text' => implode('; ', array_slice($negatives, 0, 3)),
        ];
    } elseif ($positives !== []) {
        $items[] = [
            'label' => 'Сильные стороны',
            'text' => implode('; ', array_slice($positives, 0, 3)),
        ];
    }

    if ($individual) {
        $items[] = [
            'label' => 'Лимит БГ',
            'text' => 'Автолимит недоступен — нужен индивидуальный расчёт',
        ];
    } else {
        $items[] = [
            'label' => 'Лимит БГ',
            'text' => sprintf(
                '%s ₽ · диапазон %s – %s ₽',
                finscore_format_money((float) ($bg['value'] ?? 0)),
                finscore_format_money((float) ($bg['low'] ?? 0)),
                finscore_format_money((float) ($bg['high'] ?? 0))
            ),
        ];
    }

    if ($confidenceLabel !== '') {
        $items[] = [
            'label' => 'Данные',
            'text' => rtrim($confidenceLabel, '.'),
        ];
    }

    $items = array_slice($items, 0, 4);
    $summaryLines = [];
    foreach ($items as $item) {
        $summaryLines[] = $item['label'] . ': ' . $item['text'] . '.';
    }
    $summary = implode(' ', $summaryLines);

    // Расширенный текст для банка / мессенджера
    $bankLines = [];
    $bankLines[] = 'FinBuild · краткое резюме по компании';
    $bankLines[] = $name . ($inn !== '' ? ' · ИНН ' . $inn : '');
    $bankLines[] = sprintf('FinScore: %d/100 · класс %s', $score, $grade);
    $bankLines[] = 'Вывод: ' . $gradeLabel;

    $rev = (float) ($finance['revenue'] ?? 0);
    $profit = (float) ($finance['profit'] ?? 0);
    $year = (int) ($finance['year'] ?? 0);
    if ($rev > 0 || $profit != 0.0) {
        $bankLines[] = sprintf(
            'Финансы%s: выручка %s ₽, прибыль %s ₽',
            $year > 0 ? ' ' . $year : '',
            finscore_format_money($rev),
            finscore_format_money($profit)
        );
    }

    $age = $metrics['company_age'] ?? null;
    $tax = (float) ($metrics['tax_debt'] ?? 0);
    $fssp = (float) ($metrics['fssp_debt'] ?? 0);
    $lawCount = (int) ($metrics['law_count'] ?? 0);
    $bankLines[] = sprintf(
        'Возраст: %s · налоги: %s ₽ · ФССП: %s ₽ · арбитраж: %d дел',
        $age !== null ? ((int) $age . ' лет') : 'н/д',
        finscore_format_money($tax),
        finscore_format_money($fssp),
        $lawCount
    );

    if ($hardStops !== []) {
        $bankLines[] = 'Стоп-факторы: ' . implode('; ', $hardStops);
    }
    if ($negatives !== []) {
        $bankLines[] = 'Риски: ' . implode('; ', array_slice($negatives, 0, 5));
    }
    if ($positives !== []) {
        $bankLines[] = 'Плюсы: ' . implode('; ', array_slice($positives, 0, 4));
    }

    if ($individual) {
        $bankLines[] = 'Лимит: только индивидуально';
    } else {
        $bankLines[] = sprintf(
            'Ориентир БГ: %s ₽ (%s – %s)',
            finscore_format_money((float) ($bg['value'] ?? 0)),
            finscore_format_money((float) ($bg['low'] ?? 0)),
            finscore_format_money((float) ($bg['high'] ?? 0))
        );
    }

    if ($confidenceLabel !== '') {
        $bankLines[] = 'Данные: ' . $confidenceLabel;
    }

    $bankLines[] = 'Сформировано автоматически по открытым данным (Checko). Требует проверки менеджером.';

    return [
        'summary' => $summary,
        'summary_items' => $items,
        'summary_bank' => implode("\n", $bankLines),
    ];
}

/**
 * Полный цикл: Checko → FinScore.
 *
 * @param array{product_type?:string,existing_credits?:float} $options
 * @return array{ok:bool,error?:string,warnings?:list<string>,result?:array<string,mixed>,raw?:array<string,mixed>}
 */
function finscore_build_for_inn(string $inn, array $options = []): array
{
    $inn = preg_replace('/\D+/', '', $inn) ?? '';
    if (!preg_match('/^\d{10,12}$/', $inn)) {
        return ['ok' => false, 'error' => 'Некорректный ИНН'];
    }

    $bundle = finbuild_checko_fetch_intelligence($inn, true);
    $result = finscore_evaluate([
        'inn' => $inn,
        'company' => $bundle['company'],
        'finance' => $bundle['finance'],
        'enforcements' => $bundle['enforcements'],
        'lawsuits' => $bundle['lawsuits'],
    ], $options);

    return [
        'ok' => true,
        'warnings' => $bundle['warnings'],
        'result' => $result,
        'raw' => [
            'company' => ['data' => $bundle['company'] ?? []],
            'finance' => ['data' => $bundle['finance'] ?? []],
            'enforcements' => ['data' => $bundle['enforcements'] ?? []],
            'lawsuits' => ['data' => $bundle['lawsuits'] ?? []],
        ],
    ];
}
