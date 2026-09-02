-- Дополнительные поля по заявке: срок предоставления гарантии и обеспечение.
ALTER TABLE `applications`
  ADD COLUMN `guarantee_provision_deadline` varchar(255) NULL AFTER `term_bg`,
  ADD COLUMN `collateral_transport_enabled` tinyint(1) NOT NULL DEFAULT 0 AFTER `guarantee_provision_deadline`,
  ADD COLUMN `collateral_transport_details` text NULL AFTER `collateral_transport_enabled`,
  ADD COLUMN `collateral_real_estate_enabled` tinyint(1) NOT NULL DEFAULT 0 AFTER `collateral_transport_details`,
  ADD COLUMN `collateral_real_estate_details` text NULL AFTER `collateral_real_estate_enabled`,
  ADD COLUMN `collateral_deposit_note_enabled` tinyint(1) NOT NULL DEFAULT 0 AFTER `collateral_real_estate_details`,
  ADD COLUMN `collateral_deposit_note_details` text NULL AFTER `collateral_deposit_note_enabled`,
  ADD COLUMN `collateral_third_party_guarantee_enabled` tinyint(1) NOT NULL DEFAULT 0 AFTER `collateral_deposit_note_details`,
  ADD COLUMN `collateral_third_party_guarantee_details` text NULL AFTER `collateral_third_party_guarantee_enabled`;

