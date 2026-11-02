<?php

declare(strict_types=1);

namespace SophiaLabs\CronosFlow\Model;

/**
 * O aceite do envio.
 *
 * `queued` quer dizer "aceito para envio", não "entregue": a entrega acontece
 * depois, do lado do WhatsApp. `messageId` vem nulo — o aceite não devolve
 * identificador de mensagem.
 */
final class SendResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $messageId = null,
        public readonly ?string $idempotencyKey = null,
    ) {
    }

    public function accepted(): bool
    {
        return $this->status === 'queued';
    }

    /** @param array<string, mixed> $payload */
    public static function fromApi(array $payload, ?string $idempotencyKey = null): self
    {
        return new self(
            status: (string) ($payload['status'] ?? ''),
            messageId: isset($payload['message_id']) ? (string) $payload['message_id'] : null,
            idempotencyKey: $idempotencyKey,
        );
    }
}
