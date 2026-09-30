-- Тестовая заявка БГ для ЛК Камкомбанка + заполненная банковская методика (рейтинг A).
-- created_by = 1, assigned_to = 1, added_by = 1.
-- Идемпотентно: повторный запуск не создаёт дубликат (маркер purchase_number / comment).
-- Требуется пользователь users.id = 1.
-- Документы пакета для ЛК банка создаются автоматически при открытии заявки в ЛК
-- (includes/seed_kamcom_test_docs.php), отдельно ничего запускать не нужно.

SET @seed_marker := 'SEED-KAMCOM-TEST-APP-001';
SET @seed_user_id := 1;

-- Без пользователя #1 ничего не делаем
SET @seed_user_ok := (
  SELECT COUNT(*) FROM users WHERE id = @seed_user_id LIMIT 1
);

SET @seed_exists := (
  SELECT COUNT(*) FROM applications
  WHERE purchase_number = @seed_marker
     OR comment LIKE CONCAT('%', @seed_marker, '%')
  LIMIT 1
);

SET @do_seed := IF(@seed_user_ok > 0 AND @seed_exists = 0, 1, 0);

INSERT INTO applications (
  company_name, inn, product_type, fz_type, guarantee_type, loan_type,
  amount, amount_mode, requested_amount, term, term_bg,
  is_extension, is_replacement,
  purchase_number, purchase_link, declined_banks,
  contract_subject, contract_price, customer_inn, customer_name,
  principal_inn, principal_company_name, principal_email, intake_status,
  guarantee_provision_deadline,
  collateral_transport_enabled, collateral_transport_details,
  collateral_real_estate_enabled, collateral_real_estate_details,
  collateral_deposit_note_enabled, collateral_deposit_note_details,
  collateral_third_party_guarantee_enabled, collateral_third_party_guarantee_details,
  contact_name, contact_phone, comment,
  status, created_by, added_by, assigned_to
)
SELECT
  'ООО «ВолгаСтройИнвест»',
  '1658123456',
  'bg',
  '44-ФЗ',
  'Участие',
  NULL,
  2500000.00,
  'fixed',
  2500000.00,
  3,
  '2026-12-31',
  0,
  0,
  @seed_marker,
  'https://zakupki.gov.ru/epz/order/notice/ea20/view/common-info.html?regNumber=0321300076626000012',
  NULL,
  'Выполнение работ по капитальному ремонту здания МБОУ «Средняя общеобразовательная школа № 12» г. Казани (ремонт кровли, фасада, внутренних помещений)',
  48500000.00,
  '1655001122',
  'МУНИЦИПАЛЬНОЕ БЮДЖЕТНОЕ ОБЩЕОБРАЗОВАТЕЛЬНОЕ УЧРЕЖДЕНИЕ «СРЕДНЯЯ ОБЩЕОБРАЗОВАТЕЛЬНАЯ ШКОЛА № 12» АВИАСТРОИТЕЛЬНОГО РАЙОНА ГОРОДА КАЗАНИ',
  '1658123456',
  'ООО «ВолгаСтройИнвест»',
  'office@volgastroy-invest.ru',
  NULL,
  'до 15.10.2026',
  0, NULL,
  0, NULL,
  0, NULL,
  0, NULL,
  'Иванов Сергей Петрович',
  '+7 (843) 200-45-67',
  CONCAT('Тестовая заявка для ЛК Камкомбанка и банковской методики. Маркер: ', @seed_marker),
  'in_progress',
  @seed_user_id,
  @seed_user_id,
  @seed_user_id
WHERE @do_seed = 1;

SET @app_id := (
  SELECT id FROM applications WHERE purchase_number = @seed_marker ORDER BY id DESC LIMIT 1
);

-- Продукт Камкомбанка (product_id из каталога, если есть; иначе 0)
SET @catalog_product_id := (
  SELECT id FROM bank_products
  WHERE LOWER(bank_name) IN ('камкомбанк', 'камком')
     OR bank_name LIKE '%Камский коммерческий%'
     OR bank_name LIKE '%Камком%'
  ORDER BY id ASC
  LIMIT 1
);
SET @catalog_product_id := IFNULL(@catalog_product_id, 0);

INSERT INTO application_products (
  application_id, product_id, product_type, bank_name, product_name, status
)
SELECT
  @app_id,
  @catalog_product_id,
  'bg',
  'Камкомбанк',
  'Банковская гарантия экспресс',
  'В работе'
FROM DUAL
WHERE @do_seed = 1
  AND @app_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM application_products
    WHERE application_id = @app_id AND product_type = 'bg' AND bank_name = 'Камкомбанк'
  );

SET @ap_id := (
  SELECT id FROM application_products
  WHERE application_id = @app_id AND product_type = 'bg' AND bank_name = 'Камкомбанк'
  ORDER BY id DESC LIMIT 1
);

INSERT INTO application_product_bank_cases (
  application_product_id, bank_code, status, manager_comment,
  submitted_by, submitted_at
)
SELECT
  @ap_id,
  'kamcom',
  'sent_to_bank',
  'Тестовый пакет отправлен в Камкомбанк. Просьба рассмотреть на выдачу БГ обеспечения заявки.',
  @seed_user_id,
  NOW()
FROM DUAL
WHERE @do_seed = 1
  AND @ap_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM application_product_bank_cases WHERE application_product_id = @ap_id
  );

SET @case_id := (
  SELECT id FROM application_product_bank_cases WHERE application_product_id = @ap_id LIMIT 1
);

INSERT INTO application_product_bank_case_status_log (
  bank_case_id, old_status, new_status, comment, changed_by
)
SELECT
  @case_id,
  'draft',
  'sent_to_bank',
  'Тестовая отправка пакета в банк',
  @seed_user_id
FROM DUAL
WHERE @do_seed = 1
  AND @case_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM application_product_bank_case_status_log
    WHERE bank_case_id = @case_id AND new_status = 'sent_to_bank'
  );

-- Банковская методика: заполненный draft с рассчитанным рейтингом A (total 97)
INSERT INTO bank_case_methodology_assessments (
  application_id, version, status,
  state_json, result_json,
  total_score, rating, position_code, hard_stop,
  created_by, updated_by
)
SELECT
  @app_id,
  1,
  'draft',
  '{"stop_factors":[{"code":"1.1","triggered":false,"source":"manual","comment":""},{"code":"1.2","triggered":false,"source":"manual","comment":""},{"code":"1.3","triggered":false,"source":"manual","comment":""},{"code":"1.4","triggered":false,"source":"manual","comment":""},{"code":"1.5","triggered":false,"source":"manual","comment":""},{"code":"2.1","triggered":false,"source":"manual","comment":""},{"code":"2.2","triggered":false,"source":"manual","comment":""},{"code":"2.3","triggered":false,"source":"manual","comment":""},{"code":"2.4","triggered":false,"source":"manual","comment":""},{"code":"2.5","triggered":false,"source":"manual","comment":""},{"code":"3","triggered":false,"source":"manual","comment":""},{"code":"4","triggered":false,"source":"manual","comment":""},{"code":"5.1","triggered":false,"source":"manual","comment":""},{"code":"5.2","triggered":false,"source":"manual","comment":""},{"code":"5.3","triggered":false,"source":"manual","comment":""},{"code":"6","triggered":false,"source":"manual","comment":""},{"code":"7","triggered":false,"source":"manual","comment":""},{"code":"8","triggered":false,"source":"manual","comment":""},{"code":"9","triggered":false,"source":"manual","comment":""},{"code":"10","triggered":false,"source":"manual","comment":""},{"code":"11","triggered":false,"source":"manual","comment":""},{"code":"12","triggered":false,"source":"manual","comment":""},{"code":"13","triggered":false,"source":"manual","comment":""},{"code":"14","triggered":false,"source":"manual","comment":""},{"code":"15","triggered":false,"source":"manual","comment":""},{"code":"C1","triggered":false,"source":"manual","comment":""},{"code":"C2","triggered":false,"source":"manual","comment":""},{"code":"C3","triggered":false,"source":"manual","comment":""}],"finance":{"inputs":{"revenue":186500000,"revenue_last_year":152300000,"net_profit":14200000,"prior_year_net_profit":11800000,"income_from_participation":null,"interest_receivable":null,"other_income":null,"equity":68500000,"current_assets":92400000,"current_liabilities":41800000,"long_term_liabilities":22500000,"balance_total":158700000,"short_term_borrowings":12500000,"long_term_borrowings":18000000,"accounts_payable":22300000,"other_short_liabilities":7000000,"debt_to_revenue":null,"industry":"default","reporting_period":"annual","q1_seasonal_loss_explained":false,"q1_seasonal_comment":"","profitability_explained_zero":false,"roe_explained_zero":false},"score_overrides":[]},"business":{"credit_history":{"value":"white","source":"manual","score_override":null},"company_age":{"value":"gt_3y","source":"manual","score_override":null},"comparable_contracts":{"value":"exists","source":"manual","score_override":null},"gov_contracts":{"value":"gt_10","source":"manual","score_override":null},"ownership_stability":{"value":"stable","source":"manual","score_override":null},"legal_risk_client":{"value":"no","source":"manual","score_override":null},"legal_risk_founders":{"value":"no","source":"manual","score_override":null},"accounting_accuracy":{"value":"ok","source":"manual","score_override":null}},"judgment":{"comment":"Тестовая оценка для демонстрации ЛК Камкомбанка.","upgrade_downgrade_reason":"","conclusion":"Финансовое положение Принципала оценивается как хорошее. Стоп-факторы не выявлены. Рекомендуется выдача банковской гарантии на заявленных условиях.","established_rating":"","force_not_good":false}}',
  '{"result":{"finance_score":47,"business_score":50,"total_score":97,"rating":"A","calculated_rating":"A","calculated_position":"good","established_applied":false,"category":"Инвестиционный","position":"good","position_label":"Хорошее","incomplete":false,"pending_finance":[],"pending_business":[],"hard_stop":false,"mandatory_stops":[],"conditional_stops":[],"warnings":[],"negative_equity":false},"finance":{"total":47,"metrics":{"total_profitability":{"id":"total_profitability","label":"Общая рентабельность, %","group":"profitability","value":7.613941018766757,"auto_score":7,"score":7,"max":9,"weight":18,"source":"auto","note":"","unit":"%"},"roe":{"id":"roe","label":"Рентабельность собственного капитала, %","group":"profitability","value":20.72992700729927,"auto_score":9,"score":9,"max":9,"weight":18,"source":"auto","note":"","unit":"%"},"current_liquidity":{"id":"current_liquidity","label":"Текущая ликвидность","group":"liquidity","value":2.210526315789474,"auto_score":9,"score":9,"max":9,"weight":18,"source":"auto","note":"","unit":""},"independence":{"id":"independence","label":"Коэффициент независимости (СК / валюта баланса)","group":"stability","value":0.43163201008191554,"auto_score":6,"score":6,"max":7,"weight":14,"source":"auto","note":"","unit":""},"financial_stability":{"id":"financial_stability","label":"Коэффициент финансовой устойчивости","group":"stability","value":0.573408947700063,"auto_score":7,"score":7,"max":7,"weight":14,"source":"auto","note":"","unit":""},"debt_to_revenue":{"id":"debt_to_revenue","label":"Коэффициент отношения общей задолженности к выручке","group":"coverage","value":0.20026263952724885,"auto_score":9,"score":9,"max":9,"weight":18,"source":"auto","note":"Авторасчёт: разница ≤ 0; знаменатель — выручка за год","unit":""}},"ratios":{"total_profitability":{"value":7.613941018766757,"score":7,"note":""},"roe":{"value":20.72992700729927,"score":9,"note":""},"current_liquidity":{"value":2.210526315789474,"score":9,"note":""},"independence":{"value":0.43163201008191554,"score":6,"note":""},"financial_stability":{"value":0.573408947700063,"score":7,"note":""},"debt_to_revenue":{"value":0.20026263952724885,"score":9,"note":"Авторасчёт: разница ≤ 0; знаменатель — выручка за год"}}},"business":{"total":50,"metrics":{"credit_history":{"id":"credit_history","label":"Зона кредитной истории Клиента","value":"white","auto_score":10,"score":10,"max":10,"weight":20,"source":"manual","note":"","options":[{"value":"white","label":"Белая (положительная)","score":10},{"value":"absent","label":"Отсутствует","score":0},{"value":"grey","label":"Серая","score":-5},{"value":"black","label":"Чёрная (отрицательная)","score":-10}],"hint":"По данным 3 БКИ (НБКИ, ОКБ, Эквифакс). Отрицательная: просрочки >5 дней за 180 дней.","manual_only":true},"company_age":{"id":"company_age","label":"Срок осуществления деятельности","value":"gt_3y","auto_score":5,"score":5,"max":5,"weight":10,"source":"manual","note":"","options":[{"value":"lt_6m","label":"Менее 6 месяцев","score":-5},{"value":"6_12m","label":"От 6 до 12 месяцев","score":2},{"value":"1_3y","label":"От 1 до 3 лет","score":3},{"value":"gt_3y","label":"Свыше 3 лет","score":5}],"hint":"Ровно на верхней границе — следующая ступень (6 мес. → «от 6 до 12»).","manual_only":false},"comparable_contracts":{"id":"comparable_contracts","label":"Опыт контрактов ≥ сумме текущего контракта","value":"exists","auto_score":6,"score":6,"max":6,"weight":12,"source":"manual","note":"","options":[{"value":"none","label":"Не имеется / нет данных","score":0},{"value":"exists","label":"Имеется","score":6}],"hint":"","manual_only":false},"gov_contracts":{"id":"gov_contracts","label":"Опыт госконтрактов / муниципальных контрактов","value":"gt_10","auto_score":10,"score":10,"max":10,"weight":20,"source":"manual","note":"","options":[{"value":"none","label":"Не имеется / нет данных","score":0},{"value":"1_3","label":"От 1 до 3 контрактов","score":4},{"value":"3_10","label":"От 3 до 10 контрактов","score":8},{"value":"gt_10","label":"Свыше 10 контрактов","score":10}],"hint":"","manual_only":false},"ownership_stability":{"id":"ownership_stability","label":"Стабильность собственников (≥25% за год)","value":"stable","auto_score":5,"score":5,"max":5,"weight":10,"source":"manual","note":"","options":[{"value":"stable","label":"Без изменений / изменения у <25%","score":5},{"value":"mid","label":"Изменения у собственников 25–50%","score":0},{"value":"major","label":"Изменения у собственников >50%","score":-5}],"hint":"","manual_only":false},"legal_risk_client":{"id":"legal_risk_client","label":"Правовые риски Клиента (арбитраж+ФССП >10% выручки)","value":"no","auto_score":5,"score":5,"max":5,"weight":10,"source":"manual","note":"","options":[{"value":"yes","label":"Имеются","score":-5},{"value":"no","label":"Не имеются","score":5}],"hint":"","manual_only":false},"legal_risk_founders":{"id":"legal_risk_founders","label":"Правовые риски учредителей / ЕИО (ФССП >100 тыс. ₽)","value":"no","auto_score":5,"score":5,"max":5,"weight":10,"source":"manual","note":"","options":[{"value":"yes","label":"Имеются","score":-5},{"value":"no","label":"Не имеются","score":5}],"hint":"","manual_only":true},"accounting_accuracy":{"id":"accounting_accuracy","label":"Корректность бух. учёта (расхождение с внешней отчётностью)","value":"ok","auto_score":4,"score":4,"max":4,"weight":8,"source":"manual","note":"","options":[{"value":"mismatch","label":"Расхождения >10%","score":-4},{"value":"ok","label":"Расхождения отсутствуют","score":4},{"value":"minor","label":"Расхождения <10% или нет данных во внешней системе","score":0}],"hint":"","manual_only":false}}}}',
  97,
  'A',
  'good',
  0,
  @seed_user_id,
  @seed_user_id
FROM DUAL
WHERE @do_seed = 1
  AND @app_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM bank_case_methodology_assessments WHERE application_id = @app_id
  );
