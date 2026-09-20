# AGENTS.md
Guidance for AI agents (including Claude Code) working in this repository.

## Project Overview

rennf93/psr15-guard (https://github.com/rennf93/psr15-guard) is a PSR-15 middleware adapter for guard-core-php. It translates any PSR-7 `ServerRequestInterface` into the guard-core engine and translates the engine's block verdicts back to PSR-7 responses. It works with Slim 4, Mezzio, Symfony PSR-15 bridges, or any PSR-7/PSR-15 stack.

- Composer package `rennf93/psr15-guard`, type `library`, license MIT.
- This repository contains NO security logic. Detection, rate limiting, bans, and verdicts all live in guard-core-php.
- PHP `^8.2`. Autoload is PSR-4: `RenzoFranceschini\GuardCorePsr15\` maps to `src/`.
- Shipped tag: `v0.1.0` (points at commit 55c17a9, the current tip of `main`). There are no other tags or releases.
- `main` is protected: never push to it, never merge into it, never create tags or releases.

## Ecosystem Position

- `rennf93/guard-core-php` (https://github.com/rennf93/guard-core-php) is the engine. It owns `SecurityConfig`, `GuardEngine`, the `GuardRequest`/`GuardResponse` contracts, `HeaderBag`, `RequestState`, `RedisHandler`, and `GuardRedisException`. Every check, verdict, and block response body originates there.
- This package is the PSR-7/PSR-15 adapter for that engine: `GuardMiddleware` implements `MiddlewareInterface`, `PsrGuardRequest` implements `GuardRequest`, `ResponseTranslator` converts `GuardResponse` objects to `ResponseInterface`.
- Composer constraint: `rennf93/guard-core-php: ^0.1.0` (composer.json `require`). `composer.lock` pins `v0.1.0` (source reference 89e8bd596257e607c427fc0cc26b80731cf80279, fetched from the GitHub VCS repository).
- Repository configuration in composer.json, in order:
  1. Path repository `../guard-core-php`, marked `"canonical": false` (resolves when a sibling checkout of the core exists; non-canonical, so other sources win on conflict).
  2. VCS fallback `https://github.com/rennf93/guard-core-php.git` (how fresh checkouts and CI actually resolve the core).
- `minimum-stability: dev` with `prefer-stable: true`, required until the core has a Packagist distribution. The README documents the same setup for consumers of this package.

## Boundary Rules

The adapter adapts; the engine decides.

- MUST NOT implement detection rules, penetration signatures, rate limiting, IP blacklisting or whitelisting, banning, security headers, or CORS. None of that exists in `src/` and none may be added. README: "No security headers or CORS are added by this adapter."
- MUST depend on guard-core-php for every verdict. `GuardMiddleware::process()` calls `GuardEngine::execute()` and acts only on its return value (`null` means pass, a `GuardResponse` means block).
- MUST keep framework-agnostic PSR compliance. `src/` imports only `Psr\Http\*` interfaces and `RenzoFranceschini\GuardCore\*` classes. No framework imports, ever.
- MUST stay fail-closed: if the engine throws, `process()` returns the engine's fail-closed response (`500 Security check failed`, overridable through the engine's `customErrorResponses`), never the downstream handler.
- MUST keep the middleware stateless: it holds no mutable state. PHP shared-nothing applies (per request under classic FPM, per worker under Swoole/Octane/RoadRunner); distributed rate limits, bans, and cloud-range caches require Redis.
- MUST NOT widen the bounded body read: `PsrGuardRequest::MAX_BODY_BYTES = 262144` (256 KiB) matches the engine's full-scan window. Payloads beyond the prefix, or signatures split across its boundary, are not detected; that tradeoff is deliberate and engine-coupled.

The real adapter surface (all of `src/`, three final classes in namespace `RenzoFranceschini\GuardCorePsr15`):

- `GuardMiddleware implements MiddlewareInterface`. Constructor takes `GuardEngine $engine`, a `ResponseFactoryInterface`, and a `StreamFactoryInterface`. The constructor calls `$engine->initialize()`; a `GuardRedisException` is swallowed only when `$engine->config()->redisFailOpen` is true, otherwise construction fails closed by rethrowing. `process()` wraps the PSR-7 request in `PsrGuardRequest`, runs the engine, translates block verdicts via `ResponseTranslator`, and passes the ORIGINAL PSR-7 request to the downstream handler on pass.
- `PsrGuardRequest implements RenzoFranceschini\GuardCore\Request\GuardRequest`. Adapts URI path, scheme, full URL, and a pure `urlReplaceScheme()`; upper-cased method; client host from the `REMOTE_ADDR` server param (null when missing or empty); headers into a `HeaderBag` with joined header lines; query params passthrough; and the body as a cached, bounded prefix with `bodyWasTruncated()`. Each instance carries its own `RequestState`.
- `ResponseTranslator`. `translate(GuardResponse): ResponseInterface` copies status code, body (null becomes an empty stream), and all headers onto a response built from the injected PSR-17 factories. Exact translation, no additions.

## Quick Start

This machine has NO `php` and NO `composer` binary. You cannot run the suite locally. Verify commands by reading `composer.json`, `.github/workflows/*.yml`, and `bin/test_psr15.php`; do not invent or execute local commands.

Local install path (per composer.json):

1. Make guard-core-php resolvable: either check out the core at a sibling directory `../guard-core-php` (the path repository) or rely on the VCS fallback `https://github.com/rennf93/guard-core-php.git`.
2. `composer install` (the lock already pins guard-core-php v0.1.0).
3. `composer lint`, then `composer test`.

The CI-verified path (copy this when describing a working environment; from `.github/workflows/ci.yml`):

1. Checkout this repo.
2. Checkout `rennf93/guard-core-php` at ref `guard-core-port-php` into `core-checkout`, then `mv core-checkout ../guard-core-php` so the path repository resolves.
3. Set up PHP from the matrix (8.2, 8.3, or 8.4) with the `mbstring` extension, coverage none.
4. `composer install --no-interaction --no-progress`, then `composer update rennf93/guard-core-php --no-interaction`.
5. Run the php -l sweep, then `REDIS_HOST=127.0.0.1 php bin/test_psr15.php` against a `redis:7-alpine` service on port 6379.

## Development Commands

Composer scripts (composer.json `scripts`; these are the only two):

| Command | What it runs |
| --- | --- |
| `composer test` | `php bin/test_psr15.php` |
| `composer lint` | `for f in $(find src bin -name '*.php'); do php -l "$f" > /dev/null || exit 1; done && echo LINT_OK` |

Direct commands used by CI (verified in `.github/workflows/ci.yml`; the same install, lint, and test steps appear in `release.yml` and `scheduled-lint.yml`):

- `composer install --no-interaction --no-progress`
- `composer update rennf93/guard-core-php --no-interaction` (CI deliberately refreshes the core dependency on every run)
- `for f in $(find src bin -name '*.php'); do php -l "$f" > /dev/null || exit 1; done && echo LINT_OK` (same sweep as `composer lint`)
- `php bin/test_psr15.php` with env `REDIS_HOST=127.0.0.1`
- `composer audit` (Composer audit job, PHP 8.3)

`bin/` contains exactly one script: `bin/test_psr15.php` (the whole test suite, plain PHP, no PHPUnit). There is no Makefile, no PHPUnit config, no PHPStan, no PHP-CS-Fixer, and no docker setup in this repo.

## Project Structure

```
.github/dependabot.yml                Weekly dependabot: github-actions + composer (grouped)
.github/workflows/ci.yml              CI: test matrix php 8.2/8.3/8.4 + redis service + composer audit
.github/workflows/release.yml         Release Gate: same suite, runs on v* tags
.github/workflows/scheduled-lint.yml  Weekly cron (Mon 04:00 UTC): php -l sweep + composer audit
bin/test_psr15.php                    Entire test suite, plain PHP runner with a T assertion harness
composer.json                         Package metadata, autoload, scripts, repositories
composer.lock                         Locked deps; tracked; regenerate only deliberately
src/GuardMiddleware.php               PSR-15 middleware
src/PsrGuardRequest.php               PSR-7 ServerRequest to GuardRequest adapter
src/ResponseTranslator.php            GuardResponse to PSR-7 ResponseInterface translator
LICENSE                               MIT, (c) 2026 Renzo Franceschini
README.md                             Install, usage, lifecycle, behavior notes
```

- `src/` is the PSR-4 package root for `RenzoFranceschini\GuardCorePsr15\`. It is flat: one final class per file, class name equals file name, no subdirectories. A fourth class would be a real surface change: update the README behavior notes in the same PR.
- `vendor/` exists on disk but is gitignored. Never `git add` it. `composer.lock` is tracked; never modify it casually.

## Technology Stack

- PHP `^8.2` (CI matrix: 8.2, 8.3, 8.4; the audit and scheduled-lint jobs run on 8.3).
- Runtime deps (composer.json `require`, with composer.lock versions):
  - `rennf93/guard-core-php ^0.1.0` (locked v0.1.0)
  - `psr/http-message ^1.1|^2.0` (locked 2.0)
  - `psr/http-factory ^1.0` (locked 1.1.0)
  - `psr/http-server-handler ^1.0` (locked 1.0.2)
  - `psr/http-server-middleware ^1.0` (locked 1.0.2)
- Dev deps: `nyholm/psr7 ^1.8` (locked 1.8.2), used only by `bin/test_psr15.php`.
- CI runs a `redis:7-alpine` service container on port 6379 with health checks for the Redis integration tests.
- Actions are pinned by commit SHA: `actions/checkout` v7.0.1 and `shivammathur/setup-php` 2.37.2.

## Testing Guidelines

- Run with `composer test` (or `php bin/test_psr15.php`). Exit code 0 means green, 1 means red. Output: `ok - <label>` or `FAIL - <label>` per assertion, `=== section ===` headers, and a final `Passed: N, Failed: N` plus `N/N GREEN` or `N/N RED`.
- Redis integration: the runner attempts a socket connection to `REDIS_HOST` (default 127.0.0.1) and `REDIS_PORT` (default 6379). Set `REDIS_HOST=0` to force integration off; if no Redis is reachable it prints a SKIP line and unit coverage stands. CI sets `REDIS_HOST=127.0.0.1` against the redis service. Integration uses a random `REDIS_PREFIX=guard_core_psr15:<hex>:` and cleans keys before and after.
- Coverage areas (section names in `bin/test_psr15.php`): PSR-7 to GuardRequest translation (URL parts, upper-cased method, REMOTE_ADDR missing or empty, header joining and case-insensitive lookup, query passthrough, body caching, per-instance state); bounded body read at exactly 262144 bytes (boundary, one byte over, truncation flag); block verdicts through the middleware (403 Forbidden for a blacklisted IP with the on_block hook payload, 301 https enforcement with a Location header, 429 Too many requests with Retry-After: 60, 400 Suspicious activity detected from query or POST body); pass-through (handler called exactly once with the original PSR-7 request); oversize bodies (payload beyond the cap never reaches the engine); whitelist; exclusion paths (including the favicon.ico default); passive mode; fail-closed on engine malfunction (500 Security check failed, customErrorResponses override, check-exception fail-secure); and Redis fail-open versus fail-closed construction.
- Any new engine behavior that flows through the adapter needs assertions here before the PR lands.
- You cannot run the suite on this machine (no php binary). Review runner changes by reading `bin/test_psr15.php`; CI is the executor.

## Code Quality Standards

- `declare(strict_types=1);` at the top of every PHP file in `src/` and `bin/`.
- `final` classes, `private readonly` promoted constructor properties, 4-space indentation, one class per file named after the class.
- Imports in `src/` are limited to `Psr\Http\*` and `RenzoFranceschini\GuardCore\*`. A new import outside those families is a boundary violation (see Boundary Rules).
- The php -l sweep over `src` and `bin` must pass; `composer lint` prints `LINT_OK` on success.
- Conventional commits are the house style (see `git log`): `feat:`, `fix:`, `fix(deps):`, `ci:`, `chore:`, `chore(composer):`, `test:`, `docs:`. Lowercase, imperative, no attribution trailers.
- Dependabot keeps github-actions and composer dependencies fresh weekly (composer updates are grouped into one PR).

## Best Practices

- `main` is protected. Work on a branch, push, open a PR. Never push to `main`, never tag, never publish a release as part of agent work.
- Never `git add vendor/`, `.DS_Store`, or any stray file. Stage explicit paths only.
- Keep the adapter thin. If a change adds detection, verdict logic, or response shaping beyond translation, it belongs in guard-core-php, not here.
- Do not hardwire a PSR-7 implementation: the middleware takes `ResponseFactoryInterface` and `StreamFactoryInterface` so consumers pick their own PSR-17 factory (the tests use nyholm/psr7).
- Preserve fail-closed semantics: never let an engine exception fall through to the downstream handler.
- `ci.yml` still carries an inline comment about a "dev-branch alias in composer.json"; composer.json now requires `^0.1.0` with no alias (commit 55c17a9). Trust composer.json over that comment. CI still checks out the core at branch `guard-core-port-php` into `../guard-core-php` so the path repository resolves, and `composer update rennf93/guard-core-php` floats within the `^0.1.0` constraint.
- Update README.md behavior notes in the same PR whenever adapter behavior changes.

## Related Projects

- `rennf93/guard-core-php`: https://github.com/rennf93/guard-core-php. The engine this adapter delegates to. `SecurityConfig`, `GuardEngine`, `GuardRequest`/`GuardResponse`, `HeaderBag`, `RequestState`, `RedisHandler`, and `GuardRedisException` live there. Resolved via the `../guard-core-php` path repository (canonical: false) with the VCS fallback `https://github.com/rennf93/guard-core-php.git`; CI checks out its `guard-core-port-php` branch.
- `rennf93/psr15-guard`: this repository, the PSR-15 adapter layer of the guard-core ecosystem.
