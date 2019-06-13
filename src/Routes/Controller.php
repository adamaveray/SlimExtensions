<?php
declare(strict_types=1);

namespace AdamAveray\SlimExtensions\Routes;

use AdamAveray\SlimExtensions\Http\Response;
use Slim\Http\Request;

abstract class Controller implements ControllerInterface
{
    /** @var Request $request */
    protected $request;
    /** @var Response $response */
    protected $response;

    public function setRequestResponse(Request $request, Response $response): void
    {
        $this->request = $request;
        $this->response = $response;
    }
}
