Slim Extensions
===============

A handful of custom extensions to [Slim](https://www.slimframework.com), not intended for general-purpose use but parts may be useful to others.


Features
--------

- Sets up [Whoops](https://filp.github.io/whoops/) automatically
- Supports adding converters to route groups (`->convert($argName, callable $converter)`)
- Adds convenient `notFound()` method to base App (`$app->notFound($debugMessage)`)
- Output standard API responses (`$response->api($data, array $extra = null, $status = null)`)
- Output CSVs easily (`$response->withCSV(array $headers, array $data, $status = null)`)
- Automatically formats JSON responses with `\JSON_PRETTY_PRINT` when debug enabled
- Route dependency injection


Caveats
-------

- Automatically references the constant `IS_DEBUG` if defined for enabling debug mode


Usage Notes
-----------

- It is recommended to subclass the included `Container` class, overriding the `registerDefaultServices()` method to define common services, then adding them to the class-level docblock as `@property-read type $serviceName` for IDE autocompletion.


### Dependency Injection

The arguments to a route function (e.g. `->get('/', function(...) {})`) or middleware (e.g. `->add(function(...) {})`) will be automatically injected from the following sources in order:

1. `$request` and `$response` will receive the current Request and Response instances

2. In middleware, `$next` will receive the next-callable

3. If an attribute on the Request object matches the argument name it will be used

4. If an argument on the Route object matches the argument name it will be used

5. If an item in the Container matches the argument name it will be used

6. If the argument has a class typehint a new instance of the class will be instantiated (which will in turn have its constructor arguments injected using these same rules)

If no dependency is matched, the argument default value will be used if available, otherwise an exception will be thrown.

#### Class Route/Middleware Handlers

If the route callable is a reference to a class method (e.g. `->get('/', [MyClass::class, 'someMethod'])`) the class will be instantiated (constructor arguments will be dependency injected using the above rules). In the case of multiple reference to the class (e.g. in middleware and as a route handler) the first instance will be reused. If the method is static no instantiation will take place.

If the class implements the ControllerInterface interface (or subclasses the included Controller class), route handlers must be prefixed with `endpoint` and middleware handlers prefixed with `middleware`, which should then be omitted from the reference (e.g. `->get('/', [MyController::class, 'index'])` for `function endpointIndex()` and `->add([MyController::class, 'global']` for `function middlewareGlobal()`). 
