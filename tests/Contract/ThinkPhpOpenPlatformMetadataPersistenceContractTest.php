<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$repositoryPath = $root . '/app/openplatform/infrastructure/ThinkPhpAuthorizerMetadataRepository.php';
$servicePath = $root . '/app/openplatform/application/AuthorizerMetadataSyncService.php';

expectTrue(is_file($repositoryPath), 'ThinkPHP authorizer metadata repository must exist');
expectTrue(is_file($servicePath), 'authorizer metadata sync service must exist');

$repository = is_file($repositoryPath) ? (string) file_get_contents($repositoryPath) : '';
$service = is_file($servicePath) ? (string) file_get_contents($servicePath) : '';

expectTrue(str_contains($repository, "Db::table('authorizer_metadata_current')"), 'metadata repository uses current projection table');
expectTrue(str_contains($repository, "Db::table('authorizer_metadata_snapshots')"), 'metadata repository uses immutable snapshot table');
expectTrue(str_contains($repository, 'Db::transaction'), 'metadata observation uses a short database transaction');
expectTrue(str_contains($repository, 'lock(true)'), 'metadata observation locks canonical current row before deciding version');
expectTrue(str_contains($repository, "'component_platform_id'"), 'metadata persistence keys by component platform');
expectTrue(str_contains($repository, "'authorizer_app_id'"), 'metadata persistence keys by authorizer AppId');
expectTrue(str_contains($repository, 'metadata_hash'), 'metadata repository compares semantic hash');
expectTrue(str_contains($repository, 'hash_equals'), 'metadata semantic hash comparison uses exact comparison');
expectTrue(str_contains($repository, 'provider_fetched_at'), 'identical observations can advance provider fetched time');
expectTrue(str_contains($repository, 'normalized_metadata_json'), 'current and snapshot persistence store normalized metadata only');
expectTrue(str_contains($repository, '->insert('), 'semantic metadata change appends an immutable snapshot');
expectTrue(str_contains($repository, '$currentVersion + 1') || str_contains($repository, '$version + 1'), 'changed semantic metadata increments version from current version');
expectTrue(!str_contains($repository, 'where(\'metadata_hash\'') && !str_contains($repository, 'where("metadata_hash"'), 'repository never deduplicates against historical snapshot hash');

expectTrue(str_contains($service, 'ComponentAccessTokenService'), 'metadata sync obtains component token through trusted lifecycle service');
expectTrue(str_contains($service, 'getAuthorizerInfo'), 'metadata sync fetches trusted provider metadata');
expectTrue(str_contains($service, 'AuthorizerMetadataNormalizer'), 'metadata sync canonicalizes provider metadata before persistence');
expectTrue(str_contains($service, 'AuthorizerMetadataRepository'), 'metadata sync persists only through metadata repository');
expectTrue(!str_contains($service, 'AuthorizerAuthorizationRepository'), 'metadata sync cannot mutate AuthorizerAuthorization repository');
expectTrue(!str_contains($service, 'saveFromAuthorization'), 'metadata sync cannot rewrite authorization credentials');
expectTrue(!str_contains($service, 'markUnauthorized'), 'metadata provider failure cannot transition authorization state');
expectTrue(!str_contains($service, 'accounts') && !str_contains($service, 'AccountRepository'), 'metadata sync has no API to overwrite Account.name');
