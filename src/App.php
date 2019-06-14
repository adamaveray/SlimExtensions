<?php
declare(strict_types=1);

namespace AdamAveray\SlimExtensions;

use AdamAveray\SlimExtensions\Http\Response;
use Psr\Container\ContainerExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\SlimException;
use Slim\Exception\NotFoundException as SlimNotFoundException;
use Slim\Http\StatusCode;
use Slim\Interfaces\RouteInterface;
use Slim\Http\Headers;
use Slim\Http\Request as BaseRequest;
use Slim\Http\Response as BaseResponse;
use Whoops\Handler\PrettyPageHandler as WhoopsPrettyPageHandler;
use Zeuxisoo\Whoops\Provider\Slim\WhoopsErrorHandler;
use Zeuxisoo\Whoops\Provider\Slim\WhoopsMiddleware;

/**
 * @method RouteGroup group(string $pattern, callable $callable)
 * @method Container getContainer()
 */
class App extends \Slim\App
{
    public function __construct(array $container = [])
    {
        $container['settings'] = $this->getInitialSettings($container['settings'] ?? []);

        $container['debug'] = $container['debug'] ?? $container['settings']['debug'];

        $container['notFoundHandler'] = Container::callableToGenerator(static function (
            Request $request,
            Response $response
        ): Response {
            return $response->withNotFound('Not found', [
                'uri' => $request->getUri(),
                'attributes' => array_keys($request->getAttributes()),
            ]);
        });

        $container['errorHandler'] = $container['debug']
            ? null
            : static function (Container $container): callable {
                return static function (Request $_1, Response $_2, \Throwable $exception) use ($container): void {
                    echo $container->whoops->handleException($exception);
                    exit();
                };
            };
        $container['phpErrorHandler'] = $container['errorHandler'];

        $container['callableResolver'] =
            $container['callableResolver'] ??
            static function (Container $container): CallableResolver {
                return new CallableResolver($container);
            };

        $this->setupWhoops($container);
        $this->setupRouter($container);
        $this->setupResponse($container);

        parent::__construct($this->convertContainerToObject($container));
    }

    /**
     * (For subclassing)
     *
     * @param array $container
     * @return Container
     */
    protected function convertContainerToObject(array $container): Container
    {
        return new Container($container);
    }

    /**
     * @param string|callable $patternOrCallable
     * @param callable|null $callable
     * @param string[]|null $excludedPatterns
     * @return $this
     */
    public function add($patternOrCallable, $callable = null, $excludedPatterns = null): self
    {
        if ($callable === null) {
            $callable = $patternOrCallable;
        } else {
            $callable = new NamespacedMiddleware(
                $this->getContainer(),
                $patternOrCallable,
                $callable,
                $excludedPatterns
            );
        }

        return parent::add($callable);
    }

    /** {@inheritDoc} */
    public function get($pattern, $callableOrName, $callable = null): RouteInterface
    {
        return self::setNameIfSet(parent::get($pattern, $callable ?? $callableOrName), $callableOrName);
    }
    /** {@inheritDoc} */
    public function post($pattern, $callableOrName, $callable = null): RouteInterface
    {
        return self::setNameIfSet(parent::post($pattern, $callable ?? $callableOrName), $callableOrName);
    }
    /** {@inheritDoc} */
    public function patch($pattern, $callableOrName, $callable = null): RouteInterface
    {
        return self::setNameIfSet(parent::patch($pattern, $callable ?? $callableOrName), $callableOrName);
    }
    /** {@inheritDoc} */
    public function put($pattern, $callableOrName, $callable = null): RouteInterface
    {
        return self::setNameIfSet(parent::put($pattern, $callable ?? $callableOrName), $callableOrName);
    }
    /** {@inheritDoc} */
    public function delete($pattern, $callableOrName, $callable = null): RouteInterface
    {
        return self::setNameIfSet(parent::delete($pattern, $callable ?? $callableOrName), $callableOrName);
    }
    /** {@inheritDoc} */
    public function options($pattern, $callableOrName, $callable = null): RouteInterface
    {
        return self::setNameIfSet(parent::options($pattern, $callable ?? $callableOrName), $callableOrName);
    }
    /** {@inheritDoc} */
    public function any($pattern, $callableOrName, $callable = null): RouteInterface
    {
        return self::setNameIfSet(parent::any($pattern, $callable ?? $callableOrName), $callableOrName);
    }

    private static function setNameIfSet(RouteInterface $route, $name): RouteInterface
    {
        if (\is_string($name)) {
            $route = $route->setName($name);
        }
        return $route;
    }

    private function getInitialSettings(array $settings): array
    {
        if (\defined('IS_DEBUG') && !isset($settings['debug'])) {
            $settings['debug'] = IS_DEBUG;
        }
        if ($settings['debug'] ?? false) {
            $settings['displayErrorDetails'] = true;
        }

        if (!isset($settings['patternValidator'])) {
            $settings['patternValidator'] = static function (string $pattern): bool {
                // Must end with trailing slash or contain extension (not both)
                return substr($pattern, -1) === '/' xor strpos($pattern, '.') !== false;
            };
        }

        if (!isset($settings['responseClass'])) {
            $settings['responseClass'] = Http\Response::class;
        }

        return $settings;
    }

    private function setupWhoops(array &$container): void
    {
        /** @var \Whoops\Run $whoops */
        $whoops = self::getFromContainer($container, 'whoops');

        // Import handlers from given Whoops instance
        $handlers = self::getFromContainer($container, 'whoops.handlers') ?? [];
        if ($whoops !== null) {
            $handlers = $whoops->getHandlers();
            if (isset($handlers[0]) && $handlers[0] instanceof WhoopsPrettyPageHandler) {
                // Remove original PrettyPageHandler
                /** @var WhoopsPrettyPageHandler $legacyHandler */
                $legacyHandler = array_shift($handlers);

                // Import settings
                $container['settings']['whoops.editor'] = [$legacyHandler, 'getEditorHref'];
                $container['settings']['whoops.pageTitle'] = $legacyHandler->getPageTitle();
            }
        }

        $this->add(new WhoopsMiddleware($this, $handlers));

        // Setup error handlers
        $handler = static function (Container $container): WhoopsErrorHandler {
            return new WhoopsErrorHandler($container['whoops']);
        };
        if (!isset($container['errorHandler'])) {
            $container['errorHandler'] = $handler;
        }
        if (!isset($container['phpErrorHandler'])) {
            $container['phpErrorHandler'] = $handler;
        }
    }

    private function setupRouter(array &$container): void
    {
        $container['router'] = static function (Container $container): Router {
            $routerCacheFile = $container['settings']['routerCacheFile'] ?? false;

            /** @var Router $router */
            $router = (new Router())->setCacheFile($routerCacheFile);
            if (method_exists($router, 'setContainer')) {
                $router->setContainer($container);
            }

            return $router;
        };
    }

    private function setupResponse(array &$container): void
    {
        if (isset($container['response'])) {
            // Custom response exists
            return;
        }

        $container['response'] = static function (Container $container) {
            $headers = new Headers(['Content-Type' => 'text/html; charset=UTF-8']);
            $className = $container['settings']['responseClass'];
            /** @var ResponseInterface $response */
            $response = new $className(StatusCode::HTTP_OK, $headers);
            if ($response instanceof Response) {
                // Set custom extensions
                $response->setIsDebug($container['debug']);
                $response->setContainer($container);
            }
            return $response->withProtocolVersion($container['settings']['httpVersion']);
        };
    }

    /**
     * @param string[] $methods         Numeric array of HTTP method names
     * @param string|string[] $patterns One or more route URI patterns
     * @param callable|string $callable The route callback routine
     * @return RouteInterface|RouteInterface[]
     * @throws \InvalidArgumentException A pattern does not pass the defined pattern validator
     */
    public function map(array $methods, $patterns, $callable)
    {
        try {
            $validator = $this->getContainer()->get('patternValidator');
        } catch (ContainerExceptionInterface $e) {
            $validator = null;
        }

        $routes = [];
        foreach ((array) $patterns as $i => $pattern) {
            if ($validator !== null && !$validator($pattern)) {
                throw new \InvalidArgumentException('Pattern is invalid (' . $pattern . ')');
            }

            $routes[$i] = parent::map($methods, $pattern, $callable);
        }

        if (\is_array($patterns)) {
            // Multiple patterns provided - return all routes
            return $routes;
        } else {
            // Only single pattern provided - return single route
            return current($routes);
        }
    }

    /**
     * @param string $message
     * @param BaseRequest|null $request
     * @param BaseResponse|null $response
     * @throws SlimNotFoundException
     */
    public function notFound(string $message, ?BaseRequest $request = null, ?BaseResponse $response = null): void
    {
        $this->fallbackObjects($request, $response);

        /** @var SlimNotFoundException $e */
        $e = $this->injectException(new SlimNotFoundException($request, $response), $message);
        throw $e;
    }

    /**
     * @param string|\Exception $messageOrException
     * @param int $status
     * @param BaseRequest|null $request
     * @param BaseResponse|null $response
     * @throws SlimException
     */
    public function error(
        $messageOrException,
        int $status = StatusCode::HTTP_INTERNAL_SERVER_ERROR,
        ?BaseRequest $request = null,
        ?BaseResponse $response = null
    ): void {
        $this->fallbackObjects($request, $response);

        // Set status
        $response = $response->withStatus($status);

        $message = null;
        $previous = null;
        if ($messageOrException instanceof \Exception) {
            $previous = $messageOrException;
        } else {
            $message = $messageOrException;
        }
        /** @var SlimException $e */
        $e = $this->injectException(new SlimException($request, $response), $message, $previous);
        throw $e;
    }

    /**
     * Overwrite the given values in an existing Exception object
     *
     * @param \Throwable $e             The exception to overwrite values in
     * @param string|null $message      The `->message` value to overwrite, or null to preserve existing
     * @param \Throwable|null $previous The `->previous` value to overwrite, or null to preserve existing
     * @return \Throwable The Exception provided as $e
     */
    private function injectException(\Throwable $e, ?string $message = null, ?\Throwable $previous = null): \Throwable
    {
        $mirror = new \ReflectionClass($e);

        $values = [
            'message' => $message,
            'previous' => $previous,
        ];
        foreach ($values as $propertyName => $value) {
            if ($value === null) {
                continue;
            }

            $property = $mirror->getProperty($propertyName);
            $property->setAccessible(true);
            $property->setValue($e, $value);
        }

        return $e;
    }

    /**
     * @param BaseRequest|null &$request   Will be set by reference to a generic Request instance if null
     * @param BaseResponse|null &$response Will be set by reference to a generic Response instance if null
     */
    private function fallbackObjects(?BaseRequest &$request = null, ?BaseResponse &$response = null): void
    {
        $container = $this->getContainer();
        if ($request === null) {
            $request = $container['request'];
        }
        if ($response === null) {
            $response = $container['response'];
        }
    }

    /**
     * @param array|Container &$container
     * @param string $key
     * @return mixed
     */
    private static function getFromContainer(&$container, string $key)
    {
        $value = $container[$key] ?? null;

        if (\is_array($container) && \is_callable($value)) {
            $value = $value();
            $container[$key] = $value;
        }

        return $value;
    }
}
