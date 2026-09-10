<?php

declare(strict_types=1);

namespace app\openplatform\infrastructure;

use app\openplatform\contract\ComponentCredentialProvider;
use RuntimeException;

final readonly class ConfiguredComponentCredentialProvider implements ComponentCredentialProvider
{
    /** @var array<string,string> */
    private array $secrets;

    /** @param array<string,string> $secrets */
    public function __construct(array $secrets)
    {
        if ($secrets === []) {
            throw new RuntimeException('OpenPlatform credential configuration is unavailable.');
        }

        $validated = [];
        foreach ($secrets as $reference => $secret) {
            if (!is_string($reference) || trim($reference) === '' || !is_string($secret) || $secret === '') {
                throw new RuntimeException('OpenPlatform credential configuration is unavailable.');
            }
            $reference = trim($reference);
            if (array_key_exists($reference, $validated)) {
                throw new RuntimeException('OpenPlatform credential configuration is unavailable.');
            }
            $validated[$reference] = $secret;
        }
        $this->secrets = $validated;
    }

    public function secretFor(string $credentialRef): string
    {
        $credentialRef = trim($credentialRef);
        $secret = $credentialRef === '' ? null : ($this->secrets[$credentialRef] ?? null);
        if (!is_string($secret) || $secret === '') {
            throw new RuntimeException('OpenPlatform credential configuration is unavailable.');
        }
        return $secret;
    }
}
