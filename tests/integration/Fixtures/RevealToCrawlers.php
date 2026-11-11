<?php

namespace ErnestDefoe\LatestReplies\Tests\integration\Fixtures;

use Laminas\Diactoros\StreamFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A stand-in for a gate like Velvet Rope or Spoiler Shield: API answers say "REDACTED" instead
 * of "CLASSIFIED", except to a visitor it trusts (a crawler's User-Agent, its IP address, or a
 * reveal kept in the session). The shared guest answer must never be that visitor's view.
 */
class RevealToCrawlers implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $trusted = str_contains($request->getHeaderLine('User-Agent'), 'Googlebot')
            || $request->getAttribute('ipAddress') === '6.6.6.6'
            || ($request->getAttribute('session')?->get('revealed') ?? false);

        if ($trusted) {
            return $response;
        }

        $body = (string) $response->getBody();

        return $response->withBody((new StreamFactory())->createStream(str_replace('CLASSIFIED', 'REDACTED', $body)))->withoutHeader('Content-Length');
    }
}
