<?php

declare(strict_types=1);

namespace SophiaLabs\CronosFlow\Model;

/**
 * Um evento do stream de conexão.
 *
 * `type` é `qr` enquanto espera a leitura, `logged_in` quando o aparelho entra,
 * e o stream fecha sozinho em 120 segundos com `timeout`.
 */
final class QrEvent
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $type,
        public readonly array $data = [],
    ) {
    }

    /** O conteúdo do QR code, quando o evento é `qr`. */
    public function qr(): ?string
    {
        $value = $this->data['qr'] ?? null;

        return $value === null ? null : (string) $value;
    }

    public function isLoggedIn(): bool
    {
        return $this->type === 'logged_in';
    }

    public function isTimeout(): bool
    {
        return $this->type === 'timeout';
    }
}
