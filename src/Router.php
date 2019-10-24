<?php
declare(strict_types=1);

namespace AdamAveray\SlimExtensions;

class Router extends \Slim\Router
{
    private $defaultUrlSegments = [];

    public function setDefaultUrlSegment(string $key, $value): void
    {
        $this->defaultUrlSegments[$key] = $value;
    }

    public function pushGroup($pattern, $callable): RouteGroup
    {
        $group = new RouteGroup($pattern, $callable);
        $this->routeGroups[] = $group;
        return $group;
    }

    public function relativePathFor($name, array $data = [], array $queryParams = []): string
    {
        $data = array_merge($this->defaultUrlSegments, $data);
        return parent::relativePathFor($name, $data, $queryParams);
    }
}
