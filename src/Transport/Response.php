<?php

declare(strict_types=1);

namespace SophiaLabs\CronosFlow\Transport;

/** O que voltou: status, cabeçalhos em minúsculas e o corpo cru. */
final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly array $headers = [],
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[mb_strtolower($name)] ?? null;
    }

    /** O corpo como array, ou `null` quando não é JSON. */
    public function json(): mixed
    {
        if ($this->body === '') {
            return null;
        }

        try {
            return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }
}
