<?php
declare(strict_types=1);

namespace AdamAveray\SlimExtensions;

class Router extends \Slim\Router
{
    public function pushGroup($pattern, $callable): RouteGroup
    {
        $group = new RouteGroup($pattern, $callable);
        $this->routeGroups[] = $group;
        return $group;
    }
}
