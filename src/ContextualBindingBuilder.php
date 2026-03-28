<?php

declare(strict_types=1);

namespace Avik\Crate;

use Closure;

final class ContextualBindingBuilder
{
    public function __construct(
        protected Container $container,
        protected string $concrete
    ) {}

    public function needs(string $abstract): self
    {
        $this->needs = $abstract;   // Note: $this->needs is used dynamically
        return $this;
    }

    public function give(Closure|string $implementation): void
    {
        $this->container->addContextualBinding(
            $this->concrete,
            $this->needs ?? throw new \LogicException('You must call needs() before give()'),
            $implementation
        );
    }

    private string $needs; // Dynamic property for fluent API
}