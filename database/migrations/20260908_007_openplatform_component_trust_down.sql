ALTER TABLE `miniapp_provider_accounts`
  DROP FOREIGN KEY `fk_miniapp_provider_component_platform`;

DROP TABLE IF EXISTS `component_token_refresh_leases`;
DROP TABLE IF EXISTS `component_access_tokens`;
DROP TABLE IF EXISTS `component_verify_tickets`;
DROP TABLE IF EXISTS `component_ticket_inbox`;
DROP TABLE IF EXISTS `component_platforms`;
