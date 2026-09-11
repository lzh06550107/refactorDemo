<?php

declare(strict_types=1);

namespace modules\openplatform\infrastructure;

use modules\account\domain\AccountType;
use modules\openplatform\contract\AuthorizerMetadataRepository;
use modules\openplatform\domain\AuthorizerMetadata;
use modules\openplatform\domain\AuthorizerMetadataRecord;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use think\facade\Db;

final class ThinkPhpAuthorizerMetadataRepository implements AuthorizerMetadataRepository
{
    public function current(string $componentPlatformId, string $authorizerAppId): ?AuthorizerMetadataRecord
    {
        $row = Db::table('authorizer_metadata_current')->where([
            'component_platform_id' => $componentPlatformId,
            'authorizer_app_id' => $authorizerAppId,
        ])->find();

        return is_array($row) ? $this->map($row) : null;
    }

    public function observe(
        AuthorizerMetadata $metadata,
        DateTimeImmutable $fetchedAt,
        string $source,
    ): AuthorizerMetadataRecord {
        $source = trim($source);
        if ($source === '') {
            throw new InvalidArgumentException('Authorizer metadata observation source must not be empty.');
        }

        return Db::transaction(function () use ($metadata, $fetchedAt, $source): AuthorizerMetadataRecord {
            $key = [
                'component_platform_id' => $metadata->componentPlatformId(),
                'authorizer_app_id' => $metadata->authorizerAppId(),
            ];

            $current = Db::table('authorizer_metadata_current')->where($key)->lock(true)->find();
            if (is_array($current) && hash_equals((string) $current['metadata_hash'], $metadata->metadataHash())) {
                Db::table('authorizer_metadata_current')->where($key)->update([
                    'provider_fetched_at' => $this->sqlDate($fetchedAt),
                ]);
                $current['provider_fetched_at'] = $this->sqlDate($fetchedAt);
                return $this->map($current);
            }

            $currentVersion = is_array($current) ? (int) $current['version'] : 0;
            $version = $currentVersion + 1;
            $row = $this->rowFromMetadata($metadata, $fetchedAt, $version);

            Db::table('authorizer_metadata_snapshots')->insert([
                'id' => bin2hex(random_bytes(16)),
                'component_platform_id' => $metadata->componentPlatformId(),
                'authorizer_app_id' => $metadata->authorizerAppId(),
                'account_type' => $metadata->accountType()->value,
                'metadata_hash' => $metadata->metadataHash(),
                'normalized_metadata_json' => $metadata->normalizedMetadataJson(),
                'observed_at' => $this->sqlDate($fetchedAt),
                'source' => $source,
                'version' => $version,
            ]);

            if (is_array($current)) {
                Db::table('authorizer_metadata_current')->where($key)->update($row);
            } else {
                Db::table('authorizer_metadata_current')->insert($key + $row);
            }

            return AuthorizerMetadataRecord::fromMetadata($metadata, $fetchedAt, $version);
        });
    }

    /** @return array<string,mixed> */
    private function rowFromMetadata(
        AuthorizerMetadata $metadata,
        DateTimeImmutable $fetchedAt,
        int $version,
    ): array {
        return [
            'account_type' => $metadata->accountType()->value,
            'nickname' => $metadata->nickName(),
            'original_id' => $metadata->originalId(),
            'principal_name' => $metadata->principalName(),
            'alias' => $metadata->alias(),
            'head_image_url' => $metadata->headImageUrl(),
            'qrcode_url' => $metadata->qrcodeUrl(),
            'service_type' => $metadata->serviceType(),
            'verify_type' => $metadata->verifyType(),
            'business_info_json' => json_encode(
                $metadata->businessInfo(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
            'mini_program_info_json' => $metadata->miniProgramInfo() === null
                ? null
                : json_encode(
                    $metadata->miniProgramInfo(),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ),
            'normalized_metadata_json' => $metadata->normalizedMetadataJson(),
            'metadata_hash' => $metadata->metadataHash(),
            'provider_fetched_at' => $this->sqlDate($fetchedAt),
            'version' => $version,
        ];
    }

    /** @param array<string,mixed> $row */
    private function map(array $row): AuthorizerMetadataRecord
    {
        return AuthorizerMetadataRecord::reconstitute(
            (string) $row['component_platform_id'],
            (string) $row['authorizer_app_id'],
            AccountType::from((string) $row['account_type']),
            (string) ($row['nickname'] ?? ''),
            $this->nullableString($row['head_image_url'] ?? null),
            (string) ($row['original_id'] ?? ''),
            (string) ($row['principal_name'] ?? ''),
            (string) ($row['alias'] ?? ''),
            $this->nullableInt($row['service_type'] ?? null),
            $this->nullableInt($row['verify_type'] ?? null),
            $this->decodeArray($row['business_info_json'] ?? null),
            $this->nullableString($row['qrcode_url'] ?? null),
            $this->decodeNullableArray($row['mini_program_info_json'] ?? null),
            (string) $row['normalized_metadata_json'],
            (string) $row['metadata_hash'],
            $this->date((string) $row['provider_fetched_at']),
            (int) $row['version'],
        );
    }

    /** @return array<string,mixed> */
    private function decodeArray(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        try {
            $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Persisted authorizer metadata JSON is invalid.', 0, $e);
        }
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Persisted authorizer metadata JSON must decode to an object.');
        }
        return $decoded;
    }

    /** @return array<string,mixed>|null */
    private function decodeNullableArray(mixed $value): ?array
    {
        return $value === null || $value === '' ? null : $this->decodeArray($value);
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function sqlDate(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
