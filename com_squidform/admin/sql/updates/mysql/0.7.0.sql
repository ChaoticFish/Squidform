--
-- SquidForm 0.7.0: visual builder. One unpublished draft per form.
--

CREATE TABLE IF NOT EXISTS `#__squidform_drafts` (
  `form_id` int unsigned NOT NULL,
  `schema_json` longtext NOT NULL,
  `base_version_no` int unsigned NOT NULL DEFAULT 0,
  `modified` datetime NOT NULL,
  `modified_by` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`form_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
