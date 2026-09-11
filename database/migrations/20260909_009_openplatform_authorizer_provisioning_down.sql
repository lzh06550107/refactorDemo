DROP TABLE IF EXISTS `authorizer_provisioning_jobs`;
DROP TABLE IF EXISTS `authorizer_provisionings`;
DROP TABLE IF EXISTS `official_account_provider_accounts`;
DROP TABLE IF EXISTS `authorizer_account_ownerships`;
DROP TABLE IF EXISTS `authorizer_metadata_snapshots`;
DROP TABLE IF EXISTS `authorizer_metadata_current`;

DELETE FROM `permissions` WHERE `permission_key` IN (
  'openplatform.authorizer.read',
  'openplatform.authorizer.start',
  'openplatform.authorizer.bind',
  'openplatform.authorizer.provision',
  'openplatform.authorizer.refresh_metadata',
  'openplatform.authorizer.retry_provision'
);

DELETE FROM `openplatform_authorization_intents`
 WHERE `intent_mode` = 'auto_provision_account'
    OR `target_account_id` IS NULL;

ALTER TABLE `openplatform_authorization_intents`
  DROP CHECK `chk_openplatform_intent_mode_target`,
  DROP COLUMN `intent_mode`,
  MODIFY `target_account_id` varchar(64) NOT NULL;
