-- 0.3.0: edit lock for submissions (also cleared by System -> Global Check-in).
ALTER TABLE `#__squidform_submissions` ADD COLUMN `checked_out` int unsigned DEFAULT NULL AFTER `modified_by`;
ALTER TABLE `#__squidform_submissions` ADD COLUMN `checked_out_time` datetime DEFAULT NULL AFTER `checked_out`;
