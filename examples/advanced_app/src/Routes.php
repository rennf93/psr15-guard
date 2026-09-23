<?php

declare(strict_types=1);

namespace App;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RenzoFranceschini\GuardCore\Engine\GuardEngine;

/**
 * The guarded router. Admin routes drive the engine's ban manager directly;
 * they are gated by the custom_request admin gate in Config.
 */
final readonly class Routes implements RequestHandlerInterface
{
    public function __construct(private GuardEngine $engine)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return match ($request->getUri()->getPath()) {
            '/', '' => new HtmlResponse('ok'),
            '/health' => new HtmlResponse('healthy'),
            '/search' => $this->search($request),
            '/rate/burst' => new HtmlResponse('burst ok'),
            '/admin/ban' => $this->ban($request),
            '/admin/unban' => $this->unban($request),
            '/admin/check' => $this->check($request),
            default => new HtmlResponse('not found', 404),
        };
    }

    private function search(ServerRequestInterface $request): ResponseInterface
    {
        $q = htmlspecialchars((string) ($request->getQueryParams()['q'] ?? ''), ENT_QUOTES);

        return new HtmlResponse('search: ' . $q);
    }

    private function ban(ServerRequestInterface $request): ResponseInterface
    {
        $ip = (string) ($request->getQueryParams()['ip'] ?? '');
        if ($ip === '') {
            return new JsonResponse(['error' => 'ip query parameter is required'], 400);
        }
        $duration = (int) ($request->getQueryParams()['duration'] ?? 600);
        $banned = $this->engine->banManager()->ban($ip, $duration, 'manual');

        return new JsonResponse(['ip' => $ip, 'duration' => $duration, 'banned' => $banned]);
    }

    private function unban(ServerRequestInterface $request): ResponseInterface
    {
        $ip = (string) ($request->getQueryParams()['ip'] ?? '');
        if ($ip === '') {
            return new JsonResponse(['error' => 'ip query parameter is required'], 400);
        }
        $this->engine->banManager()->unban($ip);

        return new JsonResponse(['ip' => $ip, 'banned' => false]);
    }

    private function check(ServerRequestInterface $request): ResponseInterface
    {
        $ip = (string) ($request->getQueryParams()['ip'] ?? '');
        if ($ip === '') {
            return new JsonResponse(['error' => 'ip query parameter is required'], 400);
        }

        return new JsonResponse(['ip' => $ip, 'banned' => $this->engine->banManager()->isIpBanned($ip)]);
    }
}
