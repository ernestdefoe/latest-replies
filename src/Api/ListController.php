<?php

namespace ErnestDefoe\LatestReplies\Api;

use ErnestDefoe\LatestReplies\Feed;
use ErnestDefoe\LatestReplies\Settings;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/latest-replies?tag={slug}&limit={n}
 *
 * The rows for whoever asks. A viewer who may not see the forum gets an empty
 * list (the API Feed asks refuses them).
 */
class ListController implements RequestHandlerInterface
{
    public function __construct(private readonly Feed $feed, private readonly Settings $settings)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $tag = is_string($query['tag'] ?? null) ? mb_substr(trim($query['tag']), 0, 100) : null;
        $limit = isset($query['limit']) && is_numeric($query['limit']) ? (int) $query['limit'] : $this->settings->count();

        $rows = $this->feed->rows($request, RequestUtil::getActor($request), $tag, $limit);

        return new JsonResponse(['rows' => $rows], 200, ['Cache-Control' => 'private, no-store']);
    }
}
