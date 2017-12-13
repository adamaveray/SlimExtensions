<?php
namespace AdamAveray\SlimExtensions;

use Slim\Interfaces\RouteInterface;

class RouteGroup extends \Slim\RouteGroup {
	private $isBound = false;
	private $converters = [];

	private function bind(){
		$_this = $this;
		$this->add(function($request, $response, $route) use($_this){
			$next = $route;
			if($route instanceof \Closure){
				$mirror = new \ReflectionFunction($route);
				$route = $mirror->getClosureThis();
			}
			if(!$route instanceof RouteInterface){
				throw new \UnexpectedValueException('Route instance not provided');
			}

			foreach($_this->converters as $argument => $converter){
				$value = $route->getArgument($argument);
				if($value !== null || !$converter['ignoreNull']){
					$value = $converter['callable']($value, $argument, $route, $request, $response);
					$route->setArgument($argument, $value);
				}
			}

			return $next($request, $response);
		});
	}

	public function convert($argument, callable $callable, $ignoreNull = false){
		if(!$this->isBound){
			$this->bind();
			$this->isBound = true;
		}

		$this->converters[$argument] = [
			'callable' => $callable,
			'ignoreNull' => $ignoreNull,
		];
		return $this;
	}
}
