ALTER TABLE `tg_users`
  ADD COLUMN IF NOT EXISTS `pending_action` VARCHAR(64) NOT NULL DEFAULT '' AFTER `apply_quota`,
  ADD COLUMN IF NOT EXISTS `pending_order_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `pending_action`;

ALTER TABLE `cert_orders`
  ADD COLUMN IF NOT EXISTS `txt_values_json` TEXT NULL AFTER `txt_value`,
  ADD COLUMN IF NOT EXISTS `last_error` TEXT NULL AFTER `acme_output`;
