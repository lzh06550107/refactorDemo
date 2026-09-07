ALTER TABLE `sites` DROP FOREIGN KEY `fk_sites_active_theme_release`;
DROP TABLE IF EXISTS `site_theme_releases`;
DROP TABLE IF EXISTS `style_snapshots`;
DROP TABLE IF EXISTS `style_instances`;
DROP TABLE IF EXISTS `theme_versions`;
DROP TABLE IF EXISTS `themes`;
DROP TABLE IF EXISTS `domain_bindings`;
DROP TABLE IF EXISTS `sites`;
