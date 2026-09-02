-- Переключение ЛК банка с Камкомбанка на Державу (уже созданные кейсы).
UPDATE application_product_bank_cases
SET bank_code = 'derzhava'
WHERE bank_code = 'kamcom';
