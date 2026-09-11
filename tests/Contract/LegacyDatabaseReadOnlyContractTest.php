<?php

declare(strict_types=1);

use modules\integration\legacy\contract\LegacyDatabase;

$reflection = new ReflectionClass(LegacyDatabase::class);
$methods = array_map(static fn (ReflectionMethod $method): string => $method->getName(), $reflection->getMethods());
sort($methods);
expectSame(['fetchAll', 'fetchOne'], $methods, 'legacy DB port must be read-only');
