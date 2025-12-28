<?php

declare(strict_types=1);

namespace Avik\Crate;

use Avik\Seed\Contracts\Container as ContainerContract;
use Closure;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionParameter;
use Avik\Crate\Exceptions\{
    BindingResolutionException,
    NotInstantiableException,
    CircularDependencyException
};

final class Container implements ContainerContract
{
    protected array $bindings   = [];
    protected array $singletons = [];
    protected array $instances  = [];
    protected array $aliases    = [];
    protected array $resolving  = [];

    /* =========================
       REGISTRATION
       ========================= */

    public function bind(string $abstract, string|Closure $concrete): void
    {
        $this->bindings[$abstract] = $concrete;
    }

    public function singleton(string $abstract, string|Closure $concrete): void
    {
        $this->singletons[$abstract] = $concrete;
    }

    public function instance(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    public function alias(string $abstract, string $alias): void
    {
        $this->aliases[$alias] = $abstract;
    }

    /* =========================
       RESOLUTION
       ========================= */

    public function make(string $abstract, array $parameters = []): mixed
    {
        $abstract = $this->aliases[$abstract] ?? $abstract;

        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        if (in_array($abstract, $this->resolving, true)) {
            throw new CircularDependencyException(
                "Circular dependency detected: " . implode(' → ', [...$this->resolving, $abstract])
            );
        }

        $this->resolving[] = $abstract;

        $concrete = $this->singletons[$abstract]
            ?? $this->bindings[$abstract]
            ?? $abstract;

        $object = $this->build($concrete, $parameters);

        if (isset($this->singletons[$abstract])) {
            $this->instances[$abstract] = $object;
        }

        array_pop($this->resolving);

        return $object;
    }

    protected function build(string|Closure $concrete, array $parameters): mixed
    {
        if ($concrete instanceof Closure) {
            return $concrete($this, $parameters);
        }

        if (!class_exists($concrete)) {
            throw new BindingResolutionException("Target [$concrete] does not exist.");
        }

        $reflector = new ReflectionClass($concrete);

        if (!$reflector->isInstantiable()) {
            throw new NotInstantiableException("Target [$concrete] is not instantiable.");
        }

        $constructor = $reflector->getConstructor();

        if (!$constructor) {
            return new $concrete;
        }

        $dependencies = array_map(
            fn(ReflectionParameter $param) =>
            $this->resolveParameter($param, $parameters),
            $constructor->getParameters()
        );

        return $reflector->newInstanceArgs($dependencies);
    }

    protected function resolveParameter(
        ReflectionParameter $parameter,
        array $parameters
    ): mixed {
        $name = $parameter->getName();

        if (array_key_exists($name, $parameters)) {
            return $parameters[$name];
        }

        $type = $parameter->getType();

        if ($type && !$type->isBuiltin()) {
            return $this->make($type->getName());
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw new BindingResolutionException(
            "Unable to resolve parameter [$name]"
        );
    }

    /* =========================
       CALLABLE INJECTION
       ========================= */

    public function call(callable $callable, array $parameters = []): mixed
    {
        $reflection = is_array($callable)
            ? new ReflectionMethod($callable[0], $callable[1])
            : new ReflectionFunction($callable);

        $args = array_map(
            fn(ReflectionParameter $param) =>
            $this->resolveParameter($param, $parameters),
            $reflection->getParameters()
        );

        return $callable(...$args);
    }

    /* =========================
       INTROSPECTION
       ========================= */

    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract])
            || isset($this->singletons[$abstract])
            || isset($this->instances[$abstract]);
    }

    public function resolved(string $abstract): bool
    {
        return isset($this->instances[$abstract]);
    }
}
