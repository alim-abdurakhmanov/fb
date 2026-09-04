<?php
/**
 * Серверный рендер блока аналитики (тот же состав, что assets/js/company_analytics.js).
 *
 * @param array<string,mixed>|null $data  raw: company/finance/enforcements/lawsuits
 * @param array<string,mixed>|null $finscore
 */
function finscore_render_analytics_html(?array $data, ?array $finscore): string
{
    $html = '';
    $html .= finscore_render_header_html($finscore);
    $html .= finscore_render_company_html($data['company']['data'] ?? null);
    $html .= finscore_render_finance_html($data, $finscore);
    $html .= finscore_render_enforcements_html($data['enforcements']['data'] ?? null);
    $html .= finscore_render_lawsuits_html($data['lawsuits']['data'] ?? null);
    return $html;
}

function finscore_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function finscore_money_html(mixed $value): string
{
    return number_format((float) $value, 0, '', ' ');
}

function finscore_status_circle(mixed $value, string $type = 'default'): string
{
    if ($value === null || $value === '' || $value === '-') {
        return '<span class="status-circle gray" title="Неизвестно"></span>';
    }

    if ($type === 'boolean_negative') {
        return $value
            ? '<span class="status-circle red" title="Проблема"></span>'
            : '<span class="status-circle green" title="Норма"></span>';
    }
    if ($type === 'boolean_positive') {
        if (is_string($value)) {
            return trim($value) !== ''
                ? '<span class="status-circle green" title="Норма"></span>'
                : '<span class="status-circle gray" title="Неизвестно"></span>';
        }
        return $value
            ? '<span class="status-circle green" title="Норма"></span>'
            : '<span class="status-circle red" title="Проблема"></span>';
    }

    $num = is_numeric($value) ? (float) $value : 0.0;
    if ($type === 'tax_debt') {
        return $num > 0
            ? '<span class="status-circle yellow" title="Задолженность"></span>'
            : '<span class="status-circle green" title="Нет задолженности"></span>';
    }
    if ($type === 'enforcement_debt') {
        return $num > 0
            ? '<span class="status-circle red" title="Задолженность"></span>'
            : '<span class="status-circle green" title="Нет задолженности"></span>';
    }
    if ($type === 'numeric_positive') {
        return $num > 0
            ? '<span class="status-circle green" title="Норма"></span>'
            : '<span class="status-circle yellow" title="Нет данных/нулевые"></span>';
    }
    if ($type === 'numeric_negative') {
        return $num > 0
            ? '<span class="status-circle yellow" title="Требует внимания"></span>'
            : '<span class="status-circle green" title="Норма"></span>';
    }
    if ($type === 'company_age') {
        return $num < 1
            ? '<span class="status-circle yellow" title="Молодая компания"></span>'
            : '<span class="status-circle green" title="Норма"></span>';
    }

    return '<span class="status-circle green" title="Норма"></span>';
}

function finscore_render_header_html(?array $finscore): string
{
    if (!is_array($finscore)) {
        return '';
    }

    $score = max(0, min(100, (int) ($finscore['score'] ?? 0)));
    $grade = (string) ($finscore['grade'] ?? '—');
    $gradeLabel = (string) ($finscore['grade_label'] ?? '');
    $color = (string) ($finscore['grade_color'] ?? '#2f6fed');
    $companyName = (string) ($finscore['company_name'] ?? 'Компания');
    $bg = is_array($finscore['limits']['bg'] ?? null) ? $finscore['limits']['bg'] : ['value' => 0, 'low' => 0, 'high' => 0];
    $individual = !empty($finscore['individual_only']) || !((float) ($bg['value'] ?? 0) > 0);
    $factors = is_array($finscore['factors'] ?? null) ? $finscore['factors'] : [];
    $hardStops = is_array($finscore['hard_stops'] ?? null) ? $finscore['hard_stops'] : [];
    $confidence = (string) ($finscore['confidence']['label'] ?? $finscore['confidence']['short'] ?? '');
    $metrics = is_array($finscore['metrics'] ?? null) ? $finscore['metrics'] : [];
    $finance = is_array($finscore['finance'] ?? null) ? $finscore['finance'] : [];
    $series = is_array($finance['series'] ?? null) ? $finance['series'] : [];

    $renderLimitCard = static function (string $title, float $value, float $low, float $high, bool $individual): string {
        if ($individual || !($value > 0)) {
            return '<div class="fs-limit-card">'
                . '<div class="fs-limit-title">' . finscore_h($title) . '</div>'
                . '<div class="fs-limit-value">Индивидуально</div>'
                . '<div class="fs-limit-range">Нужен ручной разбор</div>'
                . '</div>';
        }
        return '<div class="fs-limit-card">'
            . '<div class="fs-limit-title">' . finscore_h($title) . '</div>'
            . '<div class="fs-limit-value">' . finscore_money_html($value) . ' ₽</div>'
            . '<div class="fs-limit-range">Диапазон ' . finscore_money_html($low) . ' – ' . finscore_money_html($high) . ' ₽</div>'
            . '</div>';
    };

    $html = '<div class="fs-header mb-4" style="--fs-score:' . $score . ';--fs-color:' . finscore_h($color) . ';">';
    $html .= '<div class="fs-header-main">';
    $html .= '<div class="fs-ring" aria-hidden="true"><div class="fs-ring-inner">';
    $html .= '<div class="fs-grade">' . finscore_h($grade) . '</div>';
    $html .= '<div class="fs-score">' . $score . '/100</div>';
    $html .= '</div></div>';
    $html .= '<div class="fs-meta">';
    $html .= '<h5 class="mb-1">' . finscore_h($companyName) . '</h5>';
    $html .= '<div class="fs-grade-line">FinScore ' . $score . ' из 100 · класс ' . finscore_h($grade) . '</div>';
    if ($gradeLabel !== '') {
        $html .= '<div class="fs-verdict-line">' . finscore_h($gradeLabel) . '</div>';
    }
    if ($confidence !== '') {
        $html .= '<div class="fs-data-line">' . finscore_h($confidence) . '</div>';
    }
    $recommendation = (string) ($finscore['recommendation'] ?? '');
    if ($recommendation !== '' && $recommendation !== $gradeLabel) {
        $html .= '<div class="text-muted small mt-2">' . finscore_h($recommendation) . '</div>';
    }
    $html .= '</div></div>';

    $html .= '<div class="fs-limits">';
    $html .= $renderLimitCard(
        'Ориентир по БГ',
        (float) ($bg['value'] ?? 0),
        (float) ($bg['low'] ?? 0),
        (float) ($bg['high'] ?? 0),
        $individual
    );
    $html .= '</div>';

    $summary = trim((string) ($finscore['summary'] ?? ''));
    $summaryBank = trim((string) ($finscore['summary_bank'] ?? $summary));
    if ($summary !== '') {
        $html .= '<div class="fs-summary">';
        $html .= '<div class="fs-summary-head"><strong>Почему так</strong>';
        $html .= '<button type="button" class="btn btn-sm btn-outline-secondary" data-fs-copy-summary>'
            . '<i class="bi bi-clipboard me-1"></i>Скопировать резюме</button></div>';
        $html .= '<p class="fs-summary-text mb-0">' . finscore_h($summary) . '</p>';
        $html .= '<textarea class="visually-hidden" data-fs-summary-bank readonly>'
            . finscore_h($summaryBank) . '</textarea>';
        $html .= '</div>';
    }

    $html .= '<div class="fs-kpis">';
    $html .= '<div class="fs-kpi"><div class="label">Выручка' . (!empty($finance['year']) ? ' ' . (int) $finance['year'] : '') . '</div>';
    $html .= '<div class="value">' . finscore_money_html($finance['revenue'] ?? 0) . ' ₽</div></div>';
    $html .= '<div class="fs-kpi"><div class="label">Прибыль</div><div class="value">' . finscore_money_html($finance['profit'] ?? 0) . ' ₽</div></div>';
    $age = $metrics['company_age'] ?? null;
    $html .= '<div class="fs-kpi"><div class="label">Возраст</div><div class="value">'
        . ($age !== null ? (int) $age . ' лет' : '—') . '</div></div>';
    $html .= '<div class="fs-kpi"><div class="label">ФССП</div><div class="value">' . finscore_money_html($metrics['fssp_debt'] ?? 0) . ' ₽</div></div>';
    $html .= '<div class="fs-kpi"><div class="label">Арбитраж</div><div class="value">' . (int) ($metrics['law_count'] ?? 0) . ' дел</div></div>';
    $html .= '</div>';

    if ($hardStops !== []) {
        $html .= '<div class="fs-hardstops"><strong>Стоп-факторы:</strong><ul>';
        foreach ($hardStops as $stop) {
            $html .= '<li>' . finscore_h($stop) . '</li>';
        }
        $html .= '</ul></div>';
    }

    if (count($series) > 1) {
        $html .= '<div class="fs-chart-wrap"><canvas id="finscore-finance-chart" height="120"></canvas></div>';
    }

    if ($factors !== []) {
        $html .= '<div class="fs-factors-title">Ключевые факторы <span>(нажмите для деталей)</span></div><div class="fs-factors">';
        foreach ($factors as $factor) {
            $tone = (string) ($factor['tone'] ?? 'warn');
            $html .= '<button type="button" class="fs-factor" data-fs-factor>';
            $html .= '<span class="fs-tone ' . finscore_h($tone) . '"></span>';
            $html .= '<strong>' . finscore_h((string) ($factor['label'] ?? '')) . '</strong>';
            $html .= '<div class="fs-factor-detail">' . finscore_h((string) ($factor['detail'] ?? '')) . '</div>';
            $html .= '</button>';
        }
        $html .= '</div>';
    }

    $html .= '</div>';
    return $html;
}

function finscore_render_company_html(?array $d): string
{
    if (!is_array($d) || $d === []) {
        return '<div class="card mb-4"><div class="card-body"><div class="alert alert-warning mb-0">'
            . '<i class="bi bi-exclamation-triangle me-2"></i>Данные о компании не найдены</div></div></div>';
    }

    $regYears = 0;
    if (!empty($d['ДатаРег'])) {
        try {
            $regYears = (int) (new DateTimeImmutable(substr((string) $d['ДатаРег'], 0, 10)))
                ->diff(new DateTimeImmutable('today'))->y;
        } catch (Throwable $e) {
            $regYears = 0;
        }
    }
    $taxDebt = (float) ($d['Налоги']['СумНедоим'] ?? 0);

    $html = '<div class="analytics-card mb-4"><div class="analytics-card-header">';
    $html .= '<h5 class="card-title mb-0"><i class="bi bi-building me-2"></i>Основная информация</h5></div>';
    $html .= '<div class="analytics-card-body"><div class="analytics-section"><h6>Общие сведения</h6>';
    $html .= '<div class="table-responsive"><table class="table table-sm analytics-table"><tbody>';
    $html .= '<tr><td width="40">' . finscore_status_circle($d['НаимПолн'] ?? '', 'boolean_positive') . '</td>'
        . '<td><strong>Полное наименование:</strong></td><td>' . finscore_h($d['НаимПолн'] ?? '-') . '</td></tr>';
    $html .= '<tr><td>' . finscore_status_circle($regYears, 'company_age') . '</td>'
        . '<td><strong>Дата регистрации:</strong></td><td>'
        . finscore_h(!empty($d['ДатаРег']) ? date('d.m.Y', strtotime((string) $d['ДатаРег'])) : '-')
        . ' (' . $regYears . ' лет)</td></tr>';
    $html .= '<tr><td>' . finscore_status_circle($d['Регион']['Наим'] ?? '', 'boolean_positive') . '</td>'
        . '<td><strong>Регион:</strong></td><td>' . finscore_h($d['Регион']['Наим'] ?? '-') . '</td></tr>';
    $badAddr = !empty($d['ЮрАдрес']['Недост']);
    $html .= '<tr><td>' . finscore_status_circle($badAddr, 'boolean_negative') . '</td>'
        . '<td><strong>Юридический адрес недостоверен:</strong></td><td>'
        . ($badAddr ? 'Да (' . finscore_h($d['ЮрАдрес']['НедостОпис'] ?? '') . ')' : 'Нет') . '</td></tr>';
    $html .= '<tr><td>' . finscore_status_circle($taxDebt, 'tax_debt') . '</td>'
        . '<td><strong>Задолженность по налогам:</strong></td><td>'
        . finscore_money_html($taxDebt) . ' руб. (на ' . finscore_h($d['Налоги']['НедоимДата'] ?? '-') . ')</td></tr>';
    $html .= '<tr><td>' . finscore_status_circle($d['РМСП']['Кат'] ?? '', 'boolean_positive') . '</td>'
        . '<td><strong>Субъект МСП:</strong></td><td>' . finscore_h($d['РМСП']['Кат'] ?? '-') . '</td></tr>';
    $html .= '</tbody></table></div></div>';

    $html .= '<div class="analytics-section mb-0"><h6>Риски и нарушения</h6>';
    $html .= '<div class="table-responsive"><table class="table table-sm analytics-table"><tbody>';
    $mspBad = !empty($d['ПоддержМСП'][0]['Наруш']);
    $rows = [
        ['Нарушения требований МСП:', $mspBad, $mspBad ? 'Да' : 'Нет'],
        ['В реестре недобросовестных поставщиков:', !empty($d['НедобПост']), !empty($d['НедобПост']) ? 'Да' : 'Нет'],
        ['Есть дисквалифицированные лица:', !empty($d['ДисквЛица']), !empty($d['ДисквЛица']) ? 'Да' : 'Нет'],
        ['Массовые руководители:', !empty($d['МассРуковод']), !empty($d['МассРуковод']) ? 'Да' : 'Нет'],
        ['Массовые учредители:', !empty($d['МассУчред']), !empty($d['МассУчред']) ? 'Да' : 'Нет'],
        ['Финансовая нелегалка:', !empty($d['НелегалФин']), !empty($d['НелегалФин']) ? ('Да (' . (string) ($d['НелегалФинСтатус'] ?? '') . ')') : 'Нет'],
        ['Санкции:', !empty($d['Санкции']), !empty($d['Санкции']) ? 'Да' : 'Нет'],
    ];
    foreach ($rows as [$label, $bad, $text]) {
        $html .= '<tr><td width="40">' . finscore_status_circle($bad, 'boolean_negative') . '</td>'
            . '<td><strong>' . finscore_h($label) . '</strong></td><td>' . finscore_h($text) . '</td></tr>';
    }
    $html .= '</tbody></table></div></div></div></div>';
    return $html;
}

function finscore_render_finance_html(?array $data, ?array $finscore): string
{
    $series = [];
    if (is_array($finscore['finance']['series'] ?? null)) {
        $series = $finscore['finance']['series'];
    } elseif (is_array($data['finance']['data'] ?? null)) {
        $series = finscore_finance_series($data['finance']['data']);
    }
    $series = array_slice($series, -5);

    $html = '<div class="analytics-card mb-4"><div class="analytics-card-header">';
    $html .= '<h5 class="card-title mb-0"><i class="bi bi-graph-up me-2"></i>Финансовые показатели</h5></div>';
    $html .= '<div class="analytics-card-body">';
    if ($series === []) {
        $html .= '<div class="alert alert-info mb-0"><i class="bi bi-info-circle me-2"></i>Финансовая отчетность не найдена</div>';
    } else {
        $html .= '<div class="table-responsive"><table class="table table-sm analytics-finance-table mb-0"><thead><tr>';
        $html .= '<th>Год</th><th>Выручка</th><th>Прибыль</th><th>Капитал</th><th>Активы</th></tr></thead><tbody>';
        foreach (array_reverse($series) as $row) {
            $html .= '<tr><td>' . (int) ($row['year'] ?? 0) . '</td>';
            $html .= '<td>' . finscore_status_circle($row['revenue'] ?? 0, 'numeric_positive') . ' '
                . finscore_money_html($row['revenue'] ?? 0) . ' ₽</td>';
            $html .= '<td>' . finscore_status_circle($row['profit'] ?? 0, 'numeric_positive') . ' '
                . finscore_money_html($row['profit'] ?? 0) . ' ₽</td>';
            $html .= '<td>' . finscore_money_html($row['equity'] ?? 0) . ' ₽</td>';
            $html .= '<td>' . finscore_money_html($row['assets'] ?? 0) . ' ₽</td></tr>';
        }
        $html .= '</tbody></table></div>';
    }
    $html .= '</div></div>';
    return $html;
}

function finscore_render_enforcements_html(?array $e): string
{
    $html = '<div class="analytics-card mb-4"><div class="analytics-card-header">';
    $html .= '<h5 class="card-title mb-0"><i class="bi bi-shield-exclamation me-2"></i>Исполнительные производства</h5></div>';
    $html .= '<div class="analytics-card-body">';
    if (!is_array($e) || $e === []) {
        $html .= '<div class="alert alert-success mb-0"><i class="bi bi-check-circle me-2"></i>Исполнительные производства не найдены</div>';
    } else {
        $debt = (float) ($e['ОстЗадолж'] ?? $e['ОбщСум'] ?? 0);
        $count = (int) ($e['КолвоИП'] ?? $e['ОбщКолич'] ?? 0);
        $html .= '<table class="table table-sm analytics-table mb-0"><tbody>';
        $html .= '<tr><td width="40">' . finscore_status_circle($debt, 'enforcement_debt') . '</td>'
            . '<td><strong>Остаток задолженности:</strong></td><td>' . finscore_money_html($debt) . ' руб.</td></tr>';
        $html .= '<tr><td>' . finscore_status_circle($count, 'numeric_negative') . '</td>'
            . '<td><strong>Количество ИП:</strong></td><td>' . $count . '</td></tr>';
        $html .= '</tbody></table>';
    }
    $html .= '</div></div>';
    return $html;
}

function finscore_render_lawsuits_html(?array $l): string
{
    $html = '<div class="analytics-card mb-4"><div class="analytics-card-header">';
    $html .= '<h5 class="card-title mb-0"><i class="bi bi-journal-text me-2"></i>Арбитражные дела</h5></div>';
    $html .= '<div class="analytics-card-body">';
    if (!is_array($l) || $l === []) {
        $html .= '<div class="alert alert-success mb-0"><i class="bi bi-check-circle me-2"></i>Арбитражные дела не найдены</div>';
    } else {
        $count = (int) ($l['ЗапВсего'] ?? 0);
        $claim = (float) ($l['ОбщСуммИск'] ?? $l['СуммИск'] ?? 0);
        $html .= '<table class="table table-sm analytics-table mb-0"><tbody>';
        $html .= '<tr><td width="40">' . finscore_status_circle($count, 'numeric_negative') . '</td>'
            . '<td><strong>Общее количество дел:</strong></td><td>' . $count . '</td></tr>';
        $html .= '<tr><td>' . finscore_status_circle($claim, 'numeric_negative') . '</td>'
            . '<td><strong>Сумма исковых требований:</strong></td><td>' . finscore_money_html($claim) . ' руб.</td></tr>';
        $html .= '</tbody></table>';
    }
    $html .= '</div></div>';
    return $html;
}
