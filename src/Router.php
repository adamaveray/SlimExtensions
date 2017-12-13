<?php
namespace AdamAveray\SlimExtensions;

class Router extends \Slim\Router {
	public function pushGroup($pattern, $callable){
		$group = new RouteGroup($pattern, $callable);
		$this->routeGroups[] = $group;
		return $group;
	}
}
