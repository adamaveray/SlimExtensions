<?php
declare(strict_types=1);

namespace AdamAveray\SlimExtensions;

use Psr\Container\ContainerInterface as Container;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\UriInterface as Uri;

class NamespacedMiddleware
{
    private const PATH_TYPE_STRING = 'string';
    private const PATH_TYPE_PATTERN = 'pattern';

    private const PATTERN_PLACEHOLDERS = '~\\\\{.*?(?:\\:(.*))?\\\\}~';

    /** @var Container $container */
    private Container $container;
    /** @var array $pattern */
    private array $pattern;
    /** @var mixed $middleware */
    private $middleware;
    /** @var array[] $excludedPaths */
    private array $excludedPaths;

    /**
     * @param Container $container
     * @param string $pattern
     * @param mixed $middleware
     * @param array|null $excludedPaths
     */
    public function __construct(Container $container, string $pattern, $middleware, ?array $excludedPaths = null)
    {
        $this->container = $container;
        $this->middleware = $middleware;
        $this->pattern = self::parsePattern($pattern, true);
        $this->excludedPaths = array_map([__CLASS__, 'parsePattern'], $excludedPaths ?? []);
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
        if (!self::pathMatchesPattern($path, $this->pattern)) {
            // Does not begin with pattern - does not match
            return false;
        }

        // Compare URI with exclusions
        foreach ($this->excludedPaths as $excludedPath) {
            if (self::pathMatchesPattern($path, $excludedPath)) {
                // Excluded
                return false;
            }
        }

        // Valid
        return true;
    }

    private static function parsePattern(string $path, bool $prefixOnly = false): array
    {
        if (strpos($path, '{') === false) {
            // Basic path
            return ['type' => self::PATH_TYPE_STRING, 'string' => $path, 'prefixOnly' => $prefixOnly];
        } else {
            // Pattern
            $pattern = preg_replace_callback(
                self::PATTERN_PLACEHOLDERS,
                static function (array $matches): string {
                    return isset($matches[1]) ? self::preg_unquote($matches[1], '~') : '[^/]+';
                },
                preg_quote($path, '~')
            );

            $pattern = '^' . $pattern;
            if (!$prefixOnly) {
                $pattern .= '$';
            }
            return ['type' => self::PATH_TYPE_PATTERN, 'pattern' => '~' . $pattern . '~'];
        }
    }

    private static function pathMatchesPattern(string $path, array $pattern): bool
    {
        switch ($pattern['type']) {
            case self::PATH_TYPE_STRING:
                if ($pattern['prefixOnly']) {
                    // Match prefix only
                    return substr($path, 0, \strlen($pattern['string'])) === $pattern['string'];
                } else {
                    // Match full string
                    return $path === $pattern['string'];
                }

            case self::PATH_TYPE_PATTERN:
                return preg_match($pattern['pattern'], $path) === 1;

            default:
                throw new \OutOfBoundsException('Unknown pattern type "' . $pattern['type'] . '"');
        }
    }

    private static function preg_unquote(string $str, ?string $delimiter = null): string
    {
        $chars = [
            '\\.' => '.',
            '\\\\' => '\\',
            '\\+' => '+',
            '\\*' => '*',
            '\\?' => '?',
            '\\[' => '[',
            '\\^' => '^',
            '\\]' => ']',
            '\\$' => '$',
            '\\(' => '(',
            '\\)' => ')',
            '\\{' => '{',
            '\\}' => '}',
            '\\=' => '=',
            '\\!' => '!',
            '\\<' => '<',
            '\\>' => '>',
            '\\|' => '|',
            '\\:' => ':',
            '\\-' => '-',
        ];
        if ($delimiter !== null) {
            $chars['\\' . $delimiter] = $delimiter;
        }
        return strtr($str, $chars);
    }
}
