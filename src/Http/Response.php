<?php
declare(strict_types=1);

namespace AdamAveray\SlimExtensions\Http;

use Psr\Container\ContainerInterface;
use Slim\Http\StatusCode;

class Response extends \Slim\Http\Response
{
    protected $isDebug = false;
    protected $container;

    /**
     * @param bool $isDebug
     * @return $this
     */
    public function setIsDebug(bool $isDebug): self
    {
        $this->isDebug = $isDebug;
        return $this;
    }

    /**
     * @param array|ContainerInterface $container
     * @return Response
     */
    public function setContainer($container): self
    {
        $this->container = $container;
        return $this;
    }

    /** @deprecated */
    public function api($data, ?array $extra = null, ?int $status = null): self
    {
        $json = array_merge((array) $extra, [
            'status' => $status ?? $this->getStatusCode(),
        ]);

        if ($data !== null) {
            $json['data'] = $data;
        }

        return $this->withJson($json, $status);
    }

    /**
     * @param array $headers
     * @param array $data
     * @param int|null $status
     * @return static
     */
    public function withCsv(array $headers, array $data, int $status = null): self
    {
        $stream = fopen('php://temp', 'rb+');
        $response = $this->withBody(new Body($stream));
        fputcsv($stream, $headers);
        foreach ($data as $row) {
            fputcsv($stream, $row);
        }

        $response = $response->withHeader('Content-Type', 'text/csv;charset=utf-8');
        if ($status !== null) {
            $response = $response->withStatus($status);
        }
        return $response;
    }

    /**
     * {@inheritdoc}
     * @return static
     */
    public function withJson($data, $status = null, $encodingOptions = 0): self
    {
        if ($this->isDebug) {
            $encodingOptions = \JSON_PRETTY_PRINT;
        }

        return parent::withJson($data, $status, $encodingOptions);
    }

    /**
     * Return an instance with the specified string as the body
     *
     * @param string $string
     * @return static
     */
    public function withBodyString(string $string): self
    {
        return $this->withBody(new Body($string));
    }

    /**
     * @param mixed $data      The API data to send
     * @param int|null $status A different HTTP status code to use
     * @return static
     */
    public function withApi($data, ?int $status = null): self
    {
        return $this->api($data, [], $status);
    }

    /**
     * @param string $error                    The error message
     * @param int $status                      The HTTP error status code to use
     * @param array|null $extraData            Additional data to include in the response body
     * @param string $debugMessage             A message to be displayed in debug environments
     * @param array|\Throwable|null $debugData Additional debug data
     * @return static
     */
    public function withApiError(
        string $error,
        int $status = StatusCode::HTTP_INTERNAL_SERVER_ERROR,
        ?array $extraData = null,
        string $debugMessage = '',
        $debugData = null
    ): self {
        $extra = ['error' => $error] + ($extraData ?? []) + $this->formatDebugData($debugMessage, $debugData);
        return $this->api(null, $extra, $status);
    }

    /**
     * @param string $debugMessage             A message to be displayed in debug environments
     * @param array|\Throwable|null $debugData Additional debug data
     * @return static
     */
    public function withNotFound(string $debugMessage, $debugData = null): self
    {
        /** @var Response $response */
        $response = $this->withStatus(StatusCode::HTTP_NOT_FOUND);

        if ($this->getHeaderLine('Content-Type') === 'application/json') {
            // JSON response
            return $response->withJson(
                [
                    'error' => $response->getStatusCode(),
                    'message' => $response->getReasonPhrase(),
                ] + $this->formatDebugData($debugMessage, $debugData)
            );
        }

        // Assume HTML response
        if ($this->isDebug) {
            return $this->getNotFoundDebugResponse($response, $debugMessage, $debugData);
        }

        return $this->getNotFoundResponse($response);
    }

    protected function getNotFoundDebugResponse(Response $response, string $debugMessage, $debugData = null): Response
    {
        // Output basic debug page
        $body = '<p>' . htmlspecialchars($debugMessage) . '</p>';
        if (\function_exists('dump')) {
            ob_start();
            dump($debugData, debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
            $body .= ob_get_clean();
        } else {
            $body = print_r($debugData, true);
        }
        return $response->withBodyString($body);
    }

    /**
     * (For subclassing)
     *
     * @param Response $response
     * @return static
     */
    protected function getNotFoundResponse(Response $response): Response
    {
        return $response->withBodyString($response->getReasonPhrase());
    }

    private function formatDebugData(string $message, $data): array
    {
        if (!$this->isDebug) {
            // Non-debug
            return [];
        }

        if ($message === '' && ($data === null || $data === [])) {
            // No debug data
            return [];
        }

        // Format exceptions
        if ($data instanceof \Throwable) {
            $data = ['exception' => $data];
        }
        if (($data['exception'] ?? null) instanceof \Throwable) {
            /** @var \Throwable $exception */
            $exception = $data['exception'];
            $data['exception'] = [
                'type' => \get_class($exception),
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTrace(),
            ];
        }

        $backtrace = debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS);
        // Remove Response function from trace
        array_shift($backtrace);

        return [
            '_debug' => [
                'message' => $message,
                'data' => $data,
                'stackTrace' => $backtrace,
            ],
        ];
    }
}
