<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'intent' => $root . '/modules/openplatform/infrastructure/ThinkPhpAuthorizationIntentRepository.php',
    'authorization' => $root . '/modules/openplatform/infrastructure/ThinkPhpAuthorizerAuthorizationRepository.php',
    'token' => $root . '/modules/openplatform/infrastructure/ThinkPhpAuthorizerTokenRepository.php',
    'lease' => $root . '/modules/openplatform/infrastructure/ThinkPhpAuthorizerRefreshLeaseRepository.php',
    'eventInbox' => $root . '/modules/openplatform/infrastructure/ThinkPhpComponentEventInboxRepository.php',
];
foreach ($files as $name => $file) {
    expectTrue(is_file($file), 'R8C ThinkPHP ' . $name . ' repository must exist');
}

$intent = (string) file_get_contents($files['intent']);
expectTrue(str_contains($intent, "Db::table('openplatform_authorization_intents')"), 'intent repository uses authorization intent table');
expectTrue(str_contains($intent, 'Db::transaction'), 'intent claim/complete uses short DB transactions');
expectTrue(str_contains($intent, 'lock(true)'), 'intent claim/complete serializes one intent row');
expectTrue(str_contains($intent, 'expectedVersion'), 'intent claim/complete validates expected version');
expectTrue(str_contains($intent, 'claim_holder_id') && str_contains($intent, 'claim_expires_at'), 'intent repository persists completion claim ownership');
expectTrue(str_contains($intent, 'hash_equals'), 'intent completion/release validates holder identity safely');
expectTrue(!str_contains($intent, 'pre_auth_code`') && !str_contains($intent, 'authorization_code'), 'intent persistence never introduces plaintext provider code columns');

$authorization = (string) file_get_contents($files['authorization']);
expectTrue(str_contains($authorization, "Db::table('authorizer_authorizations')"), 'authorizer repository uses canonical authorization table');
expectTrue(str_contains($authorization, "Db::table('authorizer_access_tokens')"), 'authorizer repository commits initial/refresh access token atomically');
expectTrue(str_contains($authorization, "Db::table('authorizer_token_refresh_leases')"), 'authorizer repository validates/clears refresh lease state');
expectTrue(str_contains($authorization, 'Db::transaction') && str_contains($authorization, 'lock(true)'), 'authorizer mutations use short row-locked transactions');
expectTrue(str_contains($authorization, 'expectedAuthorizationVersion') && str_contains($authorization, 'expectedTokenVersion'), 'authorizer refresh CAS checks both authorization and token versions');
expectTrue(str_contains($authorization, 'holder_id') && str_contains($authorization, 'lease_expires_at'), 'authorizer refresh CAS validates live lease holder');
expectTrue(str_contains($authorization, 'refresh_token_ciphertext') && str_contains($authorization, 'refresh_token_key_version'), 'authorizer refresh credential is stored only in protected columns');
expectTrue(str_contains($authorization, '->protect(') && str_contains($authorization, '->reveal('), 'authorizer repository protects/reveals credentials at repository boundary');
expectTrue(str_contains($authorization, '->delete()'), 'unauthorized path removes cached token/lease state atomically');

$token = (string) file_get_contents($files['token']);
expectTrue(str_contains($token, "Db::table('authorizer_access_tokens')"), 'authorizer token repository uses authorizer token table');
expectTrue(str_contains($token, 'component_platform_id') && str_contains($token, 'authorizer_app_id'), 'authorizer token reads are composite scoped');
expectTrue(str_contains($token, '->reveal('), 'authorizer token repository decrypts only at repository boundary');

$lease = (string) file_get_contents($files['lease']);
expectTrue(str_contains($lease, 'Db::transaction') && str_contains($lease, 'lock(true)'), 'authorizer refresh lease acquire/release is serialized');
expectTrue(str_contains($lease, 'component_platform_id') && str_contains($lease, 'authorizer_app_id'), 'authorizer refresh lease is composite scoped');
expectTrue(str_contains($lease, 'lease_expires_at'), 'authorizer refresh lease supports expiry takeover');

$eventInbox = (string) file_get_contents($files['eventInbox']);
$insertPos = strpos($eventInbox, '->insert(');
$readPos = strpos($eventInbox, '->find()');
expectTrue(str_contains($eventInbox, "Db::table('component_ticket_inbox')"), 'R8C lifecycle replay reuses authoritative component inbox table');
expectTrue($insertPos !== false, 'event inbox performs insert-first replay claim');
expectTrue($readPos === false || $insertPos < $readPos, 'event inbox must not read before authoritative unique insert');
expectTrue(str_contains($eventInbox, 'payload_hash') && str_contains($eventInbox, 'CONFLICT'), 'same replay identity/different payload fails closed');
