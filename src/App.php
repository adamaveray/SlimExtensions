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
use Whoops\Handler as WhoopsHandler;

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
            /** @var Router $router */
            $router = $this->getContainer()->get('router');
            $pattern = $router->prefixPattern($patternOrCallable);
            $callable = new NamespacedMiddleware(
                $this->getContainer(),
                $pattern,
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
        if ($whoops === null) {
            return;
        }

        $isDebug = $container['debug'];

        // Check for existing PrettyPageHandler
        $prettyPageHandler = null;
        $handlers = $whoops->getHandlers();
        foreach ($whoops->getHandlers() as $handler) {
            if ($handler instanceof WhoopsHandler\PrettyPageHandler) {
                $prettyPageHandler = $handler;
                break;
            }
        }

        // Setup error handlers
        $handler = static function (Container $container) use ($prettyPageHandler): callable {
            /** @var \Whoops\Run $whoops */
            $whoops = $container['whoops'];

            return static function (Request $request, ResponseInterface $_, $error) use (
                $whoops,
                $prettyPageHandler
            ): void {
                if ($prettyPageHandler !== null) {
                    // Add more information to the PrettyPageHandler
                    self::configureWhoopsPrettyPageHandler($prettyPageHandler, $request);
                }

                $whoops->handleException($error);
                exit();
            };
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
            if (is_a($className, Response::class, true)) {
                // Custom subclass
                $response = new $className(StatusCode::HTTP_OK, $headers, null, $container);
                $response->setIsDebug($container['debug']);
            } else {
                // Assume generic Response class
                $response = new $className(StatusCode::HTTP_OK, $headers);
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
    public function map(array $methods, $patterns, $callableOrName, $callable = null): RouteInterface
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

            $routes[$i] = self::setNameIfSet(
                parent::map($methods, $pattern, $callable ?? $callableOrName),
                $callableOrName
            );
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

    /**
     * @param WhoopsHandler\PrettyPageHandler $handler The handler to bind update
     * @param Request $request The current request
     */
    private static function configureWhoopsPrettyPageHandler(
        WhoopsHandler\PrettyPageHandler $handler,
        Request $request
    ): void {
        $emptyValue = '<none>';

        $contentCharset = $emptyValue;
        if ($request instanceof \Slim\Http\Request && $request->getContentCharset() !== null) {
            $contentCharset = $request->getContentCharset();
        }

        $handler->addDataTable('Slim Application', [
            'Version' => self::VERSION,
            'Accept Charset' => $request->getHeader('ACCEPT_CHARSET') ?: $emptyValue,
            'Content Charset' => $contentCharset,
            'HTTP Method' => $request->getMethod(),
            'Path' => $request->getUri()->getPath(),
            'Query String' => $request->getUri()->getQuery() ?: $emptyValue,
            'Base URL' => (string) $request->getUri(),
            'Scheme' => $request->getUri()->getScheme(),
            'Port' => $request->getUri()->getPort(),
            'Host' => $request->getUri()->getHost(),
            'Request Attributes' => $request->getAttributes() ?: $emptyValue,
        ]);
    }
}
