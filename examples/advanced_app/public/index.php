<?php

declare(strict_types=1);

use App\Config;
use App\Routes;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use Psr\Http\Message\ResponseInterface;
use RenzoFranceschini\GuardCore\Engine\GuardEngine;
use RenzoFranceschini\GuardCorePsr15\GuardMiddleware;

require __DIR__ . '/../vendor/autoload.php';

$engine = new GuardEngine(Config::securityConfig());
$guard = new GuardMiddleware($engine, new ResponseFactory(), new StreamFactory());

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

$emit($guard->process(ServerRequestFactory::fromGlobals(), new Routes($engine)));
