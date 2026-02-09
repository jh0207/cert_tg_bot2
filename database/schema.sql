CREATE TABLE `tg_users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tg_id` BIGINT NOT NULL UNIQUE,
  `username` VARCHAR(64) NOT NULL DEFAULT '',
  `first_name` VARCHAR(64) NOT NULL DEFAULT '',
  `last_name` VARCHAR(64) NOT NULL DEFAULT '',
  `role` ENUM('owner','admin','user') NOT NULL DEFAULT 'user',
  `apply_quota` INT UNSIGNED NOT NULL DEFAULT 1,
  `is_banned` TINYINT(1) NOT NULL DEFAULT 0,
  `pending_action` VARCHAR(64) NOT NULL DEFAULT '',
  `pending_order_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `cert_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tg_user_id` BIGINT UNSIGNED NOT NULL,
  `domain` VARCHAR(255) NOT NULL,
  `cert_type` ENUM('root','wildcard') NOT NULL DEFAULT 'root',
  `status` ENUM('created','dns_wait','dns_verified','issued','failed') NOT NULL DEFAULT 'created',
  `txt_host` VARCHAR(255) NOT NULL DEFAULT '',
  `txt_value` VARCHAR(512) NOT NULL DEFAULT '',
  `txt_values_json` TEXT NULL,
  `acme_output` MEDIUMTEXT NULL,
  `last_error` TEXT NULL,
  `need_dns_generate` TINYINT(1) NOT NULL DEFAULT 0,
  `need_issue` TINYINT(1) NOT NULL DEFAULT 0,
  `need_install` TINYINT(1) NOT NULL DEFAULT 0,
  `retry_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `cert_path` VARCHAR(255) NOT NULL DEFAULT '',
  `key_path` VARCHAR(255) NOT NULL DEFAULT '',
  `fullchain_path` VARCHAR(255) NOT NULL DEFAULT '',
  `payment_status` VARCHAR(32) NOT NULL DEFAULT 'unpaid',
  `payment_meta` TEXT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_domain_user` (`domain`, `tg_user_id`),
  CONSTRAINT `fk_cert_user` FOREIGN KEY (`tg_user_id`) REFERENCES `tg_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `action_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tg_user_id` BIGINT UNSIGNED NOT NULL,
  `action` VARCHAR(64) NOT NULL,
  `detail` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_user_action` (`tg_user_id`, `action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
