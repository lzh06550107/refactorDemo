<?php

declare(strict_types=1);

namespace app\openplatform\application;

use modules\account\domain\AccountType;
use app\openplatform\domain\AuthorizerInfoResponse;
use app\openplatform\domain\AuthorizerMetadata;
use JsonException;

final class AuthorizerMetadataNormalizer
{
    public function normalize(
        string $componentPlatformId,
        string $authorizerAppId,
        AuthorizerInfoResponse $info,
    ): AuthorizerMetadata {
        $accountType = $info->miniProgramInfo() !== null
            ? AccountType::WECHAT_MINI_PROGRAM
            : AccountType::OFFICIAL_ACCOUNT;

        $normalized = $this->canonicalize([
            'account_type' => $accountType->value,
            'alias' => $info->alias(),
            'business_info' => $info->businessInfo(),
            'head_image_url' => $info->headImageUrl(),
            'mini_program_info' => $info->miniProgramInfo(),
            'nick_name' => $info->nickName(),
            'original_id' => $info->originalId(),
            'principal_name' => $info->principalName(),
            'qrcode_url' => $info->qrcodeUrl(),
            'service_type' => $info->serviceType(),
            'verify_type' => $info->verifyType(),
        ]);

        try {
            $json = json_encode(
                $normalized,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $e) {
            throw new \InvalidArgumentException('Authorizer metadata cannot be normalized.', 0, $e);
        }

        return new AuthorizerMetadata(
            $componentPlatformId,
            $authorizerAppId,
            $accountType,
            $info->nickName(),
            $info->headImageUrl(),
            $info->originalId(),
            $info->principalName(),
            $info->alias(),
            $info->serviceType(),
            $info->verifyType(),
            $this->canonicalize($info->businessInfo()),
            $info->qrcodeUrl(),
            $info->miniProgramInfo() === null ? null : $this->canonicalize($info->miniProgramInfo()),
            $json,
            hash('sha256', $json),
        );
    }

    /** @return array<mixed> */
    private function canonicalize(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => is_array($item) ? $this->canonicalize($item) : $item,
                $value,
            );
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }
        return $value;
    }
}
