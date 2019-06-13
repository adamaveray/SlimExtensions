<?php
declare(strict_types=1);

namespace AdamAveray\SlimExtensions;

use Slim\Container as BaseContainer;
use Whoops\Run as Whoops;

/**
 * @property-read Whoops $whoops
 */
class Container extends BaseContainer
{
    public function __construct(array $values = [])
    {
        parent::__construct($values);
        $this->registerDefaultServices();
    }

    protected function registerDefaultServices(): void
    {
        // To be implemented by subclasses
    }

    public static function callableToGenerator(callable $callable): callable
    {
        return static function () use ($callable) {
            return $callable;
        };
    }
}
