<?php

declare(strict_types=1);

namespace SophiaLabs\CronosFlow\Exception;

/**
 * 429: teto por minuto. É passageiro, e o SDK já repete com espera crescente.
 *
 * Chega ao chamador só quando as tentativas acabaram.
 */
final class RateLimited extends CronosException
{
    public function __construct(
        string $message,
        ?int $status = 429,
        mixed $detail = null,
        public readonly ?float $retryAfter = null,
    ) {
        parent::__construct($message, $status, $detail);
    }
}
