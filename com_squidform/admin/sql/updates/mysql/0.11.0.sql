--
-- SquidForm 0.11.0: save and finish later, e-signatures.
--

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
