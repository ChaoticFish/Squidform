--
-- SquidForm 0.10.4: rename the approvals index so Joomla's Database check
-- stops reporting "should not have index 'idx_submission'" (its checker read
-- 0.5.0's drop-then-re-add of the same name as a drop only).
--
ALTER TABLE `#__squidform_approvals` DROP INDEX `idx_submission`;
ALTER TABLE `#__squidform_approvals` ADD INDEX `idx_submission_round` (`submission_id`, `round`);
