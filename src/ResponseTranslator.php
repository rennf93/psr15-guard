<?php

declare(strict_types=1);

namespace RenzoFranceschini\GuardCorePsr15;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RenzoFranceschini\GuardCore\Request\GuardResponse;

final class ResponseTranslator
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory
    ) {
    }

    public function translate(GuardResponse $response): ResponseInterface
    {
        $psr7 = $this->responseFactory
            ->createResponse($response->statusCode())
            ->withBody($this->streamFactory->createStream($response->body() ?? ''));
        foreach ($response->headers()->all() as $name => $value) {
            $psr7 = $psr7->withHeader($name, $value);
        }

        return $psr7;
    }
}
