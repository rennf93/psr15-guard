# psr15-guard

`psr15-guard` is the official PSR-15 adapter for
[guard-core-php](https://github.com/rennf93/guard-core-php), the PHP port of the
guard-core security engine. It wraps any PSR-15 stack (Slim 4, Mezzio, framework
PSR-15 bridges) with the full engine pipeline: penetration detection, rate
limiting, IP banning, and verdict responses.

All security logic lives in the engine; this package is a thin shim that
translates a PSR-7 `ServerRequestInterface` into a `GuardRequest`, runs the
engine, and translates the block verdict back to a PSR-7 response when one
arrives.

## Installation

```bash
composer require rennf93/psr15-guard
```

Requires PHP 8.2 or later. Until `rennf93/guard-core-php` has a Packagist
distribution, point composer at its repository and allow dev stability:

```json
{
    "minimum-stability": "dev",
    "prefer-stable": true,
    "repositories": [
        { "type": "vcs", "url": "https://github.com/rennf93/guard-core-php" }
    ]
}
```

## Quick start

```php
use Nyholm\Psr7\Factory\Psr17Factory;
use RenzoFranceschini\GuardCore\Config\SecurityConfig;
use RenzoFranceschini\GuardCore\Engine\GuardEngine;
use RenzoFranceschini\GuardCorePsr15\GuardMiddleware;

$factory = new Psr17Factory();
$engine = new GuardEngine(new SecurityConfig(
    enableRedis: true,
    redisPrefix: 'guard_core:',
    rateLimit: 100,
    rateLimitWindow: 60,
));

$guard = new GuardMiddleware($engine, $factory, $factory);

$app->add($guard); // Slim 4, Mezzio, or any PSR-15 stack
```

Blocked requests get the engine's `GuardResponse` translated exactly (status,
body, headers). Passing requests continue down the stack untouched.

## What the middleware handles

- Client identity: `REMOTE_ADDR` from the PSR-7 server params, with trusted-proxy
  resolution performed by the engine (`SecurityConfig` `trustedProxies`)
- Headers: joined into the engine's case-insensitive `HeaderBag`
- Body: the first 256 KiB are shown to the engine (`PsrGuardRequest::MAX_BODY_BYTES`)
- Fail-closed: engine throws become `500` with a fixed, non-leaky message
- Statelessness: the middleware holds no mutable state; PHP shared-nothing
  applies (per request under FPM, per worker under Swoole/Octane/RoadRunner)

See [Usage](usage.md) for the full adapter surface and
[Configuration](configuration.md) for engine tuning. Runnable apps live in the
[examples](https://github.com/rennf93/psr15-guard/tree/master/examples)
directory.
