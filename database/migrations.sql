ALTER TABLE `cert_orders`
  ADD COLUMN `txt_values_json` TEXT NULL AFTER `txt_value`,
  ADD COLUMN `last_error` TEXT NULL AFTER `acme_output`;
