<?php

declare(strict_types=1);

namespace app\theme\domain;

use InvalidArgumentException;
use JsonException;

final readonly class StyleSnapshot
{
    /** @param array<string,string> $values */
    private function __construct(
        private string $id,
        private string $tenantId,
        private string $styleInstanceId,
        private string $themeVersionId,
        private int $revision,
        private array $values,
        private string $contentHash,
    ) {}

    public static function capture(string $id, StyleInstance $style): self
    {
        if (trim($id) === '') {
            throw new InvalidArgumentException('Snapshot id must not be empty.');
        }
        $values = $style->values();
        ksort($values, SORT_STRING);
        try {
            $canonical = json_encode([
                'style_instance_id' => $style->id(),
                'theme_version_id' => $style->themeVersionId(),
                'revision' => $style->revision(),
                'values' => $values,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Style values cannot be snapshotted.', 0, $e);
        }
        return new self($id, $style->tenantId(), $style->id(), $style->themeVersionId(), $style->revision(), $values, hash('sha256', $canonical));
    }

    /** @param array<string,string> $values */
    public static function rehydrate(string $id, string $tenantId, string $styleInstanceId, string $themeVersionId, int $revision, array $values, string $contentHash): self
    {
        return new self($id, $tenantId, $styleInstanceId, $themeVersionId, $revision, $values, $contentHash);
    }

    public function id(): string { return $this->id; }
    public function tenantId(): string { return $this->tenantId; }
    public function styleInstanceId(): string { return $this->styleInstanceId; }
    public function themeVersionId(): string { return $this->themeVersionId; }
    public function revision(): int { return $this->revision; }
    /** @return array<string,string> */ public function values(): array { return $this->values; }
    public function contentHash(): string { return $this->contentHash; }
}
