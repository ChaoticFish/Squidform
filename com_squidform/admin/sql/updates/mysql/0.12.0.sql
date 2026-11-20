--
-- SquidForm 0.12.0: forms imported from ChronoForms.
--
-- Forms brought in from ChronoForms (0.12.0): where each came from, which
-- ChronoForms field became which SquidForm field (for importing old
-- submissions), and the import report.
CREATE TABLE IF NOT EXISTS `#__squidform_imports` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `form_id` int unsigned NOT NULL,
  `source` varchar(16) NOT NULL,
  `source_ref` varchar(255) NOT NULL DEFAULT '',
  `title` varchar(255) NOT NULL DEFAULT '',
  `map_json` mediumtext NOT NULL,
  `report_json` mediumtext NOT NULL,
  `created` datetime NOT NULL,
  `created_by` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_form` (`form_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
