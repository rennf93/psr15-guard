---
name: psr15-guard
description: Use when an agent works in rennf93/psr15-guard or touches its PSR-15 adapter surface: PSR-15 middleware adapter that wires PSR-7/PSR-15 types to the guard-core-php engine and contains no security logic of its own; covers middleware construction and configuration (GuardEngine, PSR-17 factories, redisFailOpen), PSR-7 request adaptation (PsrGuardRequest, bounded 256 KiB body read, REMOTE_ADDR client host), block-response translation (ResponseTranslator, fail-closed 500), composer scripts (composer lint, composer test), and the plain-PHP test runner bin/test_psr15.php with its REDIS_HOST integration mode.
---

# psr15-guard

## Quick Reference

- Package `rennf93/psr15-guard`; PSR-4 namespace `RenzoFranceschini\GuardCorePsr15\` mapped to `src/`.
- Three final classes: `GuardMiddleware` (PSR-15 `MiddlewareInterface`), `PsrGuardRequest` (implements the core `GuardRequest` contract), `ResponseTranslator` (`GuardResponse` to PSR-7 `ResponseInterface`).
- Requires PHP `^8.2` and `rennf93/guard-core-php ^0.1.0` (locked v0.1.0).
- Commands: `composer lint` (php -l sweep over `src` and `bin`), `composer test` (`php bin/test_psr15.php`). CI also runs `composer install --no-interaction --no-progress`, `composer update rennf93/guard-core-php --no-interaction`, the same php -l sweep, `REDIS_HOST=127.0.0.1 php bin/test_psr15.php`, and `composer audit`.
- The adapter holds no security logic. Every verdict comes from `GuardEngine::execute()`.

## Installation

```bash
composer require rennf93/psr15-guard
```

Until `rennf93/guard-core-php` has a Packagist release, point Composer at its repository (README setup):

```json
{
    "minimum-stability": "dev",
    "prefer-stable": true,
    "repositories": [
        { "type": "vcs", "url": "https://github.com/rennf93/guard-core-php" }
    ]
}
```

The dependency constraint is `rennf93/guard-core-php: ^0.1.0`. This package's own composer.json resolves the core locally through a `../guard-core-php` path repository (marked `"canonical": false`) with the VCS URL above as fallback.

## Setup

```php
use Nyholm\Psr7\Factory\Psr17Factory;
use RenzoFranceschini\GuardCore\Config\SecurityConfig;
use RenzoFranceschini\GuardCore\Engine\GuardEngine;
use RenzoFranceschini\GuardCorePsr15\GuardMiddleware;

$config = new SecurityConfig(
    enableRedis: false,
    blacklist: ['192.0.2.0/24'],
    rateLimit: 100,
    rateLimitWindow: 60,
    enableRateLimiting: true,
);

$factory = new Psr17Factory();
$engine = new GuardEngine($config);
$guard = new GuardMiddleware($engine, $factory, $factory);

$app->add($guard);
```

- The constructor's third and fourth arguments are the PSR-17 `ResponseFactoryInterface` and `StreamFactoryInterface`. Any PSR-17 implementation works; the test runner uses nyholm/psr7.
- Lifecycle: construct `GuardEngine` (and therefore `GuardMiddleware`) per request in classic FPM, or per worker under Swoole/Octane/RoadRunner. The middleware holds no mutable state of its own.
- Redis: set `enableRedis: true` and point `REDIS_HOST`/`REDIS_PORT` at your instance for distributed rate limits, IP bans, and cloud-range caches. With `redisFailOpen: true` the middleware still constructs and serves requests when Redis is unreachable; with `redisFailOpen: false` construction fails closed.

## GuardMiddleware

- `final class GuardMiddleware implements MiddlewareInterface`.
- `__construct(GuardEngine $engine, ResponseFactoryInterface $responseFactory, StreamFactoryInterface $streamFactory)`. It builds a `ResponseTranslator` internally and calls `$engine->initialize()`. A `GuardRedisException` from initialization is swallowed only when `$engine->config()->redisFailOpen` is true; otherwise it rethrows and construction fails closed.
- `process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface`:
  - Wraps the request in `PsrGuardRequest` and calls `$engine->execute()`.
  - Engine throws `Throwable`: returns `$this->translator->translate($this->engine->failClosedResponse())`. Default is `500 Security check failed`, honorably overridden by the engine's `customErrorResponses`. The handler never runs.
  - Non-null `GuardResponse` verdict: translated to a PSR-7 response with the exact engine status, body, and headers. The handler never runs.
  - `null` verdict: `$handler->handle($request)`, passing the ORIGINAL PSR-7 request untouched.

## PsrGuardRequest

- `final class PsrGuardRequest implements RenzoFranceschini\GuardCore\Request\GuardRequest`; wraps a `ServerRequestInterface`.
- `MAX_BODY_BYTES = 262144` (256 KiB, public); `READ_CHUNK_BYTES = 65536` (private). `body()` reads at most the cap in 64 KiB chunks, caches the prefix, and replays the cache on later calls. `bodyWasTruncated()` reports whether bytes remain beyond the cap (it probes the stream with an extra one-byte read). The boundary is exact: a body of exactly 262144 bytes is read fully and not flagged truncated; one byte over is capped and flagged.
- URL adaptation: `urlPath()`, `urlScheme()`, `urlFull()` (scheme://host plus path plus query when non-empty), and `urlReplaceScheme()`, which is pure and does not mutate the request.
- `method()` upper-cases the PSR-7 method. `clientHost()` returns the `REMOTE_ADDR` server param, or `null` when it is missing or an empty string.
- `headers()` builds a `HeaderBag` from `getHeaderLine()` per header, so multi-value PSR-7 headers arrive joined as `a, b` and lookup is case-insensitive. `queryParams()` passes PSR-7 query params through unchanged.
- `state()` returns a fresh per-instance `RequestState` (each translated request has its own).

## ResponseTranslator

- `final class ResponseTranslator`; `__construct(ResponseFactoryInterface $responseFactory, StreamFactoryInterface $streamFactory)`.
- `translate(GuardResponse $response): ResponseInterface` creates a response with the guard status code, attaches a stream of the guard body (a null body becomes the empty string), then applies `withHeader()` for every header in the guard response's `HeaderBag`. It is an exact copy and adds nothing: no security headers, no CORS.

## Footguns

- No php and no composer on the dev machine: verify behavior by reading source and CI YAML; CI is the executor.
- Bounded body read: payloads beyond 256 KiB, or signatures split across that boundary, are not detected and reach the downstream handler on pass. `MAX_BODY_BYTES` matches the engine's full-scan window; do not change it in isolation.
- Fail-closed is intentional: an engine exception yields `500 Security check failed`, never a pass-through. `customErrorResponses` lives in the engine's `SecurityConfig`, not in the adapter.
- With `redisFailOpen: false` and Redis down, middleware CONSTRUCTION throws `GuardRedisException`, so the failure happens before any request is handled.
- In the test runner, `REDIS_HOST=0` forces integration mode off; otherwise it probes `REDIS_HOST` (default 127.0.0.1) and `REDIS_PORT` (default 6379) and prints SKIP if unreachable. CI sets `REDIS_HOST=127.0.0.1` with a `redis:7-alpine` service.
- `.github/workflows/ci.yml` still has an inline comment about a "dev-branch alias in composer.json"; composer.json now requires `^0.1.0` with no alias (commit 55c17a9). Trust composer.json. CI checks out the core at branch `guard-core-port-php` into `../guard-core-php` and floats it with `composer update rennf93/guard-core-php` within `^0.1.0`.
- The adapter adds no security headers or CORS; blocked responses are exact engine translations (for example `403 Forbidden`, `429 Too many requests` with `Retry-After`).
- `main` is protected and `v0.1.0` is the only shipped tag: branch, PR, no direct pushes to `main`, no new tags or releases.

## Related Projects

- `rennf93/guard-core-php`: https://github.com/rennf93/guard-core-php. The engine. `SecurityConfig`, `GuardEngine`, `GuardRequest`/`GuardResponse`, `HeaderBag`, `RequestState`, `RedisHandler`, and `GuardRedisException` live there, and every verdict originates there.
- `rennf93/psr15-guard`: https://github.com/rennf93/psr15-guard. This repository, the PSR-15 adapter layer of the guard-core ecosystem.
