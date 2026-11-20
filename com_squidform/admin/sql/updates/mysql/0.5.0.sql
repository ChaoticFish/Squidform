--
-- SquidForm 0.5.0: approvals
--
ALTER TABLE `#__squidform_submissions` ADD COLUMN `approval_round` int unsigned NOT NULL DEFAULT 0 AFTER `current_step`;
ALTER TABLE `#__squidform_submissions` ADD COLUMN `step_since` datetime DEFAULT NULL AFTER `approval_round`;

ALTER TABLE `#__squidform_approvals` ADD COLUMN `form_id` int unsigned NOT NULL DEFAULT 0 AFTER `submission_id`;
ALTER TABLE `#__squidform_approvals` ADD COLUMN `round` int unsigned NOT NULL DEFAULT 0 AFTER `form_id`;
ALTER TABLE `#__squidform_approvals` ADD COLUMN `on_behalf_of` int unsigned NOT NULL DEFAULT 0 AFTER `user_id`;
ALTER TABLE `#__squidform_approvals` ADD COLUMN `assignee_id` int unsigned NOT NULL DEFAULT 0 AFTER `on_behalf_of`;
ALTER TABLE `#__squidform_approvals` ADD COLUMN `entries` varchar(2048) NOT NULL DEFAULT '' AFTER `assignee_id`;
-- (The approvals index on submission_id + round is now made by 0.10.4.sql,
-- under a new name, so Joomla's Database check reads every step correctly.)
ALTER TABLE `#__squidform_approvals` ADD INDEX `idx_user` (`user_id`);

CREATE TABLE IF NOT EXISTS `#__squidform_approval_queue` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `submission_id` int unsigned NOT NULL,
  `form_id` int unsigned NOT NULL,
  `step_key` varchar(64) NOT NULL,
  `kind` varchar(8) NOT NULL,
  `ref_id` int unsigned NOT NULL,
  `entry` varchar(255) NOT NULL DEFAULT '',
  `exclude_user` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_submission` (`submission_id`),
  KEY `idx_ref` (`kind`, `ref_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__squidform_delegates` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `delegate_id` int unsigned NOT NULL,
  `form_id` int unsigned NOT NULL DEFAULT 0,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `note` varchar(255) NOT NULL DEFAULT '',
  `created` datetime NOT NULL,
  `created_by` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_delegate` (`delegate_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
