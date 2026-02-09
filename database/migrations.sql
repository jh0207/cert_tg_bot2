ALTER TABLE `tg_users`
  ADD COLUMN IF NOT EXISTS `pending_action` VARCHAR(64) NOT NULL DEFAULT '' AFTER `apply_quota`,
  ADD COLUMN IF NOT EXISTS `pending_order_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `pending_action`;
ALTER TABLE `tg_users`
  ADD COLUMN IF NOT EXISTS `is_banned` TINYINT(1) NOT NULL DEFAULT 0 AFTER `apply_quota`;

ALTER TABLE `cert_orders`
  ADD COLUMN IF NOT EXISTS `txt_values_json` TEXT NULL AFTER `txt_value`,
  ADD COLUMN IF NOT EXISTS `last_error` TEXT NULL AFTER `acme_output`,
  ADD COLUMN IF NOT EXISTS `need_dns_generate` TINYINT(1) NOT NULL DEFAULT 0 AFTER `last_error`,
  ADD COLUMN IF NOT EXISTS `need_issue` TINYINT(1) NOT NULL DEFAULT 0 AFTER `need_dns_generate`,
  ADD COLUMN IF NOT EXISTS `need_install` TINYINT(1) NOT NULL DEFAULT 0 AFTER `need_issue`,
  ADD COLUMN IF NOT EXISTS `retry_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `need_install`;

ALTER TABLE `cert_orders`
  MODIFY COLUMN `status` ENUM('created','dns_wait','dns_verified','issued','failed') NOT NULL DEFAULT 'created';
