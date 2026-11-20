-- 1.0.2: running counters for the starter handlers (Next number, Take turns).
CREATE TABLE IF NOT EXISTS `#__squidform_counters` (
  `form_id` int unsigned NOT NULL,
  `name` varchar(64) NOT NULL,
  `value` int unsigned NOT NULL DEFAULT 0,
  `modified` datetime NOT NULL,
  PRIMARY KEY (`form_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
