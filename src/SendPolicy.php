<?php

declare(strict_types=1);

namespace SophiaLabs\CronosFlow;

use InvalidArgumentException;

/**
 * O que o cliente faz entre uma mensagem e a seguinte, e quando repete.
 *
 * Os valores padrão são os mesmos que a plataforma usa no disparo: de 35 a 45
 * segundos entre mensagens do mesmo número. Quem instala o SDK e não configura
 * nada já sai no ritmo que o produto considera seguro.
 *
 * Desligar o ritmo é explícito:
 *
 *     new SendPolicy(minInterval: 0.0, maxInterval: 0.0)
 *
 * Faça isso só quando **outra coisa** já controla a cadência — uma fila com
 * agendamento, por exemplo. Sem ritmo nenhum, o bloqueio do chip é questão de
 * tempo.
 */
final class SendPolicy
{
    public const DEFAULT_MIN_INTERVAL = 35.0;
    public const DEFAULT_MAX_INTERVAL = 45.0;

    /**
     * @param float $minInterval piso da espera entre dois envios do mesmo número, em segundos
     * @param float $maxInterval teto da espera; o valor real é sorteado na faixa
     * @param int   $maxAttempts quantas vezes uma requisição é tentada no total; só 429 e 5xx repetem
     * @param float $backoffBase base da espera entre tentativas (`base ** tentativa`)
     * @param bool  $respectRetryAfter usa o cabeçalho `Retry-After` quando a API manda um
     * @param float $timeout teto de cada requisição HTTP, em segundos
     */
    public function __construct(
        public readonly float $minInterval = self::DEFAULT_MIN_INTERVAL,
        public readonly float $maxInterval = self::DEFAULT_MAX_INTERVAL,
        public readonly int $maxAttempts = 4,
        public readonly float $backoffBase = 2.0,
        public readonly bool $respectRetryAfter = true,
        public readonly float $timeout = 30.0,
    ) {
        if ($minInterval < 0 || $maxInterval < 0) {
            throw new InvalidArgumentException('intervalo de envio não pode ser negativo');
        }
        if ($maxInterval < $minInterval) {
            throw new InvalidArgumentException('maxInterval não pode ser menor que minInterval');
        }
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('maxAttempts precisa ser pelo menos 1');
        }
        if ($timeout <= 0) {
            throw new InvalidArgumentException('timeout precisa ser maior que zero');
        }
    }

    /** `false` quando a faixa é zero, isto é, quando o ritmo foi desligado. */
    public function paced(): bool
    {
        return $this->maxInterval > 0;
    }
}
