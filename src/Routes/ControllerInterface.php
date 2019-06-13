<?php
declare(strict_types=1);

namespace AdamAveray\SlimExtensions\Routes;

use AdamAveray\SlimExtensions\Http\Response;
use Slim\Http\Request;

interface ControllerInterface
{
    public function setRequestResponse(Request $request, Response $response): void;
}
