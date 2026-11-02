<?php

declare(strict_types=1);

namespace SophiaLabs\CronosFlow\Model;

/** Se a sessão do WhatsApp daquele número está conectada agora. */
final class SessionStatus
{
    public function __construct(
        public readonly string $clientId,
        public readonly bool $loggedIn,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromApi(array $payload): self
    {
        return new self(
            clientId: (string) ($payload['client_id'] ?? ''),
            loggedIn: (bool) ($payload['logged_in'] ?? false),
        );
    }
}
