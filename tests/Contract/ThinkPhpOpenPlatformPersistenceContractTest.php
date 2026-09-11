<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'platform' => $root . '/modules/openplatform/infrastructure/ThinkPhpComponentPlatformRepository.php',
    'ticket' => $root . '/modules/openplatform/infrastructure/ThinkPhpComponentTicketRepository.php',
    'token' => $root . '/modules/openplatform/infrastructure/ThinkPhpComponentTokenRepository.php',
    'lease' => $root . '/modules/openplatform/infrastructure/ThinkPhpComponentRefreshLeaseRepository.php',
];
foreach ($files as $name => $file) {
    expectTrue(is_file($file), 'R8B ThinkPHP ' . $name . ' repository must exist');
}

$platform = (string) file_get_contents($files['platform']);
expectTrue(str_contains($platform, "Db::table('component_platforms')"), 'platform repository uses component_platforms');
expectTrue(str_contains($platform, "where('id',"), 'platform lookup is id-scoped');

$ticket = (string) file_get_contents($files['ticket']);
expectTrue(str_contains($ticket, 'Db::transaction'), 'ticket accept uses short DB transaction');
expectTrue(str_contains($ticket, 'lock(true)'), 'ticket accept uses row locks for concurrency');
expectTrue(str_contains($ticket, 'component_ticket_inbox'), 'ticket repository persists replay inbox');
expectTrue(str_contains($ticket, 'component_verify_tickets'), 'ticket repository persists latest ticket');
expectTrue(str_contains($ticket, 'component_platform_id'), 'ticket state is platform-scoped');
expectTrue(str_contains($ticket, '->protect(') && str_contains($ticket, '->reveal('), 'ticket repository encrypts at rest and decrypts on read');
expectTrue(str_contains($ticket, 'ticket_ciphertext') && str_contains($ticket, 'ticket_key_version'), 'ticket repository writes only protected ticket columns');

$token = (string) file_get_contents($files['token']);
expectTrue(str_contains($token, 'Db::transaction'), 'token CAS uses short DB transaction');
expectTrue(str_contains($token, 'lock(true)'), 'token CAS locks lease/token rows');
expectTrue(str_contains($token, 'holder_id') && str_contains($token, 'lease_expires_at'), 'token CAS validates active lease holder');
expectTrue(str_contains($token, 'expectedVersion'), 'token CAS validates expected token version');
expectTrue(str_contains($token, '->protect(') && str_contains($token, '->reveal('), 'component token is encrypted at rest and decrypted on read');
expectTrue(str_contains($token, 'token_ciphertext') && str_contains($token, 'token_key_version'), 'token repository writes protected token columns');

$lease = (string) file_get_contents($files['lease']);
expectTrue(str_contains($lease, 'Db::transaction'), 'lease acquire/release uses short transactions');
expectTrue(str_contains($lease, 'lock(true)'), 'lease acquisition serializes per platform');
expectTrue(str_contains($lease, 'component_token_refresh_leases'), 'lease repository uses dedicated platform-scoped table');
expectTrue(str_contains($lease, 'lease_expires_at'), 'lease repository supports expired lease takeover');
