<?php

declare(strict_types=1);

namespace SophiaLabs\CronosFlow\Transport;

/**
 * Uma requisição pronta para sair, sem saber como vai sair.
 *
 * O corpo já vem montado em `body` (JSON ou multipart). Montar aqui, e não no
 * transporte, é o que permite trocar o cURL por outro cliente HTTP sem
 * reescrever a formação do multipart — e é o que faz o teste conseguir ler
 * exatamente o que o SDK montou.
 */
final class Request
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
        public readonly ?string $body = null,
        public readonly float $timeout = 30.0,
        public readonly bool $stream = false,
    ) {
    }
}
