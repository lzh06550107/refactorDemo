<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$appService = (string) file_get_contents($root . '/app/AppService.php');
$audit = (string) file_get_contents($root . '/app/openplatform/application/OpenPlatformAudit.php');
$completionService = (string) file_get_contents($root . '/app/openplatform/application/AuthorizationCompletionService.php');
$transactionManager = (string) file_get_contents($root . '/app/common/infrastructure/ThinkPhpTransactionManager.php');
$provisioning = (string) file_get_contents($root . '/app/openplatform/infrastructure/AuditedAuthorizerProvisioningRepository.php');
$metadata = (string) file_get_contents($root . '/app/openplatform/infrastructure/AuditedAuthorizerMetadataRepository.php');
$connection = (string) file_get_contents($root . '/app/openplatform/infrastructure/AuditedAuthorizerConnectionStore.php');
$finalizer = (string) file_get_contents($root . '/app/openplatform/infrastructure/AuditedAuthorizerAccountFinalizer.php');
$startController = (string) file_get_contents($root . '/app/api/controller/V1/OpenPlatformAuthorizationStartController.php');
$metadataController = (string) file_get_contents($root . '/app/api/controller/V1/OpenPlatformAuthorizerMetadataController.php');
$provisioningController = (string) file_get_contents($root . '/app/api/controller/V1/OpenPlatformProvisioningController.php');

foreach ([
    'QuotaLedgerRepository::class => ThinkPhpQuotaLedgerRepository::class',
    'AuthorizerMetadataRepository::class => AuditedAuthorizerMetadataRepository::class',
    'AuthorizerProvisioningRepository::class => AuditedAuthorizerProvisioningRepository::class',
    'AuthorizerConnectionStore::class => AuditedAuthorizerConnectionStore::class',
    'AuthorizerAccountFinalizer::class => AuditedAuthorizerAccountFinalizer::class',
    'AuthorizerProvisioningQuotaService::class',
    'AuthorizerConnectionService::class',
    'AuthorizationCompletionService::class',
    'AuthorizerProvisioningWorker::class',
] as $needle) {
    expectTrue(str_contains($appService, $needle), 'production runtime wiring contains ' . $needle);
}
foreach ([
    'new ThinkPhpAuthorizerMetadataRepository()',
    'new ThinkPhpAuthorizerProvisioningRepository()',
    'new ThinkPhpAuthorizerConnectionStore()',
    'new ThinkPhpAuthorizerAccountFinalizer()',
] as $needle) {
    expectTrue(str_contains($appService, $needle), 'audited decorator explicitly wraps existing production adapter: ' . $needle);
}
expectTrue(str_contains($appService, '$this->app->make(AuthorizerAccountStateReader::class)'), 'connection service cannot silently omit Account state reader');
expectTrue(str_contains($appService, '$this->app->make(AuthorizerProvisioningRepository::class)'), 'completion/quota/worker resolve durable provisioning repository explicitly');
expectTrue(str_contains($appService, '$this->app->make(ProvisioningJobRepository::class)'), 'completion/worker resolve durable provisioning jobs explicitly');
expectTrue(str_contains($appService, '$this->app->make(AuthorizerProvisioningQuotaService::class)'), 'worker receives quota saga explicitly');
expectTrue(str_contains($appService, '$this->app->make(AuthorizerAccountFinalizer::class)'), 'worker receives Account finalizer explicitly');
expectTrue(str_contains($appService, '$this->app->make(AuthorizerMetadataRepository::class)'), 'worker receives metadata repository explicitly');
expectTrue(!str_contains($appService, 'Fake'), 'production wiring never references fake implementations');
expectTrue(!str_contains(strtolower($appService), 'authkey'), 'Task 13 does not regress to legacy authkey reuse');

foreach ([
    'OpenPlatformAudit $audit',
    'OpenPlatformAudit::AUTHORIZATION_START',
] as $needle) {
    expectTrue(str_contains($startController, $needle), 'authorization start controller has required audit dependency/action: ' . $needle);
}
foreach ([
    'OpenPlatformAudit $audit',
    'OpenPlatformAudit::METADATA_REFRESH',
] as $needle) {
    expectTrue(str_contains($metadataController, $needle), 'metadata controller has required audit dependency/action: ' . $needle);
}
foreach ([
    'OpenPlatformAudit $audit',
    'OpenPlatformAudit::PROVISIONING_RETRY_REQUESTED',
] as $needle) {
    expectTrue(str_contains($provisioningController, $needle), 'provisioning controller has required audit dependency/action: ' . $needle);
}

$provisioningSave = strpos($provisioning, '$saved = $this->inner->save(');
$provisioningAudit = strpos($provisioning, '$this->audit->system(');
expectTrue($provisioningSave !== false && $provisioningAudit !== false && $provisioningSave < $provisioningAudit, 'provisioning audit happens only after underlying CAS save attempt');
expectTrue(str_contains($provisioning, 'if (!$saved)'), 'failed provisioning CAS emits no success audit');
$insertStart = strpos($provisioning, 'public function insert(');
$findStart = strpos($provisioning, 'public function find(', $insertStart === false ? 0 : $insertStart);
expectTrue($insertStart !== false && $findStart !== false, 'provisioning repository exposes insert/find boundaries');
$insertBody = substr($provisioning, $insertStart, $findStart - $insertStart);
expectTrue(!str_contains($insertBody, '$this->audit'), 'provisioning INSERT does not emit pre-commit success telemetry');

$transactionStart = strpos($completionService, '$completion = $this->transactions->run(');
$postCommit = strpos($completionService, '[$authorization, $provisioningId] = $completion;');
$completionAuditCall = strpos($completionService, '$this->auditCompletion(', $postCommit === false ? 0 : $postCommit);
expectTrue(
    $transactionStart !== false
    && $postCommit !== false
    && $completionAuditCall !== false
    && $transactionStart < $postCommit
    && $postCommit < $completionAuditCall,
    'authorization/provisioning completion audit runs only after transaction returns',
);
expectTrue(str_contains($transactionManager, 'return Db::transaction('), 'ThinkPHP transaction manager return is the commit boundary');
$auditCompletionStart = strpos($completionService, 'private function auditCompletion(');
$auditCompletionEnd = strpos(
    $completionService,
    'private function assertAutoProvisioningConfigured',
    $auditCompletionStart === false ? 0 : $auditCompletionStart,
);
expectTrue($auditCompletionStart !== false && $auditCompletionEnd !== false, 'completion audit method boundary is explicit');
$auditCompletionBody = substr($completionService, $auditCompletionStart, $auditCompletionEnd - $auditCompletionStart);
expectSame(
    1,
    substr_count($auditCompletionBody, 'OpenPlatformAudit::PROVISIONING_CREATED'),
    'successful auto-provision commit emits exactly one provisioning creation audit action',
);
expectTrue(str_contains($auditCompletionBody, 'if ($provisioningId !== null)'), 'creation audit is limited to auto-provision completion');
foreach ([
    'authorizationCode',
    'refreshToken',
    'accessToken',
    'authorization_code',
    'refresh_token',
    'access_token',
] as $secretNeedle) {
    expectTrue(!str_contains($auditCompletionBody, $secretNeedle), 'creation audit contains no provider secret material: ' . $secretNeedle);
}
$completedReplay = strpos($completionService, 'if ($current->completed())');
expectTrue(
    $completedReplay !== false && $transactionStart !== false && $completedReplay < $transactionStart,
    'completed replay exits before the transaction and cannot duplicate creation audit',
);
expectTrue(
    !str_contains($provisioning, 'OpenPlatformAudit::PROVISIONING_CREATED'),
    'worker CAS repository cannot emit delayed duplicate provisioning creation audit',
);

$metadataObserve = strpos($metadata, '$result = $this->inner->observe(');
$metadataAudit = strpos($metadata, '$this->audit->provider(');
expectTrue($metadataObserve !== false && $metadataAudit !== false && $metadataObserve < $metadataAudit, 'metadata change audit follows durable observation');
$connectionDisable = strpos($connection, '$this->inner->disable(');
$connectionAudit = strpos($connection, '$this->audit->provider(');
expectTrue($connectionDisable !== false && $connectionAudit !== false && $connectionDisable < $connectionAudit, 'disconnect audit follows provider binding disable');
$finalizeCall = strpos($finalizer, '$accountId = $this->inner->provision(');
$finalizeAudit = strpos($finalizer, '$this->emit(');
expectTrue($finalizeCall !== false && $finalizeAudit !== false && $finalizeCall < $finalizeAudit, 'provisioned audit follows successful Account transaction');
expectTrue(str_contains($finalizer, 'if ($accountId !== null)'), 'reconcile audit only emits after durable Account facts are found');

foreach ([
    'admin:',
    'external:wechat-openplatform',
    'system:openplatform-provisioning-worker',
    'SAFE_METADATA_KEYS',
] as $needle) {
    expectTrue(str_contains($audit, $needle), 'central audit boundary freezes actor/metadata policy: ' . $needle);
}
