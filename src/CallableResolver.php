<?php
declare(strict_types=1);

namespace AdamAveray\SlimExtensions;

use AdamAveray\SlimExtensions\Http\Response;
use AdamAveray\SlimExtensions\Routes\Controller;
use AdamAveray\SlimExtensions\Routes\ControllerInterface;
use Interop\Container\ContainerInterface;
use Slim\App;
use Slim\Http\Request;
use Slim\Interfaces\CallableResolverInterface;
use Slim\Interfaces\RouteInterface;

/**
 * The alternative of the \Slim\CallableResolver to auto-resolve parameter dependencies
 *
 * @see https://medium.com/@Dewey92/controller-in-slim-framework-3-48a3310452d0
 * @see https://www.ltconsulting.co.uk/automatic-dependency-injection-with-phps-reflection-api
 */
class CallableResolver implements CallableResolverInterface
{
    private const ENDPOINT_PREFIX_METHOD = 'endpoint';
    private const ENDPOINT_PREFIX_MIDDLEWARE = 'middleware';
    private const PARAM_REQUEST = 'request';
    private const PARAM_RESPONSE = 'response';
    private const PARAM_NEXT = 'next';
    private const PARAM_ARGS = 'args';

    /** @var ContainerInterface $container */
    private $container;
    /** @var object[] $instances */
    private $instances = [];

    /**
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * @param callable|string $toResolve
     * @return callable
     *
     * @throws \RuntimeException
     */
    public function resolve($toResolve): callable
    {
        $namespacedMiddleware = null;
        if ($toResolve instanceof NamespacedMiddleware) {
            // Process internal middleware
            $namespacedMiddleware = $toResolve;
            $toResolve = $namespacedMiddleware->getMiddleware();
        }

        if (\is_array($toResolve) && \count($toResolve) === 2) {
            // Constructor call
            [$controllerClassName, $methodStub] = $toResolve;

            if (!class_exists($controllerClassName)) {
                throw new \RuntimeException('Controller "' . $controllerClassName . '" does not exist');
            }

            $isController = \is_subclass_of($controllerClassName, ControllerInterface::class);
            $toResolve = $this->wrapMethod($controllerClassName, $methodStub, $isController);
        } else {
            // Generic callable
            if (!\is_callable($toResolve)) {
                throw new \InvalidArgumentException('Callable is not resolvable');
            }

            $toResolve = $this->wrapCallable($toResolve);
        }

        if ($namespacedMiddleware !== null) {
            // Update internal middleware
            $namespacedMiddleware->setMiddleware($toResolve);
            $toResolve = $namespacedMiddleware;
        }

        return $toResolve;
    }

    private function wrapMethod(string $className, string $methodStub, bool $isController = false): callable
    {
        $self = $this;

        return function (...$arguments) use ($self, $className, $methodStub, $isController) {
            [$request, $response, $next, $args] = self::processRawArguments($arguments);

            // Validate method
            if ($isController) {
                // Add controller prefixes
                $prefix = $next === null ? self::ENDPOINT_PREFIX_METHOD : self::ENDPOINT_PREFIX_MIDDLEWARE;
                $method = $prefix . ucfirst($methodStub);
            } else {
                // Leave method as-is
                $method = $methodStub;
            }

            try {
                $reflector = new \ReflectionMethod($className, $method);
            } catch (\ReflectionException $e) {
                throw new \BadMethodCallException('Unknown method "' . $methodStub . '" on class ' . $className);
            }

            if ($reflector->isStatic()) {
                // Static call
                $callable = [$className, $method];
            } else {
                // Instance call - instantiate class
                /** @var Controller $instance */
                $instance = $self->resolveClass($className, $request, $response);

                if ($isController && $request !== null && $response !== null) {
                    // Setup controller
                    $instance->setRequestResponse($request, $response);
                }

                $callable = [$instance, $method];
            }

            if (!\is_callable($callable)) {
                throw new \BadMethodCallException('Method "' . $methodStub . '" is not callable on ' . $className);
            }

            return $self->invokeCallable($callable, $reflector, $request, $response, $next, $args);
        };
    }

    private function wrapCallable(callable $callable): callable
    {
        $self = $this;

        return function (...$args) use ($self, $callable) {
            [$request, $response, $next, $args] = self::processRawArguments($args);

            if (\is_callable([$callable, '__invoke'])) {
                // Invokable class instance
                $reflector = new \ReflectionMethod($callable, '__invoke');
            } else {
                // Assume standard callable
                $reflector = new \ReflectionFunction($callable);
            }

            if ($callable instanceof \Closure && $this !== $self) {
                // Pass through binding
                $callable = $callable->bindTo($this);
            }

            return $self->invokeCallable($callable, $reflector, $request, $response, $next, $args);
        };
    }

    private function invokeCallable(
        callable $callable,
        \ReflectionFunctionAbstract $reflector,
        ?Request $request,
        ?Response $response,
        ?callable $next,
        array $args = []
    ) {
        // Determine method dependencies
        $dependencies = $this->getDependencies($reflector->getParameters(), $request, $response, $next, $args);
        $arguments = array_merge($dependencies, [$request, $response, $args]);

        // Pass-through method
        return $callable(...$arguments);
    }

    /**
     * Build an instance of the given class
     *
     * @param string $class
     * @param Request|null $request
     * @param Response|null $response
     * @return object
     * @throws \ReflectionException
     */
    protected function resolveClass(string $class, ?Request $request = null, ?Response $response = null): object
    {
        // Instantiate class if not in cache
        if (!isset($this->instances[$class])) {
            $reflector = new \ReflectionClass($class);
            if (!$reflector->isInstantiable()) {
                throw new \BadMethodCallException('"' . $class . '" is not instantiable');
            }

            $constructor = $reflector->getConstructor();
            if ($constructor === null) {
                return new $class();
            }
            $parameters = $constructor->getParameters();
            $dependencies = $this->getDependencies($parameters, $request, $response, null);
            $this->instances[$class] = new $class(...$dependencies);
        }

        return $this->instances[$class];
    }

    /**
     * @param \ReflectionParameter[] $parameters
     * @param Request|null $request
     * @param Response|null $response
     * @param callable|null $next
     * @param array $args
     * @return array
     */
    private function getDependencies(
        array $parameters,
        ?Request $request,
        ?Response $response,
        ?callable $next,
        array $args = []
    ): array {
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $dependencies[] = $this->resolveParameter($parameter, $request, $response, $next, $args);
        }

        return $dependencies;
    }

    /**
     * @param \ReflectionParameter $parameter
     * @param Request|null $request
     * @param Response|null $response
     * @param callable|null $next
     * @param array $args
     * @return mixed
     */
    private function resolveParameter(
        \ReflectionParameter $parameter,
        ?Request $request,
        ?Response $response,
        ?callable $next,
        array $args = []
    ) {
        $parameterName = $parameter->name;

        // Handle default params
        if ($parameterName === self::PARAM_REQUEST) {
            return $request ?? $this->container->get('request');
        }
        if ($parameterName === self::PARAM_RESPONSE) {
            return $response ?? $this->container->get('response');
        }
        if ($parameterName === self::PARAM_NEXT) {
            return $next;
        }
        if ($parameterName === self::PARAM_ARGS) {
            return $args;
        }

        // Check request
        if ($request !== null) {
            $attribute = $request->getAttribute($parameterName);
            if ($attribute !== null) {
                // Found in request
                return $attribute;
            }
        }

        // Check route
        if ($next instanceof RouteInterface) {
            $argument = $next->getArgument($parameterName);
            if ($argument !== null) {
                // Found in route
                return $argument;
            }
        }

        // Check container
        if ($this->container->has($parameterName)) {
            // Found in container
            return $this->container->get($parameterName);
        }

        $typehintClass = $parameter->getClass();
        if ($typehintClass !== null) {
            // Check params
            $typehintClassName = $typehintClass->name;
            foreach ($args as $key => $param) {
                if (\is_object($param) && $param instanceof $typehintClassName) {
                    // Param matches
                    return $param;
                }
            }
        }

        // Use default value if available
        if ($parameter->isOptional()) {
            return $parameter->getDefaultValue();
        }

        // Use null if possible
        if ($parameter->allowsNull()) {
            return null;
        }

        throw new \RuntimeException('Cannot resolve parameter "' . $parameterName . '"');
    }

    private static function processRawArguments(array $args): array
    {
        $app = $args[0] ?? null;
        if ($app instanceof App) {
            // Group call - no arguments
            return [null, null, null, [$app]];
        }

        [$request, $response, $nextOrParams] = $args;

        $params = [];
        $next = null;

        if (\is_callable($nextOrParams)) {
            $next = $nextOrParams;
        } else {
            $params = $nextOrParams;
        }

        return [$request, $response, $next, $params];
    }
}
