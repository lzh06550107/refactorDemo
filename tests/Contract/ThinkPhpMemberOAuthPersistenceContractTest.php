<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$transactionPath = $root . '/app/common/infrastructure/ThinkPhpTransactionManager.php';
$memberPath = $root . '/app/member/infrastructure/ThinkPhpMemberIdentityRepository.php';
$statePath = $root . '/app/oauth/infrastructure/ThinkPhpOAuthStateRepository.php';
$bindingPath = $root . '/app/oauth/infrastructure/ThinkPhpOAuthBindingRepository.php';

foreach ([$transactionPath, $memberPath, $statePath, $bindingPath] as $path) {
    expectTrue(is_file($path), 'missing ThinkPHP persistence adapter: ' . $path);
}

$transaction = (string) file_get_contents($transactionPath);
expectTrue(str_contains($transaction, 'Db::transaction'), 'transaction manager must delegate to ThinkPHP Db::transaction');

$member = (string) file_get_contents($memberPath);
expectTrue(str_contains($member, "Db::table('external_identities')"), 'member repository must query external identities');
expectTrue(str_contains($member, "'provider_type'"), 'identity lookup must include provider type');
expectTrue(str_contains($member, "'provider_account_id'"), 'identity lookup must include provider account scope');
expectTrue(str_contains($member, "'external_subject'"), 'identity lookup must include external subject');
expectTrue(str_contains($member, "Db::table('members')"), 'member repository must persist canonical members');

$state = (string) file_get_contents($statePath);
expectTrue(str_contains($state, "Db::table('oauth_states')"), 'OAuth state repository must use oauth_states');
expectTrue(str_contains($state, '->lock(true)'), 'OAuth state lock lookup must issue FOR UPDATE semantics');
expectTrue(str_contains($state, "'nonce_hash'"), 'OAuth state lookup must use the persisted nonce hash');

$binding = (string) file_get_contents($bindingPath);
expectTrue(str_contains($binding, "Db::table('oauth_bindings')"), 'OAuth binding repository must use oauth_bindings');
expectTrue(str_contains($binding, "'business_account_id'"), 'OAuth binding lookup must preserve business account context');
expectTrue(str_contains($binding, "'provider_type'"), 'OAuth binding lookup must preserve provider type');
expectTrue(str_contains($binding, "'enabled' => 1"), 'OAuth binding lookup must filter disabled bindings');
