<?php

declare(strict_types=1);

namespace app\openplatform\infrastructure;

use app\openplatform\application\OpenPlatformAudit;
use app\openplatform\contract\AuthorizerMetadataRepository;
use app\openplatform\domain\AuthorizerMetadata;
use app\openplatform\domain\AuthorizerMetadataRecord;
use DateTimeImmutable;

final readonly class AuditedAuthorizerMetadataRepository implements AuthorizerMetadataRepository
{
    public function __construct(
        private AuthorizerMetadataRepository $inner,
        private OpenPlatformAudit $audit,
    ) {
    }

    public function current(string $componentPlatformId, string $authorizerAppId): ?AuthorizerMetadataRecord
    {
        return $this->inner->current($componentPlatformId, $authorizerAppId);
    }

    public function observe(
        AuthorizerMetadata $metadata,
        DateTimeImmutable $fetchedAt,
        string $source,
    ): AuthorizerMetadataRecord {
        $before = $this->inner->current($metadata->componentPlatformId(), $metadata->authorizerAppId());
        $result = $this->inner->observe($metadata, $fetchedAt, $source);

        if ($before === null || !hash_equals($before->metadataHash(), $result->metadataHash())) {
            $operationId = implode(':', [
                $result->componentPlatformId(),
                $result->authorizerAppId(),
                (string) $result->version(),
            ]);
            $this->audit->provider(
                null,
                null,
                OpenPlatformAudit::METADATA_CHANGE,
                'metadata:' . $operationId,
                'metadata:' . $operationId,
                [
                    'component_platform_id' => $result->componentPlatformId(),
                    'authorizer_app_id' => $result->authorizerAppId(),
                    'metadata_version' => $result->version(),
                    'account_type' => $result->accountType()->value,
                ],
                $fetchedAt,
            );
        }

        return $result;
    }
}
