--
-- SquidForm 0.6.0: group delegates, rate limiting
--
ALTER TABLE `#__squidform_delegates` MODIFY `delegate_id` int unsigned NOT NULL DEFAULT 0;
ALTER TABLE `#__squidform_delegates` ADD COLUMN `delegate_group_id` int unsigned NOT NULL DEFAULT 0 AFTER `delegate_id`;
ALTER TABLE `#__squidform_delegates` ADD INDEX `idx_delegate_group` (`delegate_group_id`);

CREATE TABLE IF NOT EXISTS `#__squidform_rate` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `form_id` int unsigned NOT NULL,
  `kind` varchar(8) NOT NULL,
  `key_hash` char(64) NOT NULL,
  `what` varchar(8) NOT NULL,
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lookup` (`form_id`, `what`, `key_hash`, `created`),
  KEY `idx_created` (`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
