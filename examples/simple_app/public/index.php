<?php

declare(strict_types=1);

use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RenzoFranceschini\GuardCore\Config\SecurityConfig;
use RenzoFranceschini\GuardCore\Engine\GuardEngine;
use RenzoFranceschini\GuardCorePsr15\GuardMiddleware;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Minimal guarded PSR-15 app. The router is a plain RequestHandler; every
 * request passes through GuardMiddleware (the psr15-guard adapter for
 * guard-core-php) before the handler sees it.
 */
final readonly class App implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return match ($request->getUri()->getPath()) {
            '/', '' => new HtmlResponse('ok'),
            '/health' => new HtmlResponse('healthy'),
            '/rate/strict' => new HtmlResponse('strict ok'),
            '/search' => new HtmlResponse(
                'search: ' . htmlspecialchars((string) ($request->getQueryParams()['q'] ?? ''), ENT_QUOTES)
            ),
            default => new HtmlResponse('not found', 404),
        };
    }
}

/**
 * Engine configuration. SecurityConfig is a constructor-only surface; the
 * connection details for Redis come from the environment (REDIS_HOST and
 * REDIS_PORT), everything else is passed here explicitly.
 */
$config = new SecurityConfig(
    enableRedis: true,
    redisPrefix: getenv('REDIS_PREFIX') ?: 'guard_core_psr15:',
    redisFailOpen: true,
    // The PHP port has no ExcludedDetectionHeaders surface yet, so the ssrf
    // category flags benign Host headers (e.g. "localhost:8080") on every
    // request. The demo disables that one category; SSRF hygiene belongs to
    // the proxy tier. XSS, SQLi, and the rest stay fully on.
    enabledDetectionCategories: array_values(
        array_diff(SecurityConfig::DETECTION_CATEGORIES, ['ssrf'])
    ),
    enableRateLimiting: true,
    rateLimit: 100,
    rateLimitWindow: 60,
    endpointRateLimits: [
        '/rate/strict' => ['limit' => 1, 'window' => 10],
    ],
    enableIpBanning: true,
    autoBanThreshold: 5,
    autoBanDuration: 300,
    // The engine's strike counter for auto-ban lives in memory per engine
    // instance, so under PHP shared-nothing a repeated-violation auto-ban
    // never accumulates across requests. The demo pins the xss threat ban at
    // threshold 1 instead: the first XSS payload trips the ban, and the ban
    // itself is Redis-backed and deterministic across requests.
    threatBanConfig: ['xss' => ['threshold' => 1, 'duration' => 300]],
    customErrorResponses: [403 => 'Blocked by psr15-guard'],
    excludePaths: ['/health'],
);

$guard = new GuardMiddleware(
    new GuardEngine($config),
    new ResponseFactory(),
    new StreamFactory(),
);

/**
 * Minimal PSR-7 emitter (status line, headers, body). A real deployment would
 * use its SAPI or server's emitter instead.
 */
$emit = static function (ResponseInterface $response): never {
    http_response_code($response->getStatusCode());
    foreach ($response->getHeaders() as $name => $values) {
        header($name . ': ' . implode(', ', $values));
    }
    $body = $response->getBody();
    $body->rewind();
    echo $body->getContents();
    exit(0);
};

$emit($guard->process(ServerRequestFactory::fromGlobals(), new App()));
