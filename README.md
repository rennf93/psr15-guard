# psr15-guard

PSR-15 middleware adapter for [guard-core-php](https://github.com/rennf93/guard-core-php): translates any PSR-7 `ServerRequestInterface` to the guard-core engine and translates block verdicts back to PSR-7 responses. Works with Slim 4, Mezzio, Symfony PSR-15 bridges, or any PSR-7/PSR-15 stack.

## Install

```bash
composer require rennf93/psr15-guard
```

Until `rennf93/guard-core-php` has a Packagist release, point composer at its repository and allow dev stability:

```json
{
    "minimum-stability": "dev",
    "prefer-stable": true,
    "repositories": [
        { "type": "vcs", "url": "https://github.com/rennf93/guard-core-php" }
    ]
}
```

## Usage

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

Blocked requests get the engine's `BlockResponse` translated exactly (status, body, headers), for example `403 Forbidden` for a blacklisted IP, `429 Too many requests` with `Retry-After` for a rate limit hit. Passing requests continue down the stack untouched.

## Lifecycle

PHP shared-nothing applies: construct `GuardEngine` (and therefore `GuardMiddleware`) per request in classic FPM, or per worker under Swoole/Octane/RoadRunner. The middleware holds no mutable state of its own. In-memory fallbacks are per-request safety nets; distributed rate limits, IP bans, and cloud-range caches require Redis (set `enableRedis: true` and point `REDIS_HOST`/`REDIS_PORT` at your instance).

## Behavior notes

- Fail-closed: if the engine throws, the middleware returns the engine's fail-closed response (`500 Security check failed`, honorably overridden by `customErrorResponses`) instead of letting the request through.
- Bounded body read: the request body is scanned as a prefix of at most 256 KiB (`PsrGuardRequest::MAX_BODY_BYTES`, matching the engine's full-scan window). Payloads beyond the prefix, or signatures split across its boundary, are not detected.
- With `redisFailOpen: true` the middleware constructs and serves requests even when Redis is unreachable; with `redisFailOpen: false` construction fails closed.
- No security headers or CORS are added by this adapter.

## Testing

```bash
composer lint
composer test
```

`composer test` runs the plain-PHP suite in `bin/test_psr15.php` (unit coverage always; set `REDIS_HOST` to a reachable Redis to include the shared-state integration cases).

## License

MIT
