<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$ticketPath = $root . '/modules/openplatform/infrastructure/ThinkPhpComponentTicketRepository.php';
expectTrue(is_file($ticketPath), 'R8B ticket repository must exist for replay ordering contract');
$source = (string) file_get_contents($ticketPath);
$acceptStart = strpos($source, 'public function accept(');
expectTrue($acceptStart !== false, 'ticket repository exposes authoritative accept transaction');
$accept = substr($source, (int) $acceptStart);
$inboxInsert = strpos($accept, "Db::table('component_ticket_inbox')->insert");
$currentTicketLock = strpos($accept, "Db::table('component_verify_tickets')");
expectTrue($inboxInsert !== false && $currentTicketLock !== false && $inboxInsert < $currentTicketLock, 'authoritative replay inbox insert occurs before latest-ticket mutation decision');
expectTrue(str_contains($accept, 'catch (Throwable $insertFailure)'), 'duplicate-key race is resolved explicitly after authoritative inbox insert');
expectTrue(str_contains($accept, "->where('component_platform_id', \$platformId)"), 'duplicate replay reread remains platform-scoped');
expectTrue(str_contains($accept, "->where('replay_key', \$replayKey)"), 'duplicate replay reread uses exact replay identity');
expectTrue(str_contains($accept, 'hash_equals') && str_contains($accept, 'payload_hash'), 'same replay key with different encrypted payload is conflict-checked');
expectTrue(str_contains($accept, "'result' => 'pending'") && str_contains($accept, "->update(['result' => \$result])"), 'new inbox row is finalized only after latest-ticket outcome is known');
