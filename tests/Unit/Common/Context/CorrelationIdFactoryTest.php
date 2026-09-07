<?php

declare(strict_types=1);

use app\common\context\CorrelationIdFactory;

$factory = new CorrelationIdFactory();
expectSame('req-ABC_123:9', $factory->normalize(' req-ABC_123:9 '), 'valid inbound id should be preserved');
$generated = $factory->normalize('bad id with spaces');
expectTrue((bool) preg_match('/^[a-f0-9]{32}$/', $generated), 'invalid id should be replaced with 128-bit hex id');
$generated2 = $factory->normalize('');
expectTrue((bool) preg_match('/^[a-f0-9]{32}$/', $generated2), 'empty id should be generated');
