<?php

declare(strict_types=1);

use app\legacy\support\LegacySerializedValueDecoder;

$decoder = new LegacySerializedValueDecoder();
expectSame(['a' => 1], $decoder->array(serialize(['a' => 1])), 'serialized array');
expectSame(['a' => 1], $decoder->array(['a' => 1]), 'plain array');
expectSame([], $decoder->array(''), 'empty string');
expectSame([], $decoder->array(null), 'null');

$damaged = 'a:1:{s:3:"foo";s:5:"bar";}';
expectSame(['foo' => 'bar'], $decoder->array($damaged), 'damaged string length safely repaired');

$objectPayload = serialize(['x' => new stdClass()]);
expectThrows(static fn () => $decoder->array($objectPayload), InvalidArgumentException::class, 'serialized objects rejected');
expectThrows(static fn () => $decoder->array('not-serialized'), InvalidArgumentException::class, 'unexpected plain string rejected');
