-- 0.4.0: per-form data tables. The registry tables are created here; the
-- answers are moved out of #__squidform_submissions.data_json and
-- #__squidform_submission_values by the package install script (PHP), which
-- drops those two only after every submission has been copied and checked.
CREATE TABLE IF NOT EXISTS `#__squidform_data_tables` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `form_id` int unsigned NOT NULL,
  `kind` varchar(16) NOT NULL,
  `field_key` varchar(64) NOT NULL DEFAULT '',
  `table_name` varchar(64) NOT NULL,
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_table_name` (`table_name`),
  KEY `idx_form` (`form_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__squidform_data_columns` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `table_id` int unsigned NOT NULL,
  `field_key` varchar(64) NOT NULL,
  `column_name` varchar(64) NOT NULL,
  `col_class` varchar(16) NOT NULL,
  `retired` tinyint NOT NULL DEFAULT 0,
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_table_column` (`table_id`, `column_name`),
  KEY `idx_field` (`table_id`, `field_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

-- "Delete" is now called "Archive" (the record was always kept).
ALTER TABLE `#__squidform_submissions` CHANGE `deleted` `archived` tinyint NOT NULL DEFAULT 0;
UPDATE `#__squidform_audit` SET `action` = 'archive' WHERE `action` = 'delete';
