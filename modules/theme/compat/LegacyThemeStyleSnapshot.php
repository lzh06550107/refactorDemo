<?php

declare(strict_types=1);

namespace modules\theme\compat;

final readonly class LegacyThemeStyleSnapshot
{
    /** @param array<string,string> $variables */
    public function __construct(
        private int $legacyTemplateId,
        private int $legacyStyleId,
        private int $legacyUniacid,
        private string $templateName,
        private string $templateTitle,
        private string $templateVersion,
        private string $styleName,
        private array $variables,
    ) {}
    public function legacyTemplateId(): int { return $this->legacyTemplateId; }
    public function legacyStyleId(): int { return $this->legacyStyleId; }
    public function legacyUniacid(): int { return $this->legacyUniacid; }
    public function templateName(): string { return $this->templateName; }
    public function templateTitle(): string { return $this->templateTitle; }
    public function templateVersion(): string { return $this->templateVersion; }
    public function styleName(): string { return $this->styleName; }
    /** @return array<string,string> */ public function variables(): array { return $this->variables; }
}
