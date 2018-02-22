<?php
namespace AdamAveray\SlimExtensions;

use Psr\Container\ContainerExceptionInterface;
use Slim\Exception\SlimException;
use Slim\Exception\NotFoundException as SlimNotFoundException;
use Slim\Interfaces\RouteInterface;
use Slim\Http\Headers;
use Slim\Http\Request as BaseRequest;
use Slim\Http\Response as BaseResponse;
use Whoops\Handler\PrettyPageHandler as WhoopsPrettyPageHandler;
use Zeuxisoo\Whoops\Provider\Slim\WhoopsErrorHandler;
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
		if($settings['debug']){
			$settings['displayErrorDetails'] = true;
		}

		if(!isset($settings['patternValidator'])){
			$settings['patternValidator'] = function($pattern) {
				// Must end with trailing slash or contain extension (not both)
				return (substr($pattern, -1) === '/') xor (strpos($pattern, '.') !== false);
			};
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

				// Import settings
				$container['settings']['whoops.editor']		= [$legacyHandler, 'getEditorHref'];
				$container['settings']['whoops.pageTitle']	= $legacyHandler->getPageTitle();
			}

			// Setup error handlers
			$handler = function($container) {
				return new WhoopsErrorHandler($container->get('whoops'));
			};
			if (!isset($container['errorHandler'])) {
				$container['errorHandler'] = $handler;
			}
			if (!isset($container['phpErrorHandler'])) {
				$container['phpErrorHandler'] = $handler;
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
	 * @param string[] $methods Numeric array of HTTP method names
	 * @param string|string[] $patterns One or more route URI patterns
	 * @param callable|string $callable The route callback routine
	 * @return RouteInterface|RouteInterface[]
	 * @throws \InvalidArgumentException A pattern does not pass the defined pattern validator
	 */
	public function map(array $methods, $patterns, $callable){
		try {
			$validator = $this->getContainer()->get('patternValidator');
		} catch(ContainerExceptionInterface $e){
			$validator = null;
		}

		$routes	= [];
		foreach((array)$patterns as $i => $pattern){
			if($validator !== null && !$validator($pattern)){
				throw new \InvalidArgumentException('Pattern is invalid ('.$pattern.')');
			}

			$routes[$i] = parent::map($methods, $pattern, $callable);
		}

		if(is_array($patterns)){
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
	public function notFound($message, BaseRequest $request = null, BaseResponse $response = null){
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
	public function error($messageOrException, $status = 500, BaseRequest $request = null, BaseResponse $response = null){
		$this->fallbackObjects($request, $response);

		// Set status
		$response = $response->withStatus($status);

		$message	= null;
		$previous	= null;
		if($messageOrException instanceof \Exception){
			$previous	= $messageOrException;
		} else {
			$message	= $messageOrException;
		}
		/** @var SlimException $e */
		$e = $this->injectException(new SlimException($request, $response), $message, $previous);
		throw $e;
	}

	/**
	 * Overwrite the given values in an existing Exception object
	 *
	 * @param \Exception $e             The exception to overwrite values in
	 * @param string|null $message      The `->message` value to overwrite, or null to preserve existing
	 * @param \Exception|null $previous The `->previous` value to overwrite, or null to preserve existing
	 * @return \Exception The Exception provided as $e
	 */
	private function injectException(\Exception $e, $message = null, \Exception $previous = null){
		$mirror = new \ReflectionClass($e);

		$values	= [
			'message'	=> $message,
			'previous'	=> $previous,
		];
		foreach($values as $propertyName => $value){
			if($value === null){
				continue;
			}

			$property	= $mirror->getProperty($propertyName);
			$property->setAccessible(true);
			$property->setValue($e, $value);
		}

		return $e;
	}

	/**
	 * @param BaseRequest|null &$request	Will be set by reference to a generic Request instance if null
	 * @param BaseResponse|null &$response	Will be set by reference to a generic Response instance if null
	 */
	private function fallbackObjects(BaseRequest &$request = null, BaseResponse &$response = null){
		$container = $this->getContainer();
		if($request === null){
			$request = $container['request'];
		}
		if($response === null){
			$response = $container['response'];
		}
	}
}
