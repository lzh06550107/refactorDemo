<?php

declare(strict_types=1);

namespace app\account\application;

use app\account\contract\LegacyAccountMappingRepository;
use app\account\domain\LegacyAccountMapping;
use app\common\error\AppException;
use app\common\error\ErrorCode;

final readonly class ResolveLegacyAccount
{
    public function __construct(private LegacyAccountMappingRepository $repository)
    {
    }

    public function byUniacid(int $uniacid, ?int $acid = null): LegacyAccountMapping
    {
        if ($uniacid <= 0) {
            throw new AppException(ErrorCode::INVALID_ARGUMENT, 'uniacid must be positive.', 422);
        }

        $mapping = $this->repository->findByUniacid($uniacid);
        if ($mapping === null) {
            throw new AppException(ErrorCode::NOT_FOUND, 'Legacy account mapping not found.', 404, ['uniacid' => $uniacid]);
        }

        if ($acid !== null && $mapping->acid() !== $acid) {
            throw new AppException(
                ErrorCode::CONFLICT,
                'Legacy acid does not belong to the resolved uniacid mapping.',
                409,
                ['uniacid' => $uniacid, 'acid' => $acid],
            );
        }

        return $mapping;
    }
}
