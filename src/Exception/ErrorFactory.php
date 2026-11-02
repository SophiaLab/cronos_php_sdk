<?php

declare(strict_types=1);

namespace SophiaLabs\CronosFlow\Exception;

/**
 * Traduz a resposta da API na exceção certa.
 *
 * A leitura do texto do `detail` existe porque a API usa o mesmo 403 para três
 * situações diferentes, e porque o 409 separa "chave de idempotência em voo" de
 * "número duplicado". São marcas documentadas em `/docs/api`, e fazem parte do
 * contrato: se o texto mudar lá, muda aqui.
 */
final class ErrorFactory
{
    public static function from(int $status, mixed $detail, ?float $retryAfter = null): CronosException
    {
        $text = self::text($detail);
        $lowered = mb_strtolower($text);

        return match (true) {
            $status === 401 => new AuthenticationException($text ?: 'Chave de API ausente.', $status, $detail),
            $status === 402 => new SubscriptionRequired(
                $text ?: 'Assinatura vencida ou ainda não ativada.',
                $status,
                $detail,
            ),
            $status === 403 => self::forbidden($text, $lowered, $status, $detail),
            $status === 404 => new NotFoundException($text ?: 'Não encontrado.', $status, $detail),
            $status === 409 => self::conflict($text, $lowered, $status, $detail),
            $status === 413 => new PayloadTooLarge($text ?: 'Arquivo grande demais.', $status, $detail),
            $status === 415 => new UnsupportedMedia($text ?: 'Formato não suportado.', $status, $detail),
            $status === 422 => new ValidationException($text ?: 'Requisição inválida.', $status, $detail),
            $status === 429 => new RateLimited(
                $text ?: 'Limite de requisições excedido.',
                $status,
                $detail,
                $retryAfter,
            ),
            $status === 502 => new SendFailed($text ?: 'Falha no envio.', $status, $detail),
            $status >= 500 => new ServerException($text ?: "Erro do servidor ({$status}).", $status, $detail),
            default => new CronosException($text ?: "Resposta inesperada ({$status}).", $status, $detail),
        };
    }

    private static function forbidden(string $text, string $lowered, int $status, mixed $detail): CronosException
    {
        if (str_contains($lowered, 'limite diário')
            || str_contains($lowered, 'limite diario')
            || str_contains($lowered, 'plano configurado')
        ) {
            return new DailyLimitReached($text, $status, $detail);
        }

        if (str_contains($lowered, 'account_blocked') || str_contains($lowered, 'bloquead')) {
            return new AccountBlocked($text ?: 'Conta bloqueada.', $status, $detail);
        }

        return new ApiKeyRejected($text ?: 'Chave de API inválida.', $status, $detail);
    }

    private static function conflict(string $text, string $lowered, int $status, mixed $detail): CronosException
    {
        if (str_contains($lowered, 'idempotency')) {
            return new IdempotencyInFlight($text, $status, $detail);
        }

        if (str_contains($lowered, 'duplicate_number') || str_contains($lowered, 'já está cadastrado')) {
            return new DuplicateNumber($text, $status, $detail);
        }

        return new ConflictException($text, $status, $detail);
    }

    /** O `detail` chega string, objeto ou lista de erros de campo do pydantic. */
    private static function text(mixed $detail): string
    {
        if (is_string($detail)) {
            return $detail;
        }

        if (is_array($detail)) {
            foreach (['message', 'msg', 'detail', 'code'] as $field) {
                if (isset($detail[$field]) && is_string($detail[$field])) {
                    return $detail[$field];
                }
            }

            $parts = [];
            foreach (array_slice($detail, 0, 3) as $item) {
                $parts[] = self::text($item);
            }

            return implode('; ', array_filter($parts));
        }

        return $detail === null ? '' : (string) json_encode($detail, JSON_UNESCAPED_UNICODE);
    }
}
