-- Переключение ЛК банка с Державы на Ноосферу (уже созданные кейсы и подпись пользователей банка).
UPDATE application_product_bank_cases
SET bank_code = 'noosfera'
WHERE bank_code = 'derzhava';

UPDATE users
SET company_name = 'Ноосфера'
WHERE role = 'bank'
  AND (company_name IS NULL OR TRIM(company_name) = '' OR company_name IN ('Держава', 'Банк Держава'));
