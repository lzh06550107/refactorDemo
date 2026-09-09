<?php

declare(strict_types=1);

namespace app\openplatform\application;

use app\openplatform\contract\AuthorizerClient;
use app\openplatform\contract\AuthorizerMetadataRepository;
use app\openplatform\domain\AuthorizerMetadataRecord;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AuthorizerMetadataSyncService
{
    public function __construct(
        private ComponentAccessTokenService $componentTokens,
        private AuthorizerClient $authorizerClient,
        private AuthorizerMetadataNormalizer $normalizer,
        private AuthorizerMetadataRepository $metadata,
    ) {
    }

    public function sync(
        string $componentPlatformId,
        string $authorizerAppId,
        DateTimeImmutable $now,
        string $source,
    ): AuthorizerMetadataRecord {
        if (trim($componentPlatformId) === '' || trim($authorizerAppId) === '' || trim($source) === '') {
            throw new InvalidArgumentException('Authorizer metadata sync identifiers and source must not be empty.');
        }

        $componentToken = $this->componentTokens->forPlatform($componentPlatformId, $now);
        $info = $this->authorizerClient->getAuthorizerInfo(
            $componentToken->componentAppId(),
            $componentToken->accessToken(),
            $authorizerAppId,
        );
        $normalized = $this->normalizer->normalize(
            $componentPlatformId,
            $authorizerAppId,
            $info,
        );

        return $this->metadata->observe($normalized, $now, trim($source));
    }
}
