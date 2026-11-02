<?php

declare(strict_types=1);

namespace SophiaLabs\CronosFlow\Model;

/**
 * Um número cadastrado na conta — o que envia.
 *
 * O `id` é o `client_id` que vai nas rotas de mensagem, e `clientId()` já
 * devolve ele como texto, que é a forma que elas esperam.
 */
final class Client
{
    /**
     * @param list<string> $messageDefault
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $number,
        public readonly array $messageDefault = [],
        public readonly ?int $waitMinDefault = null,
        public readonly ?int $waitMaxDefault = null,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromApi(array $payload): self
    {
        return new self(
            id: (int) ($payload['id'] ?? 0),
            name: (string) ($payload['name'] ?? ''),
            number: (string) ($payload['number'] ?? ''),
            messageDefault: array_values(array_map('strval', (array) ($payload['message_default'] ?? []))),
            waitMinDefault: isset($payload['wait_min_default']) ? (int) $payload['wait_min_default'] : null,
            waitMaxDefault: isset($payload['wait_max_default']) ? (int) $payload['wait_max_default'] : null,
        );
    }

    public function clientId(): string
    {
        return (string) $this->id;
    }
}
