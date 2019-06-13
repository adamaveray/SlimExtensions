<?php
declare(strict_types=1);

namespace AdamAveray\SlimExtensions;

use Psr\Container\ContainerInterface as Container;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\UriInterface as Uri;

class NamespacedMiddleware
{
    /** @var Container $container */
    private $container;
    /** @var string $pattern */
    private $pattern;
    /** @var mixed $middleware */
    private $middleware;
    /** @var string[] $excludedPaths */
    private $excludedPaths;

    public function __construct(Container $container, string $pattern, $middleware, ?array $excludedPaths = null)
    {
        $this->container = $container;
        $this->middleware = $middleware;
        $this->pattern = $pattern;
        $this->excludedPaths = $excludedPaths ?? [];
    }

    /**
     * @return mixed
     */
    public function getMiddleware()
    {
        return $this->middleware;
    }

    /**
     * @param callable $middleware
     */
    public function setMiddleware(callable $middleware): void
    {
        $this->middleware = $middleware;
    }

    /**
     * @param Request $request
     * @param Response $response
     * @param callable $next
     * @return Response
     */
    public function __invoke(Request $request, Response $response, callable $next): Response
    {
        if (!$this->canRun($request->getUri())) {
            // Disallowed - do not apply middleware
            return $next($request, $response);
        }

        // Call actual middleware
        $callable = $this->middleware;
        if ($callable instanceof \Closure) {
            // Bind container
            $callable = $callable->bindTo($this->container);
        }
        return $callable($request, $response, $next);
    }

    private function canRun(Uri $uri): bool
    {
        $path = $uri->getPath();
        if (substr($path, 0, strlen($this->pattern)) !== $this->pattern) {
            // Does not begin with pattern - does not match
            return false;
        }
        if (\in_array($path, $this->excludedPaths, true)) {
            // Excluded
            return false;
        }
        return true;
    }
}
