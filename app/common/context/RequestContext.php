<?php

declare(strict_types=1);

namespace app\common\context;

use InvalidArgumentException;

final readonly class RequestContext
{
    public function __construct(
        private string $requestId,
        private string $traceId,
        private RuntimeType $runtimeType,
        private ?string $tenantId,
        private ?string $accountId,
        private ?string $siteId,
        private ?Principal $principal,
        private string $locale,
        private string $clientIp,
    ) {
        if ($requestId === '' || $traceId === '') {
            throw new InvalidArgumentException('requestId/traceId must not be empty.');
        }
    }

    public function requestId(): string { return $this->requestId; }
    public function traceId(): string { return $this->traceId; }
    public function runtimeType(): RuntimeType { return $this->runtimeType; }
    public function tenantId(): ?string { return $this->tenantId; }
    public function accountId(): ?string { return $this->accountId; }
    public function siteId(): ?string { return $this->siteId; }
    public function principal(): ?Principal { return $this->principal; }
    public function locale(): string { return $this->locale; }
    public function clientIp(): string { return $this->clientIp; }
}
