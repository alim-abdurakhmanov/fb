<?php
/**
 * Правила внутрибанковской методики экспресс-БГ (Камкомбанк / ТЗ портала ЭБГ).
 * Источник: методические указания 2023 + ТЗ «Сервис ЭБГ».
 */
declare(strict_types=1);

/**
 * @return array<string,mixed>
 */
function bank_methodology_rules(): array
{
    static $rules = null;
    if (is_array($rules)) {
        return $rules;
    }

    $rules = [
        'meta' => [
            'title' => 'Внутрибанковская оценка кредитоспособности (экспресс-БГ)',
            'version' => '2023-kamkombank',
            'max_total' => 100,
            'max_finance' => 50,
            'max_business' => 50,
        ],

        'stop_groups' => [
            '1' => '1. Статус Принципала',
            '2' => '2. Участники в уставном капитале',
            '3' => '3. Реестр дисквалифицированных лиц',
            '4' => '4. Реестр недобросовестных поставщиков (РНП)',
            '5' => '5. Регионы повышенного риска',
            '6' => '6. Судебный запрет для ЕИО',
            '7' => '7. Экстремизм / терроризм',
            '8' => '8. Недействительные паспорта',
            '9' => '9. Арбитраж и ФССП',
            '10' => '10. Бенефициар (223-ФЗ)',
            '11' => '11. ОФМ / 115-ФЗ',
            '12' => '12. ЗСК ЦБ',
            '13' => '13. Коды 764-П',
            '14' => '14. Заключение службы безопасности',
            '15' => '15. Аффилированность принципал–бенефициар',
            'conditional' => 'Условные стоп-факторы',
        ],

        'stop_factors' => [
            ['code' => '1.1', 'group' => '1', 'label' => 'Недействующее ЮЛ (нет данных в ЕГРЮЛ)', 'mandatory' => true],
            ['code' => '1.2', 'group' => '1', 'label' => 'Решение об исключении из ЕГРЮЛ', 'mandatory' => true],
            ['code' => '1.3', 'group' => '1', 'label' => 'Ликвидация / банкротство Принципала', 'mandatory' => true],
            ['code' => '1.4', 'group' => '1', 'label' => 'Реорганизация в форме присоединения', 'mandatory' => true],
            ['code' => '1.5', 'group' => '1', 'label' => 'Срок деятельности менее 6 месяцев', 'mandatory' => true],
            ['code' => '2.1', 'group' => '2', 'label' => 'Недействующие / ликвидированные ЮЛ', 'mandatory' => true],
            ['code' => '2.2', 'group' => '2', 'label' => 'ЮЛ с решением об исключении из ЕГРЮЛ', 'mandatory' => true],
            ['code' => '2.3', 'group' => '2', 'label' => 'ЮЛ в банкротстве', 'mandatory' => true],
            ['code' => '2.4', 'group' => '2', 'label' => 'ЮЛ с намерением банкротства', 'mandatory' => true],
            ['code' => '2.5', 'group' => '2', 'label' => 'ЮЛ с реорганизацией (присоединение)', 'mandatory' => true],
            ['code' => '3', 'group' => '3', 'label' => 'В реестре дисквалифицированных лиц', 'mandatory' => true],
            ['code' => '4', 'group' => '4', 'label' => 'В реестре недобросовестных поставщиков (РНП)', 'mandatory' => true],
            ['code' => '5.1', 'group' => '5', 'label' => 'Бенефициар <50% (не ЕИО) в регионе повышенного риска (короткий перечень)', 'mandatory' => true],
            ['code' => '5.2', 'group' => '5', 'label' => 'Бенефициар ≥50% или ЕИО в регионе повышенного риска (расширенный перечень)', 'mandatory' => true],
            ['code' => '5.3', 'group' => '5', 'label' => 'Клиент / предмет тендера в регионе риска или за рубежом', 'mandatory' => true],
            ['code' => '6', 'group' => '6', 'label' => 'Судебный запрет на участие/руководство для ЕИО', 'mandatory' => true],
            ['code' => '7', 'group' => '7', 'label' => 'Экстремизм / терроризм (руководитель, учредитель или компания)', 'mandatory' => true],
            ['code' => '8', 'group' => '8', 'label' => 'Недействительные паспорта руководителя / учредителей', 'mandatory' => true],
            ['code' => '9', 'group' => '9', 'label' => 'Арбитраж + ФССП > 35% годовой выручки', 'mandatory' => true],
            ['code' => '10', 'group' => '10', 'label' => 'Бенефициар не из допустимых категорий (для 223-ФЗ)', 'mandatory' => true],
            ['code' => '11', 'group' => '11', 'label' => 'Чёрная зона ОФМ / расторжение 115-ФЗ', 'mandatory' => true],
            ['code' => '12', 'group' => '12', 'label' => 'ЗСК ЦБ — красная зона', 'mandatory' => true],
            ['code' => '13', 'group' => '13', 'label' => 'Коды 764-П: 07/08 (>5) или 09 (1) за 12 мес.', 'mandatory' => true],
            ['code' => '14', 'group' => '14', 'label' => 'Отрицательное заключение СБ по прошлым заявкам', 'mandatory' => true],
            ['code' => '15', 'group' => '15', 'label' => 'Аффилированность принципал–бенефициар (615-П / коммерция)', 'mandatory' => true],
            ['code' => 'C1', 'group' => 'conditional', 'label' => 'Задолженность по налогам и сборам', 'mandatory' => false],
            ['code' => 'C2', 'group' => 'conditional', 'label' => 'Приостановление операций по счетам (ФНС)', 'mandatory' => false],
            ['code' => 'C3', 'group' => 'conditional', 'label' => 'Негативные сведения Федресурса', 'mandatory' => false],
        ],

        'finance_groups' => [
            'profitability' => ['label' => 'Рентабельность', 'weight' => 36.0, 'max' => 18],
            'liquidity' => ['label' => 'Ликвидность', 'weight' => 18.0, 'max' => 9],
            'stability' => ['label' => 'Финансовая устойчивость', 'weight' => 28.0, 'max' => 14],
            'coverage' => ['label' => 'Покрытие обязательств', 'weight' => 18.0, 'max' => 9],
        ],

        /**
         * bands: от лучших к худшим.
         * mode default: value >= lo && value < hi (hi=null → +∞)
         * mode debt: value > lo && value <= hi; value==0 → max score
         * mode zero_score: value === 0 → score 0 (рентабельность)
         */
        'finance_metrics' => [
            'total_profitability' => [
                'id' => 'total_profitability',
                'group' => 'profitability',
                'label' => 'Общая рентабельность, %',
                'unit' => '%',
                'max' => 9,
                'weight' => 18.0,
                'mode' => 'pct_zero',
                'bands' => [
                    ['lo' => 8, 'hi' => null, 'score' => 9],
                    ['lo' => 5, 'hi' => 8, 'score' => 7],
                    ['lo' => 2.5, 'hi' => 5, 'score' => 4],
                    ['lo' => 0, 'hi' => 2.5, 'score' => 2],
                    ['lo' => -5, 'hi' => 0, 'score' => -4],
                    ['lo' => -8, 'hi' => -5, 'score' => -7],
                    ['lo' => null, 'hi' => -8, 'score' => -9],
                ],
                'explained_zero_score' => 0,
            ],
            'roe' => [
                'id' => 'roe',
                'group' => 'profitability',
                'label' => 'Рентабельность собственного капитала, %',
                'unit' => '%',
                'max' => 9,
                'weight' => 18.0,
                'mode' => 'pct_zero',
                'bands' => [
                    ['lo' => 15, 'hi' => null, 'score' => 9],
                    ['lo' => 10, 'hi' => 15, 'score' => 7],
                    ['lo' => 5, 'hi' => 10, 'score' => 4],
                    ['lo' => 0, 'hi' => 5, 'score' => 2],
                    ['lo' => -5, 'hi' => 0, 'score' => -4],
                    ['lo' => -10, 'hi' => -5, 'score' => -7],
                    ['lo' => null, 'hi' => -10, 'score' => -9],
                ],
                'explained_zero_score' => 0,
                'missing_equity_score' => -4,
            ],
            'current_liquidity' => [
                'id' => 'current_liquidity',
                'group' => 'liquidity',
                'label' => 'Текущая ликвидность',
                'unit' => '',
                'max' => 9,
                'weight' => 18.0,
                'mode' => 'default',
                'bands' => [
                    ['lo' => 1.7, 'hi' => null, 'score' => 9],
                    ['lo' => 1.5, 'hi' => 1.7, 'score' => 8],
                    ['lo' => 1.3, 'hi' => 1.5, 'score' => 6],
                    ['lo' => 1.2, 'hi' => 1.3, 'score' => 4],
                    ['lo' => 1.1, 'hi' => 1.2, 'score' => 3],
                    ['lo' => 1.0, 'hi' => 1.1, 'score' => 2],
                    ['lo' => 0.9, 'hi' => 1.0, 'score' => 0],
                    ['lo' => 0.8, 'hi' => 0.9, 'score' => -2],
                    ['lo' => 0.7, 'hi' => 0.8, 'score' => -3],
                    ['lo' => 0.6, 'hi' => 0.7, 'score' => -4],
                    ['lo' => 0.55, 'hi' => 0.6, 'score' => -6],
                    ['lo' => 0.5, 'hi' => 0.55, 'score' => -8],
                    ['lo' => null, 'hi' => 0.5, 'score' => -9],
                ],
                'no_current_liabilities_score' => 6,
            ],
            'independence' => [
                'id' => 'independence',
                'group' => 'stability',
                'label' => 'Коэффициент независимости (СК / валюта баланса)',
                'unit' => '',
                'max' => 7,
                'weight' => 14.0,
                'mode' => 'default',
                'bands' => [
                    ['lo' => 0.45, 'hi' => null, 'score' => 7],
                    ['lo' => 0.40, 'hi' => 0.45, 'score' => 6],
                    ['lo' => 0.35, 'hi' => 0.40, 'score' => 4],
                    ['lo' => 0.30, 'hi' => 0.35, 'score' => 3],
                    ['lo' => 0.25, 'hi' => 0.30, 'score' => 2],
                    ['lo' => 0.0, 'hi' => 0.25, 'score' => 0],
                    ['lo' => null, 'hi' => 0.0, 'score' => -7],
                ],
            ],
            'financial_stability' => [
                'id' => 'financial_stability',
                'group' => 'stability',
                'label' => 'Коэффициент финансовой устойчивости',
                'unit' => '',
                'max' => 7,
                'weight' => 14.0,
                'mode' => 'default',
                'bands' => [
                    ['lo' => 0.55, 'hi' => null, 'score' => 7],
                    ['lo' => 0.50, 'hi' => 0.55, 'score' => 6],
                    ['lo' => 0.45, 'hi' => 0.50, 'score' => 4],
                    ['lo' => 0.40, 'hi' => 0.45, 'score' => 3],
                    ['lo' => 0.35, 'hi' => 0.40, 'score' => 2],
                    ['lo' => 0.02, 'hi' => 0.35, 'score' => 0],
                    ['lo' => null, 'hi' => 0.02, 'score' => -7],
                ],
            ],
            'debt_to_revenue' => [
                'id' => 'debt_to_revenue',
                'group' => 'coverage',
                'label' => 'Коэффициент отношения общей задолженности к выручке',
                'unit' => '',
                'max' => 9,
                'weight' => 18.0,
                'mode' => 'debt',
                'bands' => [
                    ['lo' => 0.0, 'hi' => 0.3, 'score' => 9],
                    ['lo' => 0.3, 'hi' => 0.4, 'score' => 8],
                    ['lo' => 0.4, 'hi' => 0.5, 'score' => 6],
                    ['lo' => 0.5, 'hi' => 0.6, 'score' => 4],
                    ['lo' => 0.6, 'hi' => 0.7, 'score' => 3],
                    ['lo' => 0.7, 'hi' => 0.8, 'score' => 2],
                    ['lo' => 0.8, 'hi' => 0.9, 'score' => 0],
                    ['lo' => 0.9, 'hi' => 1.0, 'score' => -2],
                    ['lo' => 1.0, 'hi' => 1.1, 'score' => -3],
                    ['lo' => 1.1, 'hi' => 1.2, 'score' => -4],
                    ['lo' => 1.2, 'hi' => 1.25, 'score' => -6],
                    ['lo' => 1.25, 'hi' => 1.3, 'score' => -8],
                    ['lo' => 1.3, 'hi' => null, 'score' => -9],
                ],
                'no_revenue_score' => -6,
                'alt_industry' => [
                    'leasing' => true,
                    'factoring' => true,
                    'max' => 6,
                    'weight' => 12.0,
                    'bands' => [
                        ['lo' => 0.0, 'hi' => 2.0, 'score' => 6],
                        ['lo' => 2.0, 'hi' => 3.0, 'score' => 5],
                        ['lo' => 3.0, 'hi' => 4.0, 'score' => 4],
                        ['lo' => 4.0, 'hi' => 5.0, 'score' => 3],
                        ['lo' => 5.0, 'hi' => 6.0, 'score' => 2],
                        ['lo' => 6.0, 'hi' => 7.0, 'score' => 1],
                        ['lo' => 7.0, 'hi' => 8.0, 'score' => -2],
                        ['lo' => 8.0, 'hi' => 10.0, 'score' => -4],
                        ['lo' => 10.0, 'hi' => null, 'score' => -6],
                    ],
                ],
            ],
        ],

        'business_metrics' => [
            'credit_history' => [
                'id' => 'credit_history',
                'label' => 'Зона кредитной истории Клиента',
                'max' => 10,
                'weight' => 20.0,
                'manual_only' => true,
                'options' => [
                    ['value' => 'white', 'label' => 'Белая (положительная)', 'score' => 10],
                    ['value' => 'absent', 'label' => 'Отсутствует', 'score' => 0],
                    ['value' => 'grey', 'label' => 'Серая', 'score' => -5],
                    ['value' => 'black', 'label' => 'Чёрная (отрицательная)', 'score' => -10],
                ],
                'hint' => 'По данным 3 БКИ (НБКИ, ОКБ, Эквифакс). Отрицательная: просрочки >5 дней за 180 дней.',
            ],
            'company_age' => [
                'id' => 'company_age',
                'label' => 'Срок осуществления деятельности',
                'max' => 5,
                'weight' => 10.0,
                'options' => [
                    ['value' => 'lt_6m', 'label' => 'Менее 6 месяцев', 'score' => -5],
                    ['value' => '6_12m', 'label' => 'От 6 до 12 месяцев', 'score' => 2],
                    ['value' => '1_3y', 'label' => 'От 1 до 3 лет', 'score' => 3],
                    ['value' => 'gt_3y', 'label' => 'Свыше 3 лет', 'score' => 5],
                ],
                'hint' => 'Верхняя граница градации не включается.',
            ],
            'comparable_contracts' => [
                'id' => 'comparable_contracts',
                'label' => 'Опыт контрактов ≥ сумме текущего контракта',
                'max' => 6,
                'weight' => 12.0,
                'options' => [
                    ['value' => 'none', 'label' => 'Не имеется / нет данных', 'score' => 0],
                    ['value' => 'exists', 'label' => 'Имеется', 'score' => 6],
                ],
            ],
            'gov_contracts' => [
                'id' => 'gov_contracts',
                'label' => 'Опыт госконтрактов / муниципальных контрактов',
                'max' => 10,
                'weight' => 20.0,
                'options' => [
                    ['value' => 'none', 'label' => 'Не имеется / нет данных', 'score' => 0],
                    ['value' => '1_3', 'label' => 'От 1 до 3 контрактов', 'score' => 4],
                    ['value' => '3_10', 'label' => 'От 3 до 10 контрактов', 'score' => 8],
                    ['value' => 'gt_10', 'label' => 'Свыше 10 контрактов', 'score' => 10],
                ],
            ],
            'ownership_stability' => [
                'id' => 'ownership_stability',
                'label' => 'Стабильность собственников (≥25% за год)',
                'max' => 5,
                'weight' => 10.0,
                'options' => [
                    ['value' => 'stable', 'label' => 'Без изменений / изменения у <25%', 'score' => 5],
                    ['value' => 'mid', 'label' => 'Изменения у собственников 25–50%', 'score' => 0],
                    ['value' => 'major', 'label' => 'Изменения у собственников >50%', 'score' => -5],
                ],
            ],
            'legal_risk_client' => [
                'id' => 'legal_risk_client',
                'label' => 'Правовые риски Клиента (арбитраж+ФССП >10% выручки)',
                'max' => 5,
                'weight' => 10.0,
                'options' => [
                    ['value' => 'yes', 'label' => 'Имеются', 'score' => -5],
                    ['value' => 'no', 'label' => 'Не имеются', 'score' => 5],
                ],
            ],
            'legal_risk_founders' => [
                'id' => 'legal_risk_founders',
                'label' => 'Правовые риски учредителей / ЕИО (ФССП >100 тыс. ₽)',
                'max' => 5,
                'weight' => 10.0,
                'manual_only' => true,
                'options' => [
                    ['value' => 'yes', 'label' => 'Имеются', 'score' => -5],
                    ['value' => 'no', 'label' => 'Не имеются', 'score' => 5],
                ],
            ],
            'accounting_accuracy' => [
                'id' => 'accounting_accuracy',
                'label' => 'Корректность бух. учёта (расхождение с внешней отчётностью)',
                'max' => 4,
                'weight' => 8.0,
                'options' => [
                    ['value' => 'mismatch', 'label' => 'Расхождения >10%', 'score' => -4],
                    ['value' => 'ok', 'label' => 'Расхождения отсутствуют', 'score' => 4],
                    ['value' => 'minor', 'label' => 'Расхождения <10% или нет данных во внешней системе', 'score' => 0],
                ],
            ],
        ],

        'rating_scale' => [
            ['lo' => 95, 'hi' => 100.0001, 'rating' => 'A', 'category' => 'Инвестиционный', 'position' => 'good'],
            ['lo' => 90, 'hi' => 95, 'rating' => 'BBB', 'category' => 'Инвестиционный', 'position' => 'good'],
            ['lo' => 80, 'hi' => 90, 'rating' => 'BB+', 'category' => 'Инвестиционный', 'position' => 'good'],
            ['lo' => 65, 'hi' => 80, 'rating' => 'BB', 'category' => 'Инвестиционный', 'position' => 'good'],
            ['lo' => 55, 'hi' => 65, 'rating' => 'B', 'category' => 'Инвестиционный', 'position' => 'good'],
            ['lo' => 40, 'hi' => 55, 'rating' => 'B-', 'category' => 'Спекулятивный', 'position' => 'average'],
            ['lo' => 30, 'hi' => 40, 'rating' => 'CCC+', 'category' => 'Спекулятивный', 'position' => 'average'],
            ['lo' => 25, 'hi' => 30, 'rating' => 'CCC', 'category' => 'Нестандартный', 'position' => 'average'],
            ['lo' => 20, 'hi' => 25, 'rating' => 'CCC-', 'category' => 'Нестандартный', 'position' => 'average'],
            ['lo' => 15, 'hi' => 20, 'rating' => 'CC+', 'category' => 'Нестандартный', 'position' => 'average'],
            ['lo' => 10, 'hi' => 15, 'rating' => 'CC', 'category' => 'Проблемный', 'position' => 'average'],
            ['lo' => 5, 'hi' => 10, 'rating' => 'C', 'category' => 'Проблемный', 'position' => 'average'],
            ['lo' => null, 'hi' => 5, 'rating' => 'D', 'category' => 'Убыточный', 'position' => 'bad'],
        ],

        'position_labels' => [
            'good' => 'Хорошее',
            'average' => 'Среднее',
            'bad' => 'Плохое',
        ],
    ];

    return $rules;
}
