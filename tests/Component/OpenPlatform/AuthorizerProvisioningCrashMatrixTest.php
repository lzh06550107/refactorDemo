<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$worker = (string) file_get_contents($root . '/app/openplatform/application/AuthorizerProvisioningWorker.php');
$quota = (string) file_get_contents($root . '/app/openplatform/application/AuthorizerProvisioningQuotaService.php');
$finalizer = (string) file_get_contents($root . '/app/openplatform/infrastructure/ThinkPhpAuthorizerAccountFinalizer.php');

$metadataSync = strpos($worker, '$this->metadataSync->sync(');
$withMetadata = $metadataSync === false ? false : strpos($worker, '->withMetadata(', $metadataSync);
$metadataSave = $withMetadata === false ? false : strpos($worker, '$this->save(', $withMetadata);
$ownershipRead = $metadataSave === false ? false : strpos($worker, '$this->ownerships->current(', $metadataSave);
expectTrue(
    $metadataSync !== false && $withMetadata !== false && $metadataSave !== false && $ownershipRead !== false
    && $metadataSync < $withMetadata && $withMetadata < $metadataSave && $metadataSave < $ownershipRead,
    'metadata provider result is normalized/persisted into provisioning before ownership/quota decisions',
);

$reconnect = strpos($worker, '$this->connections->reconnect(');
$reconnectedState = $reconnect === false ? false : strpos($worker, '->reconnected(', $reconnect);
$reconnectedSave = $reconnectedState === false ? false : strpos($worker, '$this->save(', $reconnectedState);
expectTrue(
    $reconnect !== false && $reconnectedState !== false && $reconnectedSave !== false
    && $reconnect < $reconnectedState && $reconnectedState < $reconnectedSave,
    'reconnect binding is enabled before RECONNECTED final status is persisted',
);

$consumeCall = strpos($quota, '$this->quota->consume(');
$consumeState = $consumeCall === false ? false : strpos($quota, '->withQuotaConsumed(', $consumeCall);
$consumeSave = $consumeState === false ? false : strpos($quota, '$this->provisionings->save(', $consumeState);
$consumeRecovery = $consumeSave === false ? false : strpos($quota, '$this->provisionings->find(', $consumeSave);
expectTrue(
    $consumeCall !== false && $consumeState !== false && $consumeSave !== false && $consumeRecovery !== false
    && $consumeCall < $consumeState && $consumeState < $consumeSave && $consumeSave < $consumeRecovery,
    'quota success before consume-ref persistence is recovered only after CAS outcome is known',
);
expectTrue(
    str_contains($quota, "'openplatform-provision:%s:%s:%s'"),
    'quota consume uses deterministic semantic idempotency key',
);
expectTrue(
    str_contains($quota, "'openplatform-provision-release:' . \$current->id()"),
    'quota compensation uses deterministic release idempotency key',
);

$firstReconcile = strpos($worker, '$this->finalizer->reconcile(');
$provisionCall = $firstReconcile === false ? false : strpos($worker, '$this->finalizer->provision(', $firstReconcile);
expectTrue(
    $firstReconcile !== false && $provisionCall !== false && $firstReconcile < $provisionCall,
    'worker reconciles committed Account/ownership before attempting a new Account transaction',
);
$releaseCall = strpos($worker, '$this->quota->ensureReleased(');
$lastReconcile = strrpos(substr($worker, 0, $releaseCall === false ? strlen($worker) : $releaseCall), '$this->finalizer->reconcile(');
expectTrue(
    $releaseCall !== false && $lastReconcile !== false && $lastReconcile < $releaseCall,
    'terminal recovery reconciles durable Account facts before quota release',
);

expectTrue(str_contains($finalizer, 'Db::transaction('), 'Account finalization is enclosed in one DB transaction');
$accountInsert = strpos($finalizer, "Db::table('accounts')->insert(");
$ownershipInsert = strpos($finalizer, "Db::table('authorizer_account_ownerships')->insert(");
$markProvisioned = $ownershipInsert === false ? false : strpos($finalizer, '$this->markProvisioned(', $ownershipInsert);
expectTrue(
    $accountInsert !== false && $ownershipInsert !== false && $markProvisioned !== false
    && $accountInsert < $ownershipInsert && $ownershipInsert < $markProvisioned,
    'Account and canonical ownership are durable before provisioning is finalized',
);
expectTrue(
    str_contains($finalizer, "->where('id', \$provisioning->id())") && str_contains($finalizer, '->lock(true)'),
    'finalizer locks provisioning/canonical facts to prevent duplicate Account creation',
);

expectTrue(
    str_contains($worker, 'if (!$this->task10Ready())')
    && str_contains($worker, '$this->quota !== null && $this->finalizer !== null'),
    'worker retains explicit Task10 readiness invariant while production DI supplies both dependencies',
);

$provisioningRead = strpos($worker, '$provisioning = $this->provisionings->find($provisioningId);');
$terminalRecovery = $provisioningRead === false
    ? false
    : strpos($worker, 'if ($this->isTerminal($provisioning->status())) {', $provisioningRead);
$authorizationRead = $provisioningRead === false
    ? false
    : strpos($worker, '$authorization = $this->authorizations->current(', $provisioningRead);
expectTrue(
    $provisioningRead !== false && $terminalRecovery !== false && $authorizationRead !== false
    && $provisioningRead < $terminalRecovery && $terminalRecovery < $authorizationRead,
    'already-terminal provisioning completes a recovered job before provider authorization can regress durable outcome',
);
