-- Optional upgrade for databases installed before these indexes existed.
-- Fresh installs from mama-care-install.sql already include them.
-- Requires MariaDB 10.1.4+ (IF NOT EXISTS). Safe to run more than once.

ALTER TABLE `pregnancy_episodes` ADD INDEX IF NOT EXISTS `idx_episode_status_edd` (`status`,`edd`);
ALTER TABLE `anc_visits` ADD INDEX IF NOT EXISTS `idx_anc_episode_date` (`pregnancy_episode_id`,`visit_date`);
ALTER TABLE `postnatal_visits` ADD INDEX IF NOT EXISTS `idx_pnc_episode_date` (`pregnancy_episode_id`,`visit_date`);
