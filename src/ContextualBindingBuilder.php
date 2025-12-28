<?php

declare(strict_types=1);

namespace Avik\Crate;

use Closure;

final class ContextualBindingBuilder
{
    /**
     * The container instance.
     *
     * @var Container
     */
    protected Container $container;

    /**
     * The concrete class that is being configured.
     *
     * @var string
     */
    protected string $concrete;

    /**
     * The abstract dependency that is being configured.
     *
     * @var string
     */
    protected string $needs;

    /**
     * Create a new contextual binding builder instance.
     *
     * @param  Container  $container
     * @param  string  $concrete
     * @return void
     */
    public function __construct(Container $container, string $concrete)
    {
        $this->container = $container;
        $this->concrete = $concrete;
    }

    /**
     * Define the abstract target that depends on the context.
     *
     * @param  string  $abstract
     * @return $this
     */
    public function needs(string $abstract): self
    {
        $this->needs = $abstract;

        return $this;
    }

    /**
     * Define the implementation for the contextual binding.
     *
     * @param  Closure|string  $implementation
     * @return void
     */
    public function give(Closure|string $implementation): void
    {
        $this->container->addContextualBinding(
            $this->concrete,
            $this->needs,
            $implementation
        );
    }
}
