<?php

declare(strict_types=1);

namespace modules\openplatform\infrastructure;

use modules\openplatform\contract\AuthorizerTokenRepository;
use modules\openplatform\contract\OpenPlatformSecretCipher;
use modules\openplatform\domain\AuthorizerAccessToken;
use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;

final readonly class ThinkPhpAuthorizerTokenRepository implements AuthorizerTokenRepository
{
    public function __construct(private OpenPlatformSecretCipher $cipher)
    {
    }

    public function current(string $componentPlatformId, string $authorizerAppId): ?AuthorizerAccessToken
    {
        $row = Db::table('authorizer_access_tokens')->where([
            'component_platform_id' => $componentPlatformId,
            'authorizer_app_id' => $authorizerAppId,
        ])->find();
        if (!is_array($row)) {
            return null;
        }
        $token = $this->cipher->reveal(
            (string) $row['token_ciphertext'],
            (string) $row['token_key_version'],
        );
        return new AuthorizerAccessToken(
            $componentPlatformId,
            $authorizerAppId,
            $token,
            $this->date((string) $row['issued_at']),
            $this->date((string) $row['expires_at']),
            (int) $row['version'],
        );
    }

    private function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
