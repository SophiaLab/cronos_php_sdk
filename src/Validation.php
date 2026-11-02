<?php

declare(strict_types=1);

namespace SophiaLabs\CronosFlow;

use SophiaLabs\CronosFlow\Exception\ValidationException;

/**
 * As validações que a API faz, repetidas aqui para falhar antes de sair da máquina.
 *
 * Não é desconfiança da API: é economia e clareza. Um `to` sem código de país
 * custa uma ida à rede para voltar 422; uma enquete com opção repetida custa o
 * mesmo. Checar antes devolve o erro onde o programador está olhando, e não
 * gasta o teto por minuto da chave.
 *
 * A exceção levantada é a mesma do 422 da API: quem escreve
 * `catch (ValidationException)` quer pegar "pedido inválido", não importa quem
 * percebeu primeiro.
 */
final class Validation
{
    public const MAX_TEXT_LEN = 4096;
    public const MAX_CAPTION_LEN = 2000;
    public const MAX_DESTINATION_LEN = 20;
    public const MIN_POLL_OPTIONS = 2;
    public const MAX_POLL_OPTIONS = 12;
    public const MAX_POLL_QUESTION = 255;
    public const MAX_POLL_OPTION = 25;
    public const MAX_WAIT_SECONDS = 86400;

    /** Confere o E.164 e devolve com o `+` na frente. */
    public static function destination(string $number): string
    {
        $cleaned = str_replace([' ', '-'], '', trim($number));
        if (preg_match('/^\+?[1-9]\d{7,14}$/', $cleaned) !== 1) {
            throw new ValidationException(
                "destino inválido: {$number}. Use E.164 com código do país, por exemplo +5511999999999.",
                422,
            );
        }

        return str_starts_with($cleaned, '+') ? $cleaned : "+{$cleaned}";
    }

    /**
     * O id do número que envia, como texto.
     *
     * Aceita inteiro por conveniência: todo mundo erra isso uma vez, porque
     * `GET /api/clients` devolve o id como número e o envio espera texto.
     */
    public static function clientId(int|string $clientId): string
    {
        $text = trim((string) $clientId);
        if (preg_match('/^\d{1,18}$/', $text) !== 1) {
            throw new ValidationException(
                "client_id inválido: {$clientId}. É o id numérico do cliente, o mesmo de GET /api/clients.",
                422,
            );
        }

        return $text;
    }

    public static function text(string $message): string
    {
        if (trim($message) === '') {
            throw new ValidationException('mensagem vazia.', 422);
        }
        if (mb_strlen($message) > self::MAX_TEXT_LEN) {
            throw new ValidationException(
                'mensagem com ' . mb_strlen($message) . ' caracteres; o teto é ' . self::MAX_TEXT_LEN . '.',
                422,
            );
        }

        return $message;
    }

    public static function caption(?string $caption): ?string
    {
        if ($caption === null) {
            return null;
        }

        $cleaned = trim($caption);
        if ($cleaned === '') {
            return null;
        }
        if (mb_strlen($cleaned) > self::MAX_CAPTION_LEN) {
            throw new ValidationException(
                'legenda com ' . mb_strlen($cleaned) . ' caracteres; o teto é ' . self::MAX_CAPTION_LEN . '.',
                422,
            );
        }

        return $cleaned;
    }

    /**
     * @param list<string> $options
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function poll(string $question, array $options): array
    {
        $cleanedQuestion = trim($question);
        if ($cleanedQuestion === '') {
            throw new ValidationException('a enquete precisa de uma pergunta.', 422);
        }
        if (mb_strlen($cleanedQuestion) > self::MAX_POLL_QUESTION) {
            throw new ValidationException('pergunta acima de ' . self::MAX_POLL_QUESTION . ' caracteres.', 422);
        }

        $cleaned = array_map(static fn (string $option): string => trim($option), $options);
        $total = count($cleaned);
        if ($total < self::MIN_POLL_OPTIONS || $total > self::MAX_POLL_OPTIONS) {
            throw new ValidationException(
                'a enquete precisa de ' . self::MIN_POLL_OPTIONS . ' a ' . self::MAX_POLL_OPTIONS
                . " opções; vieram {$total}.",
                422,
            );
        }
        foreach ($cleaned as $option) {
            if ($option === '') {
                throw new ValidationException('opção vazia na enquete.', 422);
            }
            if (mb_strlen($option) > self::MAX_POLL_OPTION) {
                throw new ValidationException("opção acima de " . self::MAX_POLL_OPTION . " caracteres: {$option}.", 422);
            }
        }
        // Duas opções iguais viram uma só na tela de quem recebe, e a enquete
        // fica ambígua; o WhatsApp também recusa.
        if (count(array_unique($cleaned)) !== $total) {
            throw new ValidationException('a enquete tem opções repetidas.', 422);
        }

        return [$cleanedQuestion, array_values($cleaned)];
    }

    public static function waitWindow(?int $waitMin, ?int $waitMax): void
    {
        foreach (['wait_min_default' => $waitMin, 'wait_max_default' => $waitMax] as $name => $value) {
            if ($value !== null && ($value < 0 || $value > self::MAX_WAIT_SECONDS)) {
                throw new ValidationException("{$name} precisa estar entre 0 e " . self::MAX_WAIT_SECONDS . '.', 422);
            }
        }
        if ($waitMin !== null && $waitMax !== null && $waitMin > $waitMax) {
            throw new ValidationException('wait_min_default não pode ser maior que wait_max_default.', 422);
        }
    }
}
