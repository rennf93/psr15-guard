<?php

declare(strict_types=1);

namespace RenzoFranceschini\GuardCorePsr15;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RenzoFranceschini\GuardCore\Engine\GuardEngine;
use RenzoFranceschini\GuardCore\Redis\GuardRedisException;

final class GuardMiddleware implements MiddlewareInterface
{
    private readonly ResponseTranslator $translator;

    public function __construct(
        private readonly GuardEngine $engine,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory
    ) {
        $this->translator = new ResponseTranslator($responseFactory, $streamFactory);
        try {
            $engine->initialize();
        } catch (GuardRedisException $e) {
            if (!$engine->config()->redisFailOpen) {
                throw $e;
            }
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $blocked = $this->engine->execute(new PsrGuardRequest($request));
        } catch (\Throwable) {
            return $this->translator->translate($this->engine->failClosedResponse());
        }

        if ($blocked !== null) {
            return $this->translator->translate($blocked);
        }

        return $handler->handle($request);
    }
}
