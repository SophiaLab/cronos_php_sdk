<?php

declare(strict_types=1);

namespace SophiaLabs\CronosFlow\Tests\Support;

use SophiaLabs\CronosFlow\Transport\Request;
use SophiaLabs\CronosFlow\Transport\Response;
use SophiaLabs\CronosFlow\Transport\Transport;

/**
 * Entrega a resposta programada e guarda o que o SDK montou.
 *
 * É o que permite verificar a rota, o cabeçalho, o corpo e a chave de
 * idempotência sem rede nenhuma — que é exatamente o que um teste de SDK tem a
 * dizer.
 */
final class FakeTransport implements Transport
{
    /** @var list<Request> */
    public array $requests = [];

    /** @var list<Response> */
    private array $responses;

    /** @var list<string> */
    private array $streamLines;

    /**
     * @param list<Response> $responses
     * @param list<string>   $streamLines
     */
    public function __construct(array $responses = [], array $streamLines = [])
    {
        $this->responses = $responses;
        $this->streamLines = $streamLines;
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;

        return array_shift($this->responses) ?? new Response(200, '{"status":"queued","message_id":null}');
    }

    public function stream(Request $request, callable $onLine): void
    {
        $this->requests[] = $request;
        foreach ($this->streamLines as $line) {
            if ($onLine($line) === false) {
                return;
            }
        }
    }

    public function lastBody(): string
    {
        return $this->requests[array_key_last($this->requests)]->body ?? '';
    }

    /** @return array<string, mixed> */
    public function lastJson(): array
    {
        $decoded = json_decode($this->lastBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
