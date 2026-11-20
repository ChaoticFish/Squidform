--
-- SquidForm 0.9.0: file uploads. The files table planned in 0.1.0 gets a
-- public id, its form, the virus-scan result, and who uploaded it (pending
-- files belong to a session until the form is saved).
--
ALTER TABLE `#__squidform_submission_files`
  ADD COLUMN `uid` char(32) NOT NULL DEFAULT '' AFTER `id`,
  ADD COLUMN `form_id` int unsigned NOT NULL DEFAULT 0 AFTER `uid`,
  MODIFY `submission_id` int unsigned NOT NULL DEFAULT 0,
  ADD COLUMN `scan` varchar(16) NOT NULL DEFAULT 'not_scanned' AFTER `path`,
  ADD COLUMN `owner` char(64) NOT NULL DEFAULT '' AFTER `scan`,
  ADD COLUMN `created_by` int unsigned NOT NULL DEFAULT 0 AFTER `created`;

UPDATE `#__squidform_submission_files` SET `uid` = MD5(CONCAT('sf', `id`, `sha256`)) WHERE `uid` = '';

ALTER TABLE `#__squidform_submission_files`
  ADD UNIQUE KEY `idx_uid` (`uid`),
  ADD KEY `idx_pending` (`submission_id`, `created`);
