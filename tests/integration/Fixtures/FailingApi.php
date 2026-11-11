<?php

namespace ErnestDefoe\LatestReplies\Tests\integration\Fixtures;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Makes the API's discussion and post lists fail while $failing is set. */
class FailingApi implements MiddlewareInterface
{
    public static bool $failing = false;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (self::$failing && (str_ends_with($path, '/discussions') || str_ends_with($path, '/posts'))) {
            return new JsonResponse(['errors' => [['status' => '500']]], 500);
        }

        return $handler->handle($request);
    }
}
