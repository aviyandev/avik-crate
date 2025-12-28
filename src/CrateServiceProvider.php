<?php

declare(strict_types=1);

namespace Avik\Crate;

use Avik\Seed\Contracts\ServiceProvider;

final class CrateServiceProvider implements ServiceProvider
{
    public function register(): void
    {
        $container = Container::getInstance();

        $container->instance(Container::class, $container);
        $container->instance('container', $container);
    }

    public function boot(): void {}
}
