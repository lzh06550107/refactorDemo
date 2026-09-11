<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/tests/Acceptance/bootstrap.php';
require $root . '/tests/Acceptance/FreshDatabaseMigrationTest.php';

$config = AcceptanceConfig::load($root);
$runtime = new AcceptanceRuntime($config);
$runtime->preflight();
acceptanceFreshDatabaseMigrationTest($runtime);

fwrite(STDOUT, "[PASS] Admin browser database prepared\n");
