--
-- SquidForm 0.6.0 — database schema (MySQL / MariaDB, InnoDB, utf8mb4)
-- See docs/DATA-MODEL.md for what each table and column is for.
--

CREATE TABLE IF NOT EXISTS `#__squidform_forms` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `asset_id` int unsigned NOT NULL DEFAULT 0,
  `title` varchar(255) NOT NULL,
  `alias` varchar(190) NOT NULL,
  `description` text,
  `state` tinyint NOT NULL DEFAULT 1,
  `access` int unsigned NOT NULL DEFAULT 1,
  `current_version_id` int unsigned NOT NULL DEFAULT 0,
  `created` datetime NOT NULL,
  `created_by` int unsigned NOT NULL DEFAULT 0,
  `modified` datetime NOT NULL,
  `modified_by` int unsigned NOT NULL DEFAULT 0,
  `checked_out` int unsigned,
  `checked_out_time` datetime,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_alias` (`alias`),
  KEY `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__squidform_form_versions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `form_id` int unsigned NOT NULL,
  `version_no` int unsigned NOT NULL,
  `schema_json` longtext NOT NULL,
  `note` varchar(255) NOT NULL DEFAULT '',
  `created` datetime NOT NULL,
  `created_by` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_form_version` (`form_id`, `version_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__squidform_drafts` (
  `form_id` int unsigned NOT NULL,
  `schema_json` longtext NOT NULL,
  `base_version_no` int unsigned NOT NULL DEFAULT 0,
  `modified` datetime NOT NULL,
  `modified_by` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`form_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__squidform_submissions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `form_id` int unsigned NOT NULL,
  `version_id` int unsigned NOT NULL,
  `uid` char(36) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'submitted',
  `current_step` varchar(64) NOT NULL DEFAULT '',
  `approval_round` int unsigned NOT NULL DEFAULT 0,
  `step_since` datetime DEFAULT NULL,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `ip` varchar(45) NOT NULL DEFAULT '',
  `page` varchar(10) NOT NULL DEFAULT '',
  `created` datetime NOT NULL,
  `modified` datetime NOT NULL,
  `modified_by` int unsigned NOT NULL DEFAULT 0,
  `checked_out` int unsigned DEFAULT NULL,
  `checked_out_time` datetime DEFAULT NULL,
  `archived` tinyint NOT NULL DEFAULT 0,
  `is_test` tinyint NOT NULL DEFAULT 0,
  `language` varchar(16) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_uid` (`uid`),
  KEY `idx_form_status` (`form_id`, `archived`, `status`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

-- Answers live in each form's own tables (<prefix>_sfd_<alias>), listed here.
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

CREATE TABLE IF NOT EXISTS `#__squidform_submission_files` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uid` char(32) NOT NULL DEFAULT '',
  `form_id` int unsigned NOT NULL DEFAULT 0,
  `submission_id` int unsigned NOT NULL DEFAULT 0,
  `field_key` varchar(64) NOT NULL,
  `group_key` varchar(64) NOT NULL DEFAULT '',
  `row_index` int unsigned NOT NULL DEFAULT 0,
  `stored_name` varchar(128) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `mime` varchar(127) NOT NULL DEFAULT '',
  `size` bigint unsigned NOT NULL DEFAULT 0,
  `sha256` char(64) NOT NULL,
  `path` varchar(255) NOT NULL,
  `scan` varchar(16) NOT NULL DEFAULT 'not_scanned',
  `owner` char(64) NOT NULL DEFAULT '',
  `created` datetime NOT NULL,
  `created_by` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_uid` (`uid`),
  KEY `idx_submission` (`submission_id`),
  KEY `idx_pending` (`submission_id`, `created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__squidform_approvals` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `submission_id` int unsigned NOT NULL,
  `form_id` int unsigned NOT NULL DEFAULT 0,
  `round` int unsigned NOT NULL DEFAULT 0,
  `step_key` varchar(64) NOT NULL DEFAULT '',
  `decision` varchar(20) NOT NULL,
  `comment` text,
  `user_id` int unsigned NOT NULL,
  `on_behalf_of` int unsigned NOT NULL DEFAULT 0,
  `assignee_id` int unsigned NOT NULL DEFAULT 0,
  `entries` varchar(2048) NOT NULL DEFAULT '',
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_submission_round` (`submission_id`, `round`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

-- Who can act on each pending submission's current step (rebuilt on every change).
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

-- Rate limiting: one row per counted post or save; keys are HMACs (no IPs or ids in readable form).
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

-- Standing delegates: delegate_id may approve for user_id (one form or all).
CREATE TABLE IF NOT EXISTS `#__squidform_delegates` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `delegate_id` int unsigned NOT NULL DEFAULT 0,
  `delegate_group_id` int unsigned NOT NULL DEFAULT 0,
  `form_id` int unsigned NOT NULL DEFAULT 0,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `note` varchar(255) NOT NULL DEFAULT '',
  `created` datetime NOT NULL,
  `created_by` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_delegate` (`delegate_id`),
  KEY `idx_delegate_group` (`delegate_group_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__squidform_audit` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `submission_id` int unsigned DEFAULT NULL,
  `form_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `action` varchar(32) NOT NULL,
  `diff_json` mediumtext,
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_submission` (`submission_id`),
  KEY `idx_form_created` (`form_id`, `created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

-- Saved drafts (0.11.0): "Save and finish later". One per signed-in person
-- and form; a guest's draft is found by its one-time link (only its SHA-256
-- is kept). Removed when sent, discarded, used (guests) or expired.
CREATE TABLE IF NOT EXISTS `#__squidform_resume` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `form_id` int unsigned NOT NULL,
  `version_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `token_hash` char(64) NOT NULL DEFAULT '',
  `email` varchar(255) NOT NULL DEFAULT '',
  `page` int unsigned NOT NULL DEFAULT 0,
  `data_json` mediumtext NOT NULL,
  `files_json` text NOT NULL,
  `return_url` varchar(2048) NOT NULL DEFAULT '',
  `created` datetime NOT NULL,
  `modified` datetime NOT NULL,
  `expires` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_form_user` (`form_id`, `user_id`),
  KEY `idx_token` (`token_hash`),
  KEY `idx_expires` (`expires`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

-- E-signatures (0.11.0): one row per signing. record_json is exactly what
-- was signed; record_sha256 its fingerprint; seal an HMAC of that with the
-- site's secret. A later edit marks the row void (it is never changed otherwise).
CREATE TABLE IF NOT EXISTS `#__squidform_signatures` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `submission_id` int unsigned NOT NULL,
  `form_id` int unsigned NOT NULL,
  `run` char(36) NOT NULL,
  `field_key` varchar(64) NOT NULL,
  `user_id` int unsigned NOT NULL,
  `signed_at` datetime NOT NULL,
  `method` varchar(32) NOT NULL,
  `record_json` mediumtext NOT NULL,
  `record_sha256` char(64) NOT NULL,
  `seal` char(64) NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'valid',
  `voided_at` datetime DEFAULT NULL,
  `voided_by` int unsigned NOT NULL DEFAULT 0,
  `void_reason` varchar(255) NOT NULL DEFAULT '',
  `retain_until` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_submission` (`submission_id`),
  KEY `idx_run` (`run`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

-- The e-signature audit trail (0.11.0), append-only: opened, consented,
-- signed, copy sent, voided, signed again. Tied to the pass through the form
-- (run = the submission's uid).
CREATE TABLE IF NOT EXISTS `#__squidform_esign_events` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `run` char(36) NOT NULL,
  `form_id` int unsigned NOT NULL,
  `field_key` varchar(64) NOT NULL DEFAULT '',
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `event` varchar(20) NOT NULL,
  `detail` varchar(255) NOT NULL DEFAULT '',
  `ip` varchar(45) NOT NULL DEFAULT '',
  `user_agent` varchar(255) NOT NULL DEFAULT '',
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_run` (`run`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

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

-- 1.0.2: running counters for the starter handlers (Next number, Take turns).
-- One row per form and counter; a row lock makes two people sending at once
-- wait for each other instead of getting the same number.
CREATE TABLE IF NOT EXISTS `#__squidform_counters` (
  `form_id` int unsigned NOT NULL,
  `name` varchar(64) NOT NULL,
  `value` int unsigned NOT NULL DEFAULT 0,
  `modified` datetime NOT NULL,
  PRIMARY KEY (`form_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
