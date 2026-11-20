-- Uninstalling SquidForm keeps all of its data (0.4.0): the #__squidform_*
-- tables and every form's <prefix>_sfd_* / _sfa_* tables stay in the
-- database, and reinstalling picks them up again. Nothing is deleted
-- unless someone deliberately drops the tables (for example in phpMyAdmin).
SELECT 1;
