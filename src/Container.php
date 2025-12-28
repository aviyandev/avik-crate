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
    /**
     * The current globally available container (if any).
     */
    protected static ?Container $instance = null;

    protected array $bindings        = [];
    protected array $singletons      = [];
    protected array $instances       = [];
    protected array $aliases         = [];
    protected array $resolving       = [];
    protected array $tags            = [];
    protected array $contextual      = [];
    protected array $extenders       = [];
    protected array $reflectionCache = [];

    protected array $resolvingCallbacks      = [];
    protected array $afterResolvingCallbacks = [];

    /**
     * Set the globally available instance of the container.
     */
    public static function setInstance(?Container $container = null): ?Container
    {
        return static::$instance = $container;
    }

    /**
     * Get the globally available instance of the container.
     */
    public static function getInstance(): static
    {
        if (is_null(static::$instance)) {
            static::$instance = new static;
        }

        return static::$instance;
    }

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

    /**
     * Assign a set of tags to a given binding.
     */
    public function tag(array|string $abstracts, array|string $tags): void
    {
        $tags = (array) $tags;

        foreach ((array) $abstracts as $abstract) {
            foreach ($tags as $tag) {
                $this->tags[$tag][] = $abstract;
            }
        }
    }

    /**
     * Resolve all of the bindings for a given tag.
     */
    public function tagged(string $tag): iterable
    {
        if (!isset($this->tags[$tag])) {
            return [];
        }

        foreach ($this->tags[$tag] as $abstract) {
            yield $this->make($abstract);
        }
    }

    /**
     * Define a contextual binding.
     */
    public function when(string $concrete): ContextualBindingBuilder
    {
        return new ContextualBindingBuilder($this, $concrete);
    }

    /**
     * Add a contextual binding to the container.
     */
    public function addContextualBinding(string $concrete, string $abstract, Closure|string $implementation): void
    {
        $this->contextual[$concrete][$abstract] = $implementation;
    }

    /**
     * "Extend" a service in the container.
     */
    public function extend(string $abstract, Closure $closure): void
    {
        $abstract = $this->aliases[$abstract] ?? $abstract;

        if (isset($this->instances[$abstract])) {
            $this->instances[$abstract] = $closure($this->instances[$abstract], $this);
        } else {
            $this->extenders[$abstract][] = $closure;
        }
    }

    /**
     * Register a new resolving callback.
     */
    public function resolving(string $abstract, Closure $callback): void
    {
        $this->resolvingCallbacks[$abstract][] = $callback;
    }

    /**
     * Register a new after resolving callback.
     */
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

        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        if (in_array($abstract, $this->resolving, true)) {
            throw new CircularDependencyException(
                "Circular dependency detected: " . implode(' → ', [...$this->resolving, $abstract])
            );
        }

        $this->resolving[] = $abstract;

        foreach ($this->resolvingCallbacks[$abstract] ?? [] as $callback) {
            $callback($this);
        }

        $concrete = $this->singletons[$abstract]
            ?? $this->bindings[$abstract]
            ?? $abstract;

        $object = $this->build($concrete, $parameters);

        foreach ($this->extenders[$abstract] ?? [] as $extender) {
            $object = $extender($object, $this);
        }

        foreach ($this->afterResolvingCallbacks[$abstract] ?? [] as $callback) {
            $callback($object, $this);
        }

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

        $reflector = $this->reflectionCache[$concrete] ??= new ReflectionClass($concrete);

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

        // 1. Check if it's explicitly passed in parameters
        if (array_key_exists($name, $parameters)) {
            return $parameters[$name];
        }

        // 2. Check for contextual binding
        $class = $parameter->getDeclaringClass();
        if ($class && isset($this->contextual[$class->getName()][$name])) {
            $concrete = $this->contextual[$class->getName()][$name];
            return $concrete instanceof Closure ? $concrete($this) : $this->make($concrete);
        }

        $type = $parameter->getType();

        // 3. Check for contextual binding by type
        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && $class && isset($this->contextual[$class->getName()][$type->getName()])) {
            $concrete = $this->contextual[$class->getName()][$type->getName()];
            return $concrete instanceof Closure ? $concrete($this) : $this->make($concrete);
        }

        // 4. Resolve by type-hint
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

        // 5. Handle Variadic
        if ($parameter->isVariadic()) {
            return [];
        }

        // 6. Use default value if available
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw new BindingResolutionException(
            "Unable to resolve parameter [$name] in " . ($class ? $class->getName() : 'closure')
        );
    }

    /* =========================
       CALLABLE INJECTION
       ========================= */

    /**
     * Call the given Closure / class@method and inject its dependencies.
     */
    public function call(callable|string $callable, array $parameters = []): mixed
    {
        if (is_string($callable) && str_contains($callable, '@')) {
            [$class, $method] = explode('@', $callable);
            $callable = [$this->make($class), $method];
        }

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

    /**
     * Wrap the given closure such that its dependencies will be injected when executed.
     */
    public function wrap(Closure $callback, array $parameters = []): Closure
    {
        return fn() => $this->call($callback, $parameters);
    }

    /**
     * Get a closure to resolve the given type from the container.
     */
    public function factory(string $abstract): Closure
    {
        return fn() => $this->make($abstract);
    }

    /* =========================
       CLEANUP
       ========================= */

    /**
     * Remove a specific instance from the container.
     */
    public function forgetInstance(string $abstract): void
    {
        unset($this->instances[$abstract]);
    }

    /**
     * Flush the container of all bindings and resolved instances.
     */
    public function flush(): void
    {
        $this->bindings        = [];
        $this->singletons      = [];
        $this->instances       = [];
        $this->aliases         = [];
        $this->tags            = [];
        $this->contextual      = [];
        $this->extenders       = [];
        $this->reflectionCache = [];
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
