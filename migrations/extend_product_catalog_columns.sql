-- Расширение справочников продуктов (если в вашей БД ещё нет колонок из карточки продукта).
-- Выполняйте по одной строке; при ошибке «Duplicate column» пропустите эту строку.

ALTER TABLE bank_products ADD COLUMN limit_amount VARCHAR(255) NULL;
ALTER TABLE bank_products ADD COLUMN company_age VARCHAR(255) NULL;
ALTER TABLE bank_products ADD COLUMN spfs VARCHAR(255) NULL;
ALTER TABLE bank_products ADD COLUMN third_party_payment VARCHAR(255) NULL;
ALTER TABLE bank_products ADD COLUMN customers TEXT NULL;
ALTER TABLE bank_products ADD COLUMN works_with_individual_entrepreneurs VARCHAR(255) NULL;
ALTER TABLE bank_products ADD COLUMN works_with_state_enterprises VARCHAR(255) NULL;
ALTER TABLE bank_products ADD COLUMN stop_regions_principal TEXT NULL;
ALTER TABLE bank_products ADD COLUMN stop_regions_beneficiary TEXT NULL;
ALTER TABLE bank_products ADD COLUMN stop_factors TEXT NULL;
ALTER TABLE bank_products ADD COLUMN product_passport_link VARCHAR(512) NULL;
ALTER TABLE bank_products ADD COLUMN curator TEXT NULL;
ALTER TABLE bank_products ADD COLUMN platform_access TEXT NULL;

ALTER TABLE credit_products ADD COLUMN type VARCHAR(255) NULL;
ALTER TABLE credit_products ADD COLUMN credit_line_type VARCHAR(255) NULL;
ALTER TABLE credit_products ADD COLUMN tranch_term VARCHAR(255) NULL;
ALTER TABLE credit_products ADD COLUMN collateral VARCHAR(255) NULL;
ALTER TABLE credit_products ADD COLUMN individual_entrepreneur TINYINT(1) NULL DEFAULT 0;
ALTER TABLE credit_products ADD COLUMN documents TEXT NULL;
ALTER TABLE credit_products ADD COLUMN stops TEXT NULL;
ALTER TABLE credit_products ADD COLUMN consideration_term VARCHAR(255) NULL;
ALTER TABLE credit_products ADD COLUMN comments TEXT NULL;
ALTER TABLE credit_products ADD COLUMN passport_link VARCHAR(512) NULL;
ALTER TABLE credit_products ADD COLUMN curator TEXT NULL;
ALTER TABLE credit_products ADD COLUMN work_stages TEXT NULL;
ALTER TABLE credit_products ADD COLUMN platform_access TEXT NULL;
