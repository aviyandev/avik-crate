<?php

declare(strict_types=1);

namespace Avik\Crate;

use Avik\Ignite\Application;
use Avik\Seed\Contracts\ServiceProvider;

final class CrateServiceProvider implements ServiceProvider
{
    public function __construct(private Application $app) {}

    public function register(): void
    {
        $container = Container::getInstance();

        // Bind container to itself
        $container->instance(Container::class, $container);
        $container->instance(ContainerContract::class, $container);
        $container->instance('container', $container);

        // Also bind to the application
        $this->app->instance(Container::class, $container);
        $this->app->instance(ContainerContract::class, $container);
    }

    public function boot(): void
    {
        // Reserved for future use
    }
}