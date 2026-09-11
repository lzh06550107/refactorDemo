<?php

declare(strict_types=1);

namespace modules\module\domain;

final readonly class BindingRouteDecision
{
    public function __construct(
        private BindingRouteKind $kind,
        private ModuleBinding $binding,
    ) {
    }

    public function kind(): BindingRouteKind { return $this->kind; }
    public function binding(): ModuleBinding { return $this->binding; }
    public function routePath(): ?string { return $this->binding->routePath(); }
    public function legacyCall(): ?string { return $this->binding->legacyCall(); }
}
