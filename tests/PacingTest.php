<?php

declare(strict_types=1);

namespace SophiaLabs\CronosFlow\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SophiaLabs\CronosFlow\CronosClient;
use SophiaLabs\CronosFlow\SendPacer;
use SophiaLabs\CronosFlow\SendPolicy;
use SophiaLabs\CronosFlow\Tests\Support\Clock;
use SophiaLabs\CronosFlow\Tests\Support\FakeTransport;

/**
 * O ritmo de envio, com relógio injetado.
 *
 * É a parte do SDK que protege o chip de quem integra, e a única que não dá
 * para verificar "rodando e vendo": um teste honesto do intervalo de 35
 * segundos levaria 35 segundos. O relógio falso faz o teste observar **o quanto
 * o SDK mandou dormir**.
 */
final class PacingTest extends TestCase
{
    private function pacer(SendPolicy $policy, Clock $relogio): SendPacer
    {
        return new SendPacer(
            $policy,
            sleep: $relogio->sleep(...),
            now: $relogio->monotonic(...),
        );
    }

    public function testTheDefaultMatchesThePlatform(): void
    {
        // 35 a 45 segundos é o que o disparo da plataforma usa quando o número
        // não define a própria janela.
        $policy = new SendPolicy();

        self::assertSame(35.0, $policy->minInterval);
        self::assertSame(45.0, $policy->maxInterval);
    }

    public function testTheFirstSendDoesNotWait(): void
    {
        $relogio = new Clock();
        $ritmo = $this->pacer(new SendPolicy(), $relogio);

        self::assertSame(0.0, $ritmo->waitFor('1'));
        self::assertSame([], $relogio->slept);
    }

    public function testTheSecondSendWaitsInsideTheRange(): void
    {
        $relogio = new Clock();
        $ritmo = $this->pacer(new SendPolicy(minInterval: 35.0, maxInterval: 45.0), $relogio);

        $ritmo->waitFor('1');
        $esperou = $ritmo->waitFor('1');

        self::assertGreaterThanOrEqual(35.0, $esperou);
        self::assertLessThanOrEqual(45.0, $esperou);
        self::assertSame([$esperou], $relogio->slept);
    }

    public function testTimeAlreadySpentIsDiscounted(): void
    {
        // Quem demorou 30 segundos montando a mensagem não espera mais 35.
        $relogio = new Clock();
        $ritmo = $this->pacer(new SendPolicy(minInterval: 35.0, maxInterval: 35.0), $relogio);

        $ritmo->waitFor('1');
        $relogio->advance(30.0);
        $esperou = $ritmo->waitFor('1');

        self::assertEqualsWithDelta(5.0, $esperou, 0.001);
    }

    public function testALongGapMeansNoWaitAtAll(): void
    {
        $relogio = new Clock();
        $ritmo = $this->pacer(new SendPolicy(minInterval: 35.0, maxInterval: 45.0), $relogio);

        $ritmo->waitFor('1');
        $relogio->advance(600.0);

        self::assertSame(0.0, $ritmo->waitFor('1'));
    }

    public function testTheClockIsPerNumber(): void
    {
        // Quem opera cinco chips manda pelos cinco sem um esperar o outro: a
        // proteção é por chip, e um relógio global custaria vazão sem motivo.
        $relogio = new Clock();
        $ritmo = $this->pacer(new SendPolicy(minInterval: 35.0, maxInterval: 45.0), $relogio);

        self::assertSame(0.0, $ritmo->waitFor('1'));
        self::assertSame(0.0, $ritmo->waitFor('2'));
        self::assertSame(0.0, $ritmo->waitFor('3'));
        self::assertSame([], $relogio->slept);
    }

    public function testTheIntervalIsDrawnNotFixed(): void
    {
        // Intervalo cravado é tão artificial quanto rajada: o padrão aparece na
        // janela de quem recebe.
        $relogio = new Clock();
        $ritmo = $this->pacer(new SendPolicy(minInterval: 30.0, maxInterval: 60.0), $relogio);

        for ($i = 0; $i < 12; $i++) {
            $ritmo->waitFor('1');
        }

        self::assertGreaterThan(1, count(array_unique($relogio->slept)), 'esperas iguais quer dizer intervalo fixo');
    }

    public function testPacingCanBeTurnedOffButOnlyOnPurpose(): void
    {
        $relogio = new Clock();
        $ritmo = $this->pacer(new SendPolicy(minInterval: 0.0, maxInterval: 0.0), $relogio);

        $ritmo->waitFor('1');
        $ritmo->waitFor('1');

        self::assertSame([], $relogio->slept);
    }

    public function testAnInvertedRangeIsRefusedWhenThePolicyIsBuilt(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SendPolicy(minInterval: 60.0, maxInterval: 30.0);
    }

    public function testTheClientPacesBetweenSendsToTheSameNumber(): void
    {
        $relogio = new Clock();
        $transporte = new FakeTransport();
        $cronos = new CronosClient(
            apiKey: 'cf_a1b2c3d4_' . str_repeat('0', 32),
            policy: new SendPolicy(minInterval: 35.0, maxInterval: 35.0, maxAttempts: 1),
            transport: $transporte,
        );
        $cronos->withPacer(new SendPacer(
            $cronos->policy,
            sleep: $relogio->sleep(...),
            now: $relogio->monotonic(...),
        ));

        $cronos->messages()->text('1', '+5511999999999', 'primeira');
        $cronos->messages()->text('1', '+5511999999999', 'segunda');
        $cronos->messages()->audio(
            '1',
            '+5511999999999',
            "OggS" . str_repeat("\x00", 23) . 'OpusHead',
        );

        // Texto e áudio dividem o mesmo relógio do número: o que o WhatsApp vê
        // é o chip mandando, não a rota.
        self::assertSame([35.0, 35.0], $relogio->slept);
    }
}
