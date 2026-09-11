<?php

declare(strict_types=1);

(static function (): void {
    $root = dirname(__DIR__, 3);
    $classFile = $root . '/app/common/migration/V1BaselineSql.php';
    if (!is_file($classFile)) {
        throw new RuntimeException('V1BaselineSql class is required.');
    }
    require_once $classFile;

    $fixture = sys_get_temp_dir() . '/weplatform-v1-baseline-' . bin2hex(random_bytes(6));
    if (!mkdir($fixture, 0700, true) && !is_dir($fixture)) {
        throw new RuntimeException('Unable to create V1 baseline test fixture.');
    }

    try {
        file_put_contents(
            $fixture . '/001_up.sql',
            "CREATE TABLE a (id INT);\n\nINSERT INTO a VALUES (1);\n",
        );
        file_put_contents($fixture . '/empty.sql', "\n");

        $loader = new \app\common\migration\V1BaselineSql($fixture);
        $actual = $loader->statements('001_up.sql');
        if ($actual !== ['CREATE TABLE a (id INT)', 'INSERT INTO a VALUES (1)']) {
            throw new RuntimeException('V1BaselineSql must preserve statement order.');
        }

        foreach ([
            '../outside.sql' => 'Invalid V1 baseline filename.',
            'missing.sql' => 'V1 baseline SQL is missing or empty: missing.sql',
            'empty.sql' => 'V1 baseline SQL is missing or empty: empty.sql',
        ] as $file => $expectedMessage) {
            try {
                $loader->statements($file);
                throw new RuntimeException('Expected V1BaselineSql failure for ' . $file);
            } catch (RuntimeException $error) {
                if ($error->getMessage() !== $expectedMessage) {
                    throw new RuntimeException(
                        sprintf('Unexpected V1BaselineSql error for %s: %s', $file, $error->getMessage()),
                    );
                }
            }
        }
    } finally {
        @unlink($fixture . '/001_up.sql');
        @unlink($fixture . '/empty.sql');
        @rmdir($fixture);
    }
})();
