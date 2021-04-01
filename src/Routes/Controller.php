<?php
declare(strict_types=1);

namespace AdamAveray\SlimExtensions\Routes;

use AdamAveray\SlimExtensions\Http\Response;
use Slim\Http\Request;

abstract class Controller implements ControllerInterface
{
    /** @var Request|null $request */
    protected ?Request $request = null;
    /** @var Response|null $response */
    protected ?Response $response = null;

    public function setRequestResponse(Request $request, Response $response): void
    {
        $this->request = $request;
        $this->response = $response;
    }
}
