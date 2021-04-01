<?php
declare(strict_types=1);

namespace AdamAveray\SlimExtensions;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\RouteInterface;

class RouteGroup extends \Slim\RouteGroup
{
    private bool $isBound = false;
    /** @var array<string, array{callable: callable, ignoreNull: bool, required: bool}>  */
    private array $converters = [];
    /** @var App|null $app */
    private ?App $app;

    public function __invoke(\Slim\App $app = null)
    {
        if (!$app instanceof App) {
            throw new \UnexpectedValueException(
                'Subclassed "' . __CLASS__ . '" requires app "' . App::class . '" subclass'
            );
        }

        $this->app = $app;
        parent::__invoke($app);
    }

    private function bind(): void
    {
        $_this = $this;

        $this->add(function (ServerRequestInterface $request, ResponseInterface $response, $route) use ($_this) {
            $next = $route;
            if ($route instanceof \Closure) {
                $mirror = new \ReflectionFunction($route);
                $route = $mirror->getClosureThis();
            }
            if (!$route instanceof RouteInterface) {
                throw new \UnexpectedValueException('Route instance not provided');
            }

            foreach ($_this->converters as $argument => $converter) {
                $_this->convertArgument($argument, $converter, $route);
            }

            return $next($request, $response);
        });
    }

    /**
     * @param mixed $argument  The name of the argument to convert
     * @param array $converter The converter configuration (set from ::convert())
     * @param RouteInterface $route
     * @throws \Slim\Exception\NotFoundException
     */
    private function convertArgument(string $argument, array $converter, RouteInterface $route): void
    {
        // Load raw value
        $value = $route->getArgument($argument);

        if ($value === null && $converter['ignoreNull']) {
            // Ignore empty value
            return;
        }

        // Convert value
        $value = $converter['callable']($value, $argument, $route);

        if ($value === null && $converter['required']) {
            // Required value not loaded
            $this->app->notFound('Required value "' . $argument . '" not loaded');
            return;
        }

        // Update route with converted value
        $route->setArgument($argument, $value);
    }

    /**
     * @param string $argument		The name of the argument to convert (e.g. 'test' to match `/{test}/`)
     * @param callable $callable	A callable taking the initial argument value and returning the converted value
     *                           	(function(mixed $value, string $argument, Route $route, Request $request, Response $response)
     * @param bool $ignoreNull		If true, the converter will be skipped if the URL does not contain the specified argument
     * @param bool $required		If true, a not-found exception will be thrown if $callable returns `null`
     * @return $this
     */
    public function convert(
        string $argument,
        callable $callable,
        bool $ignoreNull = false,
        bool $required = false
    ): self {
        if (!$this->isBound) {
            $this->bind();
            $this->isBound = true;
        }

        $this->converters[$argument] = [
            'callable' => $callable,
            'ignoreNull' => $ignoreNull,
            'required' => $required,
        ];
        return $this;
    }
}
