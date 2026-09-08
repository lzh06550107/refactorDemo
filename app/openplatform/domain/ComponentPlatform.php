<?php

declare(strict_types=1);

namespace app\openplatform\domain;

use InvalidArgumentException;

final readonly class ComponentPlatform
{
    public function __construct(
        private string $id,
        private string $componentAppId,
        private string $appSecretRef,
        private string $verifyTokenRef,
        private string $encodingAesKeyRef,
        private bool $enabled,
    ) {
        foreach ([
            'id' => $id,
            'componentAppId' => $componentAppId,
            'appSecretRef' => $appSecretRef,
            'verifyTokenRef' => $verifyTokenRef,
            'encodingAesKeyRef' => $encodingAesKeyRef,
        ] as $name => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($name . ' must not be empty.');
            }
        }
    }

    public function id(): string { return $this->id; }
    public function componentAppId(): string { return $this->componentAppId; }
    public function appSecretRef(): string { return $this->appSecretRef; }
    public function verifyTokenRef(): string { return $this->verifyTokenRef; }
    public function encodingAesKeyRef(): string { return $this->encodingAesKeyRef; }
    public function enabled(): bool { return $this->enabled; }
}
