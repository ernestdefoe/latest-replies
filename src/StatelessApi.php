<?php

namespace ErnestDefoe\LatestReplies;

use Flarum\Api\Client;
use Flarum\Http\RequestUtil;
use Flarum\User\Guest;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use Psr\Http\Message\ResponseInterface;

/**
 * Flarum's API, asked as a guest who is nobody in particular.
 *
 * 🚨 The shared guest answer must be the most-redacted view, never one guest's.
 * Client::send() builds its request from PHP's globals (this visitor's
 * User-Agent and cookies) and withParentRequest() adds their session and IP,
 * so a guest with a Spoiler Shield reveal in their session, a crawler that
 * Velvet Rope lets read, or a metered guest would put their view in the cache
 * every guest is served. This request is built from nothing: no headers, no
 * cookies, no session, no IP, a plain Guest. It still runs the same API
 * middleware, so every gate redacts it.
 */
class StatelessApi extends Client
{
    public static function from(Client $client): self
    {
        return new self($client->pipe);
    }

    /** @param array<string, mixed> $query */
    public function asNobody(string $path, array $query): ResponseInterface
    {
        $request = (new ServerRequest([], [], new Uri($path), 'GET', 'php://temp'))->withQueryParams($query);
        $request = RequestUtil::withActor(RequestUtil::withInternal($request), new Guest());

        return $this->pipe->errorHandling(true)->handle($request);
    }
}
