<?php

declare(strict_types=1);

namespace Tests\PhpUnit;

use PHPUnit\Framework\TestCase;

final class FoundationOfflineSuiteTest extends TestCase
{
    public function testOfflineFoundationContractsPass(): void
    {
        $root = dirname(__DIR__, 2);
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tests/run.php') . ' 2>&1';
        $output = [];
        $exitCode = 0;

        exec($command, $output, $exitCode);

        self::assertSame(0, $exitCode, implode(PHP_EOL, $output));
        self::assertStringContainsString('[PASS] StructureContractTest.php', implode(PHP_EOL, $output));
        self::assertStringContainsString('[PASS] LegacyEntrypointMappingTest.php', implode(PHP_EOL, $output));
    }
}
