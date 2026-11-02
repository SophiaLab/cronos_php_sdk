<?php

declare(strict_types=1);

namespace SophiaLabs\CronosFlow;

/**
 * Guarda quando cada número enviou pela última vez e dorme o que falta.
 *
 * Esta é a parte do SDK que protege o número de quem integra. O teto da API é
 * 160 mensagens por minuto na rota de texto; o ritmo **seguro para o chip** é
 * outra coisa, e muito menor. Rajada pelo mesmo número é o padrão que o
 * WhatsApp usa para decidir que ali tem robô, e conta bloqueada não volta por
 * reclamação.
 *
 * Três decisões que valem explicação:
 *
 * - **a espera é por número, não global.** Quem opera cinco chips manda pelos
 *   cinco sem um esperar o outro; o que não pode é o mesmo chip disparar em
 *   sequência. Um relógio global transformaria cinco números em um só;
 * - **o intervalo é sorteado dentro da faixa.** Mensagem a cada 40 segundos
 *   cravados é tão artificial quanto rajada: o padrão aparece na janela de quem
 *   recebe;
 * - **a primeira sai na hora.** A espera é *entre* envios; cobrar a primeira
 *   faria todo script começar dormindo.
 *
 * O relógio e o `sleep` são injetáveis para o teste poder verificar a espera
 * sem gastar o tempo dela.
 */
final class SendPacer
{
    /** @var array<string, float> */
    private array $lastSend = [];

    /** @var callable(float): void */
    private $sleep;

    /** @var callable(): float */
    private $now;

    public function __construct(
        private readonly SendPolicy $policy,
        ?callable $sleep = null,
        ?callable $now = null,
    ) {
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
        $this->now = $now ?? static fn (): float => microtime(true);
    }

    /**
     * Espera o que falta para o próximo envio deste número e devolve quanto dormiu.
     *
     * O relógio é marcado **antes** do envio, com a hora em que ele vai
     * acontecer. Marcar depois faria o tempo da requisição entrar como se fosse
     * espera, e duas mensagens sairiam mais perto do que a faixa manda quando a
     * API demorasse a responder.
     */
    public function waitFor(string $channel): float
    {
        if (! $this->policy->paced()) {
            return 0.0;
        }

        $target = $this->draw();
        $now = ($this->now)();
        $last = $this->lastSend[$channel] ?? null;
        $waited = $last === null ? 0.0 : max(0.0, $target - ($now - $last));
        $this->lastSend[$channel] = $now + $waited;

        if ($waited > 0) {
            ($this->sleep)($waited);
        }

        return $waited;
    }

    /** Esquece o último envio de um número, ou de todos quando `$channel` é `null`. */
    public function forget(?string $channel = null): void
    {
        if ($channel === null) {
            $this->lastSend = [];

            return;
        }

        unset($this->lastSend[$channel]);
    }

    private function draw(): float
    {
        $min = $this->policy->minInterval;
        $max = $this->policy->maxInterval;
        if ($max <= $min) {
            return $min;
        }

        return $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
    }
}
