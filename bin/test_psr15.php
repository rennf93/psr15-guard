<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RenzoFranceschini\GuardCore\Config\SecurityConfig;
use RenzoFranceschini\GuardCore\Engine\GuardEngine;
use RenzoFranceschini\GuardCore\Request\GuardRequest;
use RenzoFranceschini\GuardCore\Request\GuardResponse;
use RenzoFranceschini\GuardCore\Redis\RedisHandler;
use RenzoFranceschini\GuardCorePsr15\GuardMiddleware;
use RenzoFranceschini\GuardCorePsr15\PsrGuardRequest;

require __DIR__ . '/../vendor/autoload.php';

final class T
{
    public int $passed = 0;
    public int $failed = 0;

    public function same(mixed $expected, mixed $actual, string $label): void
    {
        if ($expected === $actual) {
            $this->passed++;
            echo "ok - {$label}\n";
        } else {
            $this->failed++;
            echo "FAIL - {$label}\n";
            echo '  expected: ' . var_export($expected, true) . "\n";
            echo '  actual:   ' . var_export($actual, true) . "\n";
        }
    }

    public function ok(bool $condition, string $label): void
    {
        if ($condition) {
            $this->passed++;
            echo "ok - {$label}\n";
        } else {
            $this->failed++;
            echo "FAIL - {$label}\n";
        }
    }

    public function throws(string $class, callable $fn, string $label): void
    {
        try {
            $fn();
            $this->failed++;
            echo "FAIL - {$label}: no exception\n";
        } catch (Throwable $e) {
            $this->same($class, $e::class, $label);
        }
    }

    public function section(string $name): void
    {
        echo "\n=== {$name} ===\n";
    }
}

final class RecordingHandler implements RequestHandlerInterface
{
    public int $calls = 0;

    public ?ServerRequestInterface $seen = null;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->calls++;
        $this->seen = $request;

        return (new Psr17Factory())->createResponse(200)->withBody((new Psr17Factory())->createStream('downstream'));
    }
}

final class ThrowingUriRequest implements ServerRequestInterface
{
    public function __construct(private readonly ServerRequestInterface $inner)
    {
    }

    public function getUri(): UriInterface
    {
        throw new RuntimeException('malformed request uri');
    }

    public function getProtocolVersion(): string
    {
        return $this->inner->getProtocolVersion();
    }

    public function withProtocolVersion(string $version): static
    {
        return new static($this->inner->withProtocolVersion($version));
    }

    public function getHeaders(): array
    {
        return $this->inner->getHeaders();
    }

    public function hasHeader(string $name): bool
    {
        return $this->inner->hasHeader($name);
    }

    public function getHeader(string $name): array
    {
        return $this->inner->getHeader($name);
    }

    public function getHeaderLine(string $name): string
    {
        return $this->inner->getHeaderLine($name);
    }

    public function withHeader(string $name, $value): static
    {
        return new static($this->inner->withHeader($name, $value));
    }

    public function withAddedHeader(string $name, $value): static
    {
        return new static($this->inner->withAddedHeader($name, $value));
    }

    public function withoutHeader(string $name): static
    {
        return new static($this->inner->withoutHeader($name));
    }

    public function getBody(): StreamInterface
    {
        return $this->inner->getBody();
    }

    public function withBody(StreamInterface $body): static
    {
        return new static($this->inner->withBody($body));
    }

    public function getRequestTarget(): string
    {
        return $this->inner->getRequestTarget();
    }

    public function withRequestTarget(string $requestTarget): static
    {
        return new static($this->inner->withRequestTarget($requestTarget));
    }

    public function getMethod(): string
    {
        return $this->inner->getMethod();
    }

    public function withMethod(string $method): static
    {
        return new static($this->inner->withMethod($method));
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): static
    {
        return new static($this->inner->withUri($uri, $preserveHost));
    }

    public function getServerParams(): array
    {
        return $this->inner->getServerParams();
    }

    public function getCookieParams(): array
    {
        return $this->inner->getCookieParams();
    }

    public function withCookieParams(array $cookies): static
    {
        return new static($this->inner->withCookieParams($cookies));
    }

    public function getQueryParams(): array
    {
        return $this->inner->getQueryParams();
    }

    public function withQueryParams(array $query): static
    {
        return new static($this->inner->withQueryParams($query));
    }

    public function getParsedBody(): mixed
    {
        return $this->inner->getParsedBody();
    }

    public function withParsedBody($data): static
    {
        return new static($this->inner->withParsedBody($data));
    }

    public function getAttributes(): array
    {
        return $this->inner->getAttributes();
    }

    public function getAttribute(string $name, $default = null): mixed
    {
        return $this->inner->getAttribute($name, $default);
    }

    public function withAttribute(string $name, $value): static
    {
        return new static($this->inner->withAttribute($name, $value));
    }

    public function withoutAttribute(string $name): static
    {
        return new static($this->inner->withoutAttribute($name));
    }

    public function getUploadedFiles(): array
    {
        return $this->inner->getUploadedFiles();
    }

    public function withUploadedFiles(array $uploadedFiles): static
    {
        return new static($this->inner->withUploadedFiles($uploadedFiles));
    }
}

/**
 * @param array<string, string> $headers
 */
function psrRequest(
    string $path = '/',
    string $ip = '203.0.113.9',
    string $method = 'GET',
    string $query = '',
    string $body = '',
    array $headers = []
): ServerRequestInterface {
    $uri = 'http://ex.test' . $path . ($query !== '' ? '?' . $query : '');

    return new ServerRequest($method, $uri, $headers, $body !== '' ? $body : null, '1.1', ['REMOTE_ADDR' => $ip]);
}

function makeStack(SecurityConfig $config): array
{
    $factory = new Psr17Factory();
    $engine = new GuardEngine($config);

    return [new GuardMiddleware($engine, $factory, $factory), $engine];
}

/**
 * @param list<array<string, mixed>> $hooks
 */
function hookCapture(array &$hooks): \Closure
{
    return function (object $request, array $payload) use (&$hooks): void {
        $hooks[] = $payload;
    };
}

function streamFromString(string $content): StreamInterface
{
    $resource = fopen('php://temp', 'r+b');
    fwrite($resource, $content);
    rewind($resource);

    return Stream::create($resource);
}

$t = new T();
$factory = new Psr17Factory();
$attackQuery = 'q=' . urlencode("<script>alert('xss')</script>");

$t->section('PSR-7 -> GuardRequest translation');
$psr = new ServerRequest('get', 'http://ex.test/api/users?x=1', ['X-Custom-Test' => ['a', 'b'], 'Content-Type' => 'text/plain'], 'hello', '1.1', ['REMOTE_ADDR' => '198.51.100.7']);
$guard = new PsrGuardRequest($psr);
$t->same('/api/users', $guard->urlPath(), 'url_path');
$t->same('http', $guard->urlScheme(), 'url_scheme');
$t->same('http://ex.test/api/users?x=1', $guard->urlFull(), 'url_full');
$t->same('https://ex.test/api/users?x=1', $guard->urlReplaceScheme('https'), 'url_replace_scheme pure');
$t->same('http://ex.test/api/users?x=1', $guard->urlFull(), 'url_replace_scheme did not mutate');
$t->same('GET', $guard->method(), 'method upper-cased');
$t->same('198.51.100.7', $guard->clientHost(), 'client_host from REMOTE_ADDR');
$t->same('a, b', $guard->headers()->get('x-custom-test'), 'multi-value header joined');
$t->same('text/plain', $guard->headers()->get('Content-Type'), 'header get is case-insensitive');
$t->same(['x' => '1'], $guard->queryParams(), 'query_params passthrough');
$t->same('hello', $guard->body(), 'body first read');
$t->same('hello', $guard->body(), 'body cached on replay');
$t->same(true, $guard->state() !== (new PsrGuardRequest($psr))->state(), 'state is per translated request');

$noAddr = new ServerRequest('GET', 'http://ex.test/');
$guard = new PsrGuardRequest($noAddr);
$t->same(null, $guard->clientHost(), 'missing REMOTE_ADDR -> null client_host');
$emptyAddr = new ServerRequest('GET', 'http://ex.test/', [], null, '1.1', ['REMOTE_ADDR' => '']);
$t->same(null, (new PsrGuardRequest($emptyAddr))->clientHost(), 'empty REMOTE_ADDR -> null client_host');

$t->section('bounded body read (spec 01.2)');
$guard = new PsrGuardRequest((new ServerRequest('POST', 'http://ex.test/'))->withBody(streamFromString(str_repeat('A', 300000))));
$oversize = $guard->body();
$t->same(PsrGuardRequest::MAX_BODY_BYTES, strlen($oversize), 'oversize body capped at MaxBodyBytes');
$t->same(true, $guard->bodyWasTruncated(), 'truncation observable');
$t->same($oversize, $guard->body(), 'replay returns the same cached prefix');
$exact = PsrGuardRequest::MAX_BODY_BYTES;
$guard = new PsrGuardRequest((new ServerRequest('POST', 'http://ex.test/'))->withBody(streamFromString(str_repeat('B', $exact))));
$t->same($exact, strlen($guard->body()), 'body exactly MaxBodyBytes read fully');
$t->same(false, $guard->bodyWasTruncated(), 'boundary body not flagged truncated');
$guard = new PsrGuardRequest((new ServerRequest('POST', 'http://ex.test/'))->withBody(streamFromString(str_repeat('B', $exact + 1))));
$t->same($exact, strlen($guard->body()), 'body one byte over the cap capped');
$t->same(true, $guard->bodyWasTruncated(), 'one byte over flagged truncated');

$t->section('block verdict -> PSR-7 response');
$hooks = [];
$config = new SecurityConfig(enableRedis: false, blacklist: ['192.0.2.66'], onBlock: hookCapture($hooks));
[$middleware] = makeStack($config);
$handler = new RecordingHandler();
$blocked = $middleware->process(psrRequest('/private', '192.0.2.66'), $handler);
$t->same(403, $blocked->getStatusCode(), 'blacklisted ip -> 403');
$t->same('Forbidden', (string) $blocked->getBody(), '403 body exact');
// Engine >= 4.1 sets an explicit content type on block responses; the
// published 4.0.x engine does not, so the header is optional here until
// the coordinated floor bump.
$headers = array_change_key_case($blocked->getHeaders(), CASE_LOWER);
if (isset($headers['content-type'])) {
    $t->same(['text/plain; charset=utf-8'], $headers['content-type'], 'block response content type explicit when the engine sets it');
}
unset($headers['content-type']);
$t->same([], $headers, 'no unexpected headers on plain block (later sections)');
$t->same(0, $handler->calls, 'handler not called on block');
$t->same('ip_security', $hooks[0]['check_name'] ?? null, 'on_block check_name');
$t->same('IP blacklisted: 192.0.2.66', $hooks[0]['reason'] ?? null, 'on_block reason');
$t->same(403, $hooks[0]['status_code'] ?? null, 'on_block status_code');
$t->same(false, $hooks[0]['passive_mode'] ?? null, 'on_block passive_mode false');

$hooks = [];
$config = new SecurityConfig(enableRedis: false, enforceHttps: true, onBlock: hookCapture($hooks));
[$middleware] = makeStack($config);
$redirect = $middleware->process(psrRequest('/order'), new RecordingHandler());
$t->same(301, $redirect->getStatusCode(), 'https enforcement -> 301');
$t->same(['https://ex.test/order'], $redirect->getHeader('location'), 'Location header translated to PSR-7');

$hooks = [];
$config = new SecurityConfig(enableRedis: false, rateLimit: 2, rateLimitWindow: 60, enableRateLimiting: true, enablePenetrationDetection: false, onBlock: hookCapture($hooks));
[$middleware] = makeStack($config);
$handler = new RecordingHandler();
$limited = psrRequest('/limited', '203.0.113.44');
$t->same(200, $middleware->process($limited, $handler)->getStatusCode(), 'rate limit hit 1 passes');
$t->same(200, $middleware->process($limited, $handler)->getStatusCode(), 'rate limit hit 2 passes');
$third = $middleware->process($limited, $handler);
$t->same(429, $third->getStatusCode(), 'rate limit hit 3 -> 429');
$t->same('Too many requests', (string) $third->getBody(), '429 body exact');
$t->same(['60'], $third->getHeader('Retry-After'), 'Retry-After header translated');

$hooks = [];
$config = new SecurityConfig(enableRedis: false, onBlock: hookCapture($hooks));
[$middleware] = makeStack($config);
$handler = new RecordingHandler();
$suspicious = $middleware->process(psrRequest('/search', '203.0.113.10', 'GET', $attackQuery), $handler);
$t->same(400, $suspicious->getStatusCode(), 'penetration via query param -> 400');
$t->same('Suspicious activity detected', (string) $suspicious->getBody(), 'suspicious body exact');

$t->section('pass verdict -> downstream handler');
$hooks = [];
$config = new SecurityConfig(enableRedis: false, onBlock: hookCapture($hooks));
[$middleware] = makeStack($config);
$handler = new RecordingHandler();
$request = psrRequest('/search', '203.0.113.10', 'GET', 'q=hello+world');
$passthrough = $middleware->process($request, $handler);
$t->same(1, $handler->calls, 'handler called exactly once on pass');
$t->same($request, $handler->seen, 'handler received the original PSR-7 request');
$t->same('downstream', (string) $passthrough->getBody(), 'handler response returned unchanged');
$t->same([], $hooks, 'no on_block on pass');

$t->section('POST body scanned and replayed through the stack');
$config = new SecurityConfig(enableRedis: false);
[$middleware] = makeStack($config);
$handler = new RecordingHandler();
$t->same(200, $middleware->process(psrRequest('/submit', '203.0.113.20', 'POST', body: 'name=renzo&comment=ok'), $handler)->getStatusCode(), 'clean POST passes');
$t->same(400, $middleware->process(psrRequest('/submit', '203.0.113.21', 'POST', body: 'comment=<script>alert(1)</script>'), $handler)->getStatusCode(), 'attack body in POST -> 400');

$t->section('oversize body per spec 01 (engine only ever sees the capped prefix)');
$seenBody = null;
$config = new SecurityConfig(
    enableRedis: false,
    enablePenetrationDetection: false,
    customRequestCheck: static function (GuardRequest $request) use (&$seenBody): ?GuardResponse {
        $seenBody = $request->body();

        return null;
    }
);
[$middleware] = makeStack($config);
$handler = new RecordingHandler();
$beyond = (new ServerRequest('POST', 'http://ex.test/upload', [], null, '1.1', ['REMOTE_ADDR' => '203.0.113.30']))
    ->withBody(streamFromString(str_repeat('lorem ipsum ', 25000) . '<script>alert(1)</script>'));
$t->same(200, $middleware->process($beyond, $handler)->getStatusCode(), 'beyond-cap suffix does not block the request');
$t->same(PsrGuardRequest::MAX_BODY_BYTES, strlen($seenBody ?? ''), 'engine received exactly the capped prefix');
$t->same(false, str_contains($seenBody ?? '', '<script>'), 'payload beyond the cap never reaches the engine');
$seenBody = null;
$inside = (new ServerRequest('POST', 'http://ex.test/upload', [], null, '1.1', ['REMOTE_ADDR' => '203.0.113.31']))
    ->withBody(streamFromString(str_repeat('lorem ipsum ', 20000) . '<script>alert(1)</script>'));
$t->same(200, $middleware->process($inside, new RecordingHandler())->getStatusCode(), 'spy stack passes the mid-size attack request');
$t->same(true, str_contains($seenBody ?? '', '<script>alert(1)</script>'), 'payload inside the prefix reaches the engine');

$t->section('mid-size POST body detection end to end');
$config = new SecurityConfig(enableRedis: false);
[$middleware] = makeStack($config);
$filler = 'lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod ';
$hit = $middleware->process(
    psrRequest('/submit', '203.0.113.32', 'POST', body: $filler . '<script>alert(1)</script>' . $filler),
    new RecordingHandler()
);
$t->same(400, $hit->getStatusCode(), 'attack inside a mid-size body is detected');
$t->same('Suspicious activity detected', (string) $hit->getBody(), 'mid-size attack body exact');
$clean = $middleware->process(psrRequest('/submit', '203.0.113.33', 'POST', body: $filler), new RecordingHandler());
$t->same(200, $clean->getStatusCode(), 'clean mid-size body passes');

$t->section('whitelist through the full stack');
$hooks = [];
$config = new SecurityConfig(enableRedis: false, whitelist: ['203.0.113.50'], onBlock: hookCapture($hooks));
[$middleware] = makeStack($config);
$allowed = $middleware->process(psrRequest('/search', '203.0.113.50', 'GET', $attackQuery), new RecordingHandler());
$t->same(200, $allowed->getStatusCode(), 'whitelisted ip passes despite attack payload');
$other = $middleware->process(psrRequest('/search', '203.0.113.99', 'GET', $attackQuery), new RecordingHandler());
$t->same(403, $other->getStatusCode(), 'non-whitelisted ip -> 403');
$t->same('Forbidden', (string) $other->getBody(), 'whitelist miss body exact');

$t->section('exclusion paths through the full stack');
$hooks = [];
$config = new SecurityConfig(enableRedis: false, blacklist: ['192.0.2.66'], onBlock: hookCapture($hooks));
[$middleware] = makeStack($config);
$docs = $middleware->process(psrRequest('/docs/api', '203.0.113.60', 'GET', $attackQuery), new RecordingHandler());
$t->same(200, $docs->getStatusCode(), 'attack on excluded path passes (exclusion-scoped pipeline)');
$search = $middleware->process(psrRequest('/search', '203.0.113.60', 'GET', $attackQuery), new RecordingHandler());
$t->same(400, $search->getStatusCode(), 'same attack outside excluded path -> 400');
$docsBanned = $middleware->process(psrRequest('/docs/api', '192.0.2.66'), new RecordingHandler());
$t->same(403, $docsBanned->getStatusCode(), 'ip_security still enforced on excluded paths');
$favicon = $middleware->process(psrRequest('/favicon.ico', '203.0.113.60', 'GET', $attackQuery), new RecordingHandler());
$t->same(200, $favicon->getStatusCode(), 'default exclusion favicon.ico passes');

$t->section('passive mode');
$hooks = [];
$config = new SecurityConfig(enableRedis: false, passiveMode: true, onBlock: hookCapture($hooks));
[$middleware] = makeStack($config);
$handler = new RecordingHandler();
$passive = $middleware->process(psrRequest('/search', '203.0.113.70', 'GET', $attackQuery), $handler);
$t->same(200, $passive->getStatusCode(), 'passive mode does not block');
$t->same(1, $handler->calls, 'passive mode hands request to the handler');
$t->same([], $hooks, 'passive mode fires no on_block from suspicious_activity');

$t->section('fail-closed on engine malfunction (conformance.md)');
$config = new SecurityConfig(enableRedis: false);
[$middleware] = makeStack($config);
$handler = new RecordingHandler();
$malformed = $middleware->process(new ThrowingUriRequest(psrRequest('/x')), $handler);
$t->same(500, $malformed->getStatusCode(), 'engine malfunction -> fail-closed 500');
$t->same('Security check failed', (string) $malformed->getBody(), 'fail-closed body exact');
$t->same(0, $handler->calls, 'handler not called on malfunction');

$config = new SecurityConfig(enableRedis: false, customErrorResponses: [500 => 'Security unavailable']);
[$middleware] = makeStack($config);
$custom = $middleware->process(new ThrowingUriRequest(psrRequest('/x')), new RecordingHandler());
$t->same('Security unavailable', (string) $custom->getBody(), 'custom_error_responses honored on fail-closed');

$config = new SecurityConfig(enableRedis: false, customRequestCheck: static fn (): never => throw new RuntimeException('validator exploded'));
[$middleware] = makeStack($config);
$pipelineFail = $middleware->process(psrRequest('/x', '203.0.113.80'), new RecordingHandler());
$t->same(500, $pipelineFail->getStatusCode(), 'check exception -> pipeline fail-secure 500');
$t->same('Security check failed', (string) $pipelineFail->getBody(), 'pipeline fail-secure body exact');

$config = new SecurityConfig(enableRedis: true, redisFailOpen: false);
$engine = new GuardEngine($config, new RedisHandler(enableRedis: true, prefix: 'guard_core_psr15u:', host: '127.0.0.1', port: 1));
$t->throws(
    'RenzoFranceschini\GuardCore\Redis\GuardRedisException',
    static function () use ($config, $engine): void {
        $factory = new Psr17Factory();
        new GuardMiddleware($engine, $factory, $factory);
    },
    'redis down + redis_fail_open=false: middleware construction fails closed'
);

$config = new SecurityConfig(enableRedis: true, redisFailOpen: true);
$engine = new GuardEngine($config, new RedisHandler(enableRedis: true, prefix: 'guard_core_psr15u:', host: '127.0.0.1', port: 1));
$factory = new Psr17Factory();
$middleware = new GuardMiddleware($engine, $factory, $factory);
$openPass = $middleware->process(psrRequest('/x', '203.0.113.81'), new RecordingHandler());
$t->same(200, $openPass->getStatusCode(), 'redis down + redis_fail_open=true: construction survives, request passes (bounded fail-open)');

$t->section('integration: shared state over real redis');
$integration = getenv('REDIS_HOST') !== '0';
if ($integration) {
    $host = getenv('REDIS_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('REDIS_PORT') ?: 6379);
    $socket = @fsockopen($host, $port, $errno, $errstr, 1.0);
    if ($socket === false) {
        echo "SKIP: integration mode: no redis reachable at {$host}:{$port} ({$errstr}); unit coverage stands\n";
    } else {
        fclose($socket);
        runRedisIntegration($t);
    }
} else {
    echo "\nNOTE: integration mode off (set REDIS_HOST to run the adapter over real redis)\n";
}

function runRedisIntegration(T $t): void
{
    putenv('REDIS_PREFIX=guard_core_psr15:' . bin2hex(random_bytes(3)) . ':');
    $redis = RedisHandler::fromEnv();
    $redis->initialize();
    $conn = $redis->connection();
    foreach ($redis->keys('*') as $key) {
        $conn->del((string) $key);
    }

    $t->section('integration: rate limit shared across engine instances');
    $factory = new Psr17Factory();
    $configA = new SecurityConfig(rateLimit: 2, rateLimitWindow: 60, enableRateLimiting: true, enablePenetrationDetection: false);
    $engineA = new GuardEngine($configA, $redis);
    $middlewareA = new GuardMiddleware($engineA, $factory, $factory);
    $limited = psrRequest('/limited', '192.0.2.11');
    $t->same(200, $middlewareA->process($limited, new RecordingHandler())->getStatusCode(), 'engine A hit 1 passes');
    $t->same(200, $middlewareA->process($limited, new RecordingHandler())->getStatusCode(), 'engine A hit 2 passes');
    $t->same(429, $middlewareA->process($limited, new RecordingHandler())->getStatusCode(), 'engine A hit 3 -> 429 over redis');

    $configB = new SecurityConfig(rateLimit: 2, rateLimitWindow: 60, enableRateLimiting: true, enablePenetrationDetection: false);
    $engineB = new GuardEngine($configB, $redis);
    $middlewareB = new GuardMiddleware($engineB, $factory, $factory);
    $t->same(429, $middlewareB->process($limited, new RecordingHandler())->getStatusCode(), 'fresh engine B sees the shared bucket immediately');

    $t->section('integration: ban written by engine A blocks engine B through the adapter');
    $t->same(true, $engineA->banManager()->ban('192.0.2.66', 60, 'psr15_integration'), 'engine A bans 192.0.2.66');
    $banned = $middlewareB->process(psrRequest('/private', '192.0.2.66'), new RecordingHandler());
    $t->same(403, $banned->getStatusCode(), 'engine B blocks the banned ip');
    $t->same('IP address banned', (string) $banned->getBody(), 'ban body exact');

    foreach ($redis->keys('*') as $key) {
        $conn->del((string) $key);
    }
}

$total = $t->passed + $t->failed;
echo "\nPassed: {$t->passed}, Failed: {$t->failed}\n";
echo "{$t->passed}/{$total}" . ($t->failed === 0 ? ' GREEN' : ' RED') . "\n";
exit($t->failed === 0 ? 0 : 1);
