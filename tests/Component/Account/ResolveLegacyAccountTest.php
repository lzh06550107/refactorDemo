<?php

declare(strict_types=1);

use modules\account\application\ResolveLegacyAccount;
use modules\account\contract\LegacyAccountMappingRepository;
use modules\account\domain\LegacyAccountMapping;
use app\common\error\AppException;
use app\common\error\ErrorCode;

$mapping = new LegacyAccountMapping('account-7', 'tenant-3', 12, 34, 4);
$repository = new class($mapping) implements LegacyAccountMappingRepository {
    public function __construct(private LegacyAccountMapping $mapping) {}
    public function findByUniacid(int $uniacid): ?LegacyAccountMapping { return $uniacid === $this->mapping->uniacid() ? $this->mapping : null; }
    public function findByAcid(int $acid): ?LegacyAccountMapping { return $acid === $this->mapping->acid() ? $this->mapping : null; }
};
$resolver = new ResolveLegacyAccount($repository);

expectSame($mapping, $resolver->byUniacid(12), 'resolve by uniacid');
expectSame($mapping, $resolver->byUniacid(12, 34), 'resolve by uniacid and matching acid');

try {
    $resolver->byUniacid(99);
    throw new RuntimeException('unknown uniacid must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::NOT_FOUND, $e->errorCode(), 'unknown uniacid error code');
    expectSame(404, $e->httpStatus(), 'unknown uniacid http status');
}

try {
    $resolver->byUniacid(12, 35);
    throw new RuntimeException('mismatched acid must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::CONFLICT, $e->errorCode(), 'mismatched acid error code');
    expectSame(409, $e->httpStatus(), 'mismatched acid http status');
}
