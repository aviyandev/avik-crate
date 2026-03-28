<?php

declare(strict_types=1);

namespace Avik\Crate;

use Avik\Seed\Contracts\Container as ContainerContract;
use Closure;
use ArrayAccess;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionParameter;
use Avik\Crate\Exceptions\{
    BindingResolutionException,
    NotInstantiableException,
    CircularDependencyException
};

final class Container implements ContainerContract, ArrayAccess
{
    protected static ?self $instance = null;

    protected array $bindings = [];
    protected array $singletons = [];
    protected array $instances = [];
    protected array $aliases = [];
    protected array $tags = [];
    protected array $contextual = [];
    protected array $extenders = [];
    protected array $reflectionCache = [];

    protected array $resolvingCallbacks = [];
    protected array $afterResolvingCallbacks = [];

    // Stack to detect circular dependencies
    protected array $resolving = [];

    /* =========================
       GLOBAL INSTANCE
       ========================= */

    public static function setInstance(?self $container = null): ?self
    {
        return static::$instance = $container;
    }

    public static function getInstance(): self
    {
        if (is_null(static::$instance)) {
            static::$instance = new self;
        }

        return static::$instance;
    }

    /* =========================
       REGISTRATION
       ========================= */

    public function bind(string $abstract, string|Closure|null $concrete = null): void
    {
        $this->bindings[$abstract] = $concrete ?? $abstract;
    }

    public function singleton(string $abstract, string|Closure|null $concrete = null): void
    {
        $this->singletons[$abstract] = $concrete ?? $abstract;
    }

    public function instance(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    public function alias(string $abstract, string $alias): void
    {
        $this->aliases[$alias] = $abstract;
    }

    public function tag(array|string $abstracts, array|string $tags): void
    {
        foreach ((array) $abstracts as $abstract) {
            foreach ((array) $tags as $tag) {
                $this->tags[$tag][] = $abstract;
            }
        }
    }

    public function tagged(string $tag): iterable
    {
        return isset($this->tags[$tag])
            ? array_map(fn($abstract) => $this->make($abstract), $this->tags[$tag])
            : [];
    }

    public function when(string $concrete): ContextualBindingBuilder
    {
        return new ContextualBindingBuilder($this, $concrete);
    }

    public function addContextualBinding(string $concrete, string $abstract, Closure|string $implementation): void
    {
        $this->contextual[$concrete][$abstract] = $implementation;
    }

    public function extend(string $abstract, Closure $closure): void
    {
        $abstract = $this->aliases[$abstract] ?? $abstract;

        if (isset($this->instances[$abstract])) {
            $this->instances[$abstract] = $closure($this->instances[$abstract], $this);
        } else {
            $this->extenders[$abstract][] = $closure;
        }
    }

    public function resolving(string $abstract, Closure $callback): void
    {
        $this->resolvingCallbacks[$abstract][] = $callback;
    }

    public function afterResolving(string $abstract, Closure $callback): void
    {
        $this->afterResolvingCallbacks[$abstract][] = $callback;
    }

    /* =========================
       RESOLUTION
       ========================= */

    public function make(string $abstract, array $parameters = []): mixed
    {
        $abstract = $this->aliases[$abstract] ?? $abstract;

        // Return existing instance
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // Circular dependency protection
        if (in_array($abstract, $this->resolving, true)) {
            throw new CircularDependencyException(
                "Circular dependency detected: " . implode(' → ', [...$this->resolving, $abstract])
            );
        }

        $this->resolving[] = $abstract;

        // Fire resolving callbacks
        foreach ($this->resolvingCallbacks[$abstract] ?? [] as $callback) {
            $callback($this);
        }

        $concrete = $this->singletons[$abstract]
            ?? $this->bindings[$abstract]
            ?? $abstract;

        $object = $this->build($concrete, $parameters);

        // Apply extenders
        foreach ($this->extenders[$abstract] ?? [] as $extender) {
            $object = $extender($object, $this);
        }

        // Fire after resolving callbacks
        foreach ($this->afterResolvingCallbacks[$abstract] ?? [] as $callback) {
            $callback($object, $this);
        }

        // Cache singleton
        if (isset($this->singletons[$abstract])) {
            $this->instances[$abstract] = $object;
        }

        array_pop($this->resolving);

        return $object;
    }

    protected function build(string|Closure $concrete, array $parameters = []): mixed
    {
        if ($concrete instanceof Closure) {
            return $concrete($this, $parameters);
        }

        if (!class_exists($concrete)) {
            throw new BindingResolutionException("Target class [$concrete] does not exist.");
        }

        $reflector = $this->reflectionCache[$concrete] ??= new ReflectionClass($concrete);

        if (!$reflector->isInstantiable()) {
            throw new NotInstantiableException("Target [$concrete] is not instantiable.");
        }

        $constructor = $reflector->getConstructor();

        if (!$constructor) {
            return new $concrete;
        }

        $dependencies = $this->resolveDependencies($constructor->getParameters(), $parameters);

        return $reflector->newInstanceArgs($dependencies);
    }

    protected function resolveDependencies(array $parameters, array $primitives = []): array
    {
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $dependencies[] = $this->resolveParameter($parameter, $primitives);
        }

        return $dependencies;
    }

    protected function resolveParameter(ReflectionParameter $parameter, array $primitives): mixed
    {
        $name = $parameter->getName();

        // 1. Explicit primitive value passed
        if (array_key_exists($name, $primitives)) {
            return $primitives[$name];
        }

        $declaringClass = $parameter->getDeclaringClass()?->getName();

        // 2. Contextual binding (by name or type)
        if ($declaringClass && isset($this->contextual[$declaringClass])) {
            $context = $this->contextual[$declaringClass];

            if (isset($context[$name])) {
                $concrete = $context[$name];
                return $concrete instanceof Closure ? $concrete($this) : $this->make($concrete);
            }

            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && isset($context[$type->getName()])) {
                $concrete = $context[$type->getName()];
                return $concrete instanceof Closure ? $concrete($this) : $this->make($concrete);
            }
        }

        // 3. Type-hinted dependency
        $type = $parameter->getType();
        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
            try {
                return $this->make($type->getName());
            } catch (BindingResolutionException $e) {
                if ($parameter->isDefaultValueAvailable()) {
                    return $parameter->getDefaultValue();
                }
                if ($type->allowsNull()) {
                    return null;
                }
                throw $e;
            }
        }

        // 4. Variadic parameter
        if ($parameter->isVariadic()) {
            return [];
        }

        // 5. Default value
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw new BindingResolutionException(
            "Unable to resolve parameter [{$name}] in class " . ($declaringClass ?? 'closure')
        );
    }

    /* =========================
       METHOD CALLING
       ========================= */

    public function call(callable|string $callback, array $parameters = []): mixed
    {
        if (is_string($callback) && str_contains($callback, '@')) {
            [$class, $method] = explode('@', $callback, 2);
            $callback = [$this->make($class), $method];
        }

        $reflection = is_array($callback)
            ? new ReflectionMethod($callback[0], $callback[1])
            : new ReflectionFunction($callback);

        $args = $this->resolveDependencies($reflection->getParameters(), $parameters);

        return $callback(...$args);
    }

    public function wrap(Closure $callback, array $parameters = []): Closure
    {
        return fn() => $this->call($callback, $parameters);
    }

    public function factory(string $abstract): Closure
    {
        return fn() => $this->make($abstract);
    }

    /* =========================
       INTROSPECTION & CLEANUP
       ========================= */

    public function has(string $abstract): bool
    {
        $abstract = $this->aliases[$abstract] ?? $abstract;
        return isset($this->bindings[$abstract])
            || isset($this->singletons[$abstract])
            || isset($this->instances[$abstract]);
    }

    public function get(string $abstract): mixed
    {
        return $this->make($abstract);
    }

    public function resolved(string $abstract): bool
    {
        $abstract = $this->aliases[$abstract] ?? $abstract;
        return isset($this->instances[$abstract]);
    }

    public function forgetInstance(string $abstract): void
    {
        unset($this->instances[$abstract]);
    }

    public function flush(): void
    {
        $this->bindings = [];
        $this->singletons = [];
        $this->instances = [];
        $this->aliases = [];
        $this->tags = [];
        $this->contextual = [];
        $this->extenders = [];
        $this->reflectionCache = [];
        $this->resolvingCallbacks = [];
        $this->afterResolvingCallbacks = [];
    }

    /* =========================
       ARRAY ACCESS
       ========================= */

    public function offsetExists(mixed $offset): bool
    {
        return $this->has($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->make($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->bind($offset, $value instanceof Closure ? $value : fn() => $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        unset(
            $this->bindings[$offset],
            $this->singletons[$offset],
            $this->instances[$offset]
        );
    }
}