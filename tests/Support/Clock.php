<?php

declare(strict_types=1);

namespace SophiaLabs\CronosFlow\Tests\Support;

/**
 * Relógio falso: só anda quando alguém dorme.
 *
 * Um teste honesto do intervalo de 35 segundos levaria 35 segundos. O relógio
 * injetado faz o teste observar **o quanto o SDK mandou dormir**, que é o que
 * importa.
 */
final class Clock
{
    public float $now = 1000.0;

    /** @var list<float> */
    public array $slept = [];

    public function sleep(float $seconds): void
    {
        $this->slept[] = $seconds;
        $this->now += $seconds;
    }

    public function monotonic(): float
    {
        return $this->now;
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }
}
