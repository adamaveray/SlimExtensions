<?php
namespace AdamAveray\SlimExtensions;

use Slim\Exception\NotFoundException as SlimNotFoundException;
use Slim\Http\Headers;
use Slim\Http\Request as BaseRequest;
use Slim\Http\Response as BaseResponse;
use Whoops\Handler\PrettyPageHandler as WhoopsPrettyPageHandler;
use Zeuxisoo\Whoops\Provider\Slim\WhoopsMiddleware;

/**
 * @method RouteGroup group(string $pattern, callable $callable)
 */
class App extends \Slim\App {
	public function __construct($container = []){
		$container['settings'] = $this->getInitialSettings(isset($container['settings']) ? $container['settings'] : []);

		$this->setupWhoops($container);
		$this->setupRouter($container);
		$this->setupResponse($container);

		parent::__construct($container);
	}

	private function getInitialSettings(array $settings){
		if(defined('IS_DEBUG')){
			$settings['debug'] = IS_DEBUG;
		}

		return $settings;
	}

	private function setupWhoops(&$container){
		/** @var \Whoops\Run $whoops */
		$whoops = isset($container['whoops']) ? $container['whoops'] : null;

		$handlers = [];
		if(isset($whoops)){
			// Import handlers
			$handlers	= $whoops->getHandlers();
			if(isset($handlers[0]) && $handlers[0] instanceof WhoopsPrettyPageHandler){
				// Remove original PrettyPageHandler
				/** @var WhoopsPrettyPageHandler $legacyHandler */
				$legacyHandler = array_shift($handlers);
			}

			// Import editor links
			if(isset($legacyHandler)){
				$container['settings']['whoops.editor'] = [$legacyHandler, 'getEditorHref'];
			}
		}

		$this->add(new WhoopsMiddleware($this, $handlers));
	}

	private function setupRouter(&$container){
		$container['router'] = function($container){
			$routerCacheFile = false;
			if(isset($container->get('settings')['routerCacheFile'])){
				$routerCacheFile = $container->get('settings')['routerCacheFile'];
			}

			$router = (new Router)->setCacheFile($routerCacheFile);
			if(method_exists($router, 'setContainer')){
				$router->setContainer($container);
			}

			return $router;
		};
	}

	private function setupResponse(&$container){
		$container['response'] = function($container){
			$headers  = new Headers(['Content-Type' => 'text/html; charset=UTF-8']);
			$response = new Http\Response(200, $headers);
			$response->setIsDebug($container['settings']['debug']);

			return $response->withProtocolVersion($container->get('settings')['httpVersion']);
		};
	}

	/**
	 * @param string $message
	 * @param BaseRequest|null $request
	 * @param BaseResponse|null $response
	 * @throws SlimNotFoundException
	 */
	public function notFound($message, BaseRequest $request = null, BaseResponse $response = null){
		$container = $this->getContainer();
		if(!isset($request)){
			$request = $container['request'];
		}
		if(!isset($response)){
			$response = $container['response'];
		}

		$e = new SlimNotFoundException($request, $response);
		$property = new \ReflectionProperty($e, 'message');
		$property->setAccessible(true);
		$property->setValue($e, $message);
		throw $e;
	}
}
