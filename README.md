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


Caveates
--------

- Automatically references the constant `IS_DEBUG` if defined for enabling debug mode
