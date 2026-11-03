<?php

declare(strict_types=1);

namespace SophiaLabs\CronosFlow\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SophiaLabs\CronosFlow\CronosClient;
use SophiaLabs\CronosFlow\Exception\AccountBlocked;
use SophiaLabs\CronosFlow\Exception\ApiKeyRejected;
use SophiaLabs\CronosFlow\Exception\AuthenticationException;
use SophiaLabs\CronosFlow\Exception\CronosException;
use SophiaLabs\CronosFlow\Exception\DailyLimitReached;
use SophiaLabs\CronosFlow\Exception\IdempotencyInFlight;
use SophiaLabs\CronosFlow\Exception\NotFoundException;
use SophiaLabs\CronosFlow\Exception\RateLimited;
use SophiaLabs\CronosFlow\Exception\SendFailed;
use SophiaLabs\CronosFlow\Exception\SubscriptionRequired;
use SophiaLabs\CronosFlow\Exception\UnsupportedMedia;
use SophiaLabs\CronosFlow\Exception\ValidationException;
use SophiaLabs\CronosFlow\SendPolicy;
use SophiaLabs\CronosFlow\Tests\Support\FakeTransport;
use SophiaLabs\CronosFlow\Transport\Response;

final class ClientTest extends TestCase
{
    // O prefixo zerado nao e enfeite: o CI varre o pacote de distribuicao
    // procurando chave de verdade, e chave de exemplo com prefixo plausivel
    // obrigaria a varredura a adivinhar qual das duas e qual.
    private const CHAVE = 'cf_00000000_naoeumachavereal0000000000000000';

    private const OGG = "OggS\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00OpusHead";
    private const MP4 = "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2";
    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR";
    private const WAV = "RIFF\x00\x00\x00\x00WAVEfmt ";

    /**
     * Ritmo desligado: aqui se verifica o que é montado e o que é traduzido.
     * A espera tem teste próprio, com relógio injetado.
     */
    private function montar(array $respostas = [], ?SendPolicy $policy = null): array
    {
        $transporte = new FakeTransport($respostas);
        $cliente = new CronosClient(
            apiKey: self::CHAVE,
            policy: $policy ?? new SendPolicy(minInterval: 0.0, maxInterval: 0.0, maxAttempts: 1),
            transport: $transporte,
            sleep: static fn (float $s) => null,
        );

        return [$cliente, $transporte];
    }

    // ============================================================
    // Credencial e endereço
    // ============================================================

    public function testEveryRequestCarriesTheKeyAndNeverLeaksIt(): void
    {
        [$cronos, $transporte] = $this->montar();

        $cronos->messages()->text('1', '+5511999999999', 'oi');

        self::assertSame(self::CHAVE, $transporte->requests[0]->headers['X-API-Key']);

        // `var_dump` de objeto acaba em log e em tela de debug de framework.
        $debug = $cronos->__debugInfo();
        self::assertSame('cf_00000000_***', $debug['apiKey']);
        self::assertStringNotContainsString('0a1b2c3d4e5f', json_encode($debug, JSON_THROW_ON_ERROR));
    }

    public function testAnHttpBaseUrlIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/https/');

        new CronosClient(self::CHAVE, 'http://api.exemplo.com');
    }

    public function testLocalhostIsAllowedWhenSaidOutLoud(): void
    {
        $cronos = new CronosClient(self::CHAVE, 'http://127.0.0.1:8000', allowInsecure: true);

        self::assertSame('http://127.0.0.1:8000', $cronos->baseUrl);
    }

    public function testAnEmptyKeyIsRefusedBeforeAnyRequest(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CronosClient('   ');
    }

    // ============================================================
    // O que o SDK monta
    // ============================================================

    public function testTextGoesToTheRightRouteWithTheRightBody(): void
    {
        [$cronos, $transporte] = $this->montar();

        $resultado = $cronos->messages()->text(1, '5511999999999', 'Seu pedido saiu.');

        $requisicao = $transporte->requests[0];
        self::assertStringEndsWith('/api/message/text', $requisicao->url);
        self::assertSame(
            ['client_id' => '1', 'to' => '+5511999999999', 'message' => 'Seu pedido saiu.'],
            $transporte->lastJson(),
        );
        self::assertTrue($resultado->accepted());
    }

    public function testTheIdempotencyKeyIsOnePerMessageAndComesBack(): void
    {
        [$cronos, $transporte] = $this->montar([
            new Response(200, '{"status":"queued"}'),
            new Response(200, '{"status":"queued"}'),
        ]);

        $primeira = $cronos->messages()->text('1', '+5511999999999', 'oi');
        $segunda = $cronos->messages()->text('1', '+5511999999999', 'oi');

        $chaves = array_map(static fn ($r) => $r->headers['Idempotency-Key'], $transporte->requests);
        self::assertNotSame($chaves[0], $chaves[1], 'duas mensagens sao duas chaves');
        self::assertSame($chaves[0], $primeira->idempotencyKey);
        self::assertSame($chaves[1], $segunda->idempotencyKey);
    }

    public function testTheSameKeyIsRepeatedAcrossRetries(): void
    {
        [$cronos, $transporte] = $this->montar(
            [new Response(429, '{"detail":"Limite de requisições excedido"}'), new Response(200, '{"status":"queued"}')],
            new SendPolicy(minInterval: 0.0, maxInterval: 0.0, maxAttempts: 2, backoffBase: 0.0),
        );

        $cronos->messages()->text('1', '+5511999999999', 'oi');

        $chaves = array_unique(array_map(static fn ($r) => $r->headers['Idempotency-Key'], $transporte->requests));
        self::assertCount(2, $transporte->requests);
        self::assertCount(1, $chaves, 'a tentativa seguinte tem que levar a mesma chave');
    }

    public function testPollSendsQuestionAndOptions(): void
    {
        [$cronos, $transporte] = $this->montar();

        $cronos->messages()->poll('1', '+5511999999999', ' Qual horário? ', [' Manhã ', 'Tarde']);

        self::assertSame(
            [
                'client_id' => '1',
                'to' => '+5511999999999',
                'question' => 'Qual horário?',
                'options' => ['Manhã', 'Tarde'],
            ],
            $transporte->lastJson(),
        );
    }

    public function testAudioGoesAsMultipartWithTheProvenExtension(): void
    {
        [$cronos, $transporte] = $this->montar();

        $cronos->messages()->audio('9', '+5511999999999', self::OGG);

        $corpo = $transporte->lastBody();
        self::assertStringEndsWith('/api/message/audio', $transporte->requests[0]->url);
        self::assertStringContainsString('name="audio"; filename="audio.ogg"', $corpo);
        self::assertStringContainsString('Content-Type: audio/ogg', $corpo);
        self::assertStringContainsString('name="client_id"', $corpo);
    }

    public function testImageGoesWithTheFormFieldNamesTheApiExpects(): void
    {
        [$cronos, $transporte] = $this->montar();

        $cronos->messages()->image('1', '+5511999999999', self::PNG, caption: 'olha');

        $corpo = $transporte->lastBody();
        self::assertStringContainsString('name="client_id_form"', $corpo);
        self::assertStringContainsString('name="to_form"', $corpo);
        self::assertStringContainsString('name="caption_form"', $corpo);
    }

    public function testDocumentPutsTheParametersInTheQueryString(): void
    {
        [$cronos, $transporte] = $this->montar();

        $cronos->messages()->document('1', '+5511999999999', '%PDF-1.4 conteudo', filename: 'boleto.pdf');

        $url = $transporte->requests[0]->url;
        self::assertStringContainsString('/api/message/file?', $url);
        self::assertStringContainsString('client_id=1', $url);
        self::assertStringContainsString('media_type=DOCUMENT', $url);
    }

    // ============================================================
    // Recusa local: nem sai da máquina
    // ============================================================

    /** @return list<array{string}> */
    public static function destinosInvalidos(): array
    {
        return [['1234'], ['5511999999999x'], [''], ['+0511999999999']];
    }

    #[DataProvider('destinosInvalidos')]
    public function testADestinationOutsideE164NeverReachesTheNetwork(string $destino): void
    {
        [$cronos, $transporte] = $this->montar();

        try {
            $cronos->messages()->text('1', $destino, 'oi');
            self::fail('o destino invalido tinha que ser recusado');
        } catch (ValidationException) {
            self::assertSame([], $transporte->requests, 'nao pode gastar rede nem cota');
        }
    }

    public function testANonNumericClientIdIsRefusedLocally(): void
    {
        [$cronos, $transporte] = $this->montar();

        $this->expectException(ValidationException::class);

        try {
            $cronos->messages()->text('sessao-de-outro', '+5511999999999', 'oi');
        } finally {
            self::assertSame([], $transporte->requests);
        }
    }

    public function testAudioThatWouldArriveAsADocumentIsRefusedBeforeTheUpload(): void
    {
        [$cronos, $transporte] = $this->montar();

        try {
            $cronos->messages()->audio('1', '+5511999999999', self::WAV);
            self::fail('o .wav tinha que ser recusado');
        } catch (UnsupportedMedia $erro) {
            self::assertStringContainsString('ffmpeg', $erro->getMessage());
            self::assertSame([], $transporte->requests);
        }
    }

    public function testVideoThatIsNotMp4IsRefusedBeforeTheUpload(): void
    {
        [$cronos, $transporte] = $this->montar();

        $this->expectException(UnsupportedMedia::class);

        try {
            $cronos->messages()->video('1', '+5511999999999', "\x1a\x45\xdf\xa3" . str_repeat("\x00", 16));
        } finally {
            self::assertSame([], $transporte->requests);
        }
    }

    public function testAFileAboveTheCapIsRefusedBeforeTheUpload(): void
    {
        [$cronos, $transporte] = $this->montar();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/16MB/');

        try {
            $cronos->messages()->audio('1', '+5511999999999', self::OGG . str_repeat("\x00", 16 * 1024 * 1024));
        } finally {
            self::assertSame([], $transporte->requests);
        }
    }

    /** @return list<array{string, list<string>}> */
    public static function enquetesInvalidas(): array
    {
        return [
            ['Vai?', ['Sim']],
            ['Vai?', ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm']],
            ['Vai?', ['Sim', 'Sim']],
            ['', ['Sim', 'Não']],
            [str_repeat('x', 256), ['Sim', 'Não']],
        ];
    }

    /** @param list<string> $opcoes */
    #[DataProvider('enquetesInvalidas')]
    public function testAPollTheWhatsAppWouldRefuseIsCaughtLocally(string $pergunta, array $opcoes): void
    {
        [$cronos, $transporte] = $this->montar();

        $this->expectException(ValidationException::class);

        try {
            $cronos->messages()->poll('1', '+5511999999999', $pergunta, $opcoes);
        } finally {
            self::assertSame([], $transporte->requests);
        }
    }

    // ============================================================
    // Tradução dos erros
    // ============================================================

    /** @return list<array{int, string, class-string}> */
    public static function recusas(): array
    {
        return [
            [401, 'X-API-Key ausente', AuthenticationException::class],
            [402, 'trial_expired', SubscriptionRequired::class],
            [403, 'API Key inválida', ApiKeyRejected::class],
            [403, 'account_blocked', AccountBlocked::class],
            [403, 'Você atingiu o limite diário de disparos: 720/720.', DailyLimitReached::class],
            [404, 'Cliente não encontrado', NotFoundException::class],
            [409, 'Requisição com esta Idempotency-Key ainda em andamento.', IdempotencyInFlight::class],
            [422, "Número 'to' inválido.", ValidationException::class],
            [502, 'Falha no envio.', SendFailed::class],
        ];
    }

    /** @param class-string $esperado */
    #[DataProvider('recusas')]
    public function testEachRefusalBecomesItsOwnException(int $status, string $detalhe, string $esperado): void
    {
        [$cronos] = $this->montar([
            new Response($status, json_encode(['detail' => $detalhe], JSON_THROW_ON_ERROR)),
        ]);

        try {
            $cronos->messages()->text('1', '+5511999999999', 'oi');
            self::fail("{$status} tinha que virar {$esperado}");
        } catch (CronosException $erro) {
            self::assertInstanceOf($esperado, $erro);
            self::assertSame($status, $erro->status);
        }
    }

    public function testA429ThatSurvivesEveryAttemptReachesTheCaller(): void
    {
        [$cronos, $transporte] = $this->montar(
            array_fill(0, 3, new Response(429, '{"detail":"Limite de requisições excedido"}')),
            new SendPolicy(minInterval: 0.0, maxInterval: 0.0, maxAttempts: 3, backoffBase: 0.0),
        );

        $this->expectException(RateLimited::class);

        try {
            $cronos->messages()->text('1', '+5511999999999', 'oi');
        } finally {
            self::assertCount(3, $transporte->requests);
        }
    }

    public function testAValidationErrorIsNeverRetried(): void
    {
        [$cronos, $transporte] = $this->montar(
            [new Response(422, '{"detail":"Número \'to\' inválido."}')],
            new SendPolicy(minInterval: 0.0, maxInterval: 0.0, maxAttempts: 4, backoffBase: 0.0),
        );

        $this->expectException(ValidationException::class);

        try {
            $cronos->messages()->text('1', '+5511999999999', 'oi');
        } finally {
            self::assertCount(1, $transporte->requests, 'repetir 422 so gasta o teto por minuto');
        }
    }

    // ============================================================
    // Clientes e lote
    // ============================================================

    public function testListingClientsReturnsTypedObjects(): void
    {
        [$cronos] = $this->montar([
            new Response(200, '[{"id":1,"name":"Comercial","number":"+5511999990000"}]'),
        ]);

        $numeros = $cronos->clients()->list();

        self::assertCount(1, $numeros);
        self::assertSame(1, $numeros[0]->id);
        self::assertSame('1', $numeros[0]->clientId(), 'o id vira texto para as rotas de mensagem');
    }

    public function testStatusSaysWhetherTheSessionIsConnected(): void
    {
        [$cronos, $transporte] = $this->montar([new Response(200, '{"client_id":"1","logged_in":true}')]);

        self::assertTrue($cronos->clients()->status(1)->loggedIn);
        self::assertStringEndsWith('/api/clients/1/status', $transporte->requests[0]->url);
    }

    public function testDeletingAClientAcceptsTheEmpty204(): void
    {
        [$cronos] = $this->montar([new Response(204, '')]);

        $cronos->clients()->delete('456');

        self::assertTrue(true, 'nao estourou com corpo vazio');
    }

    public function testSendManyKeepsGoingAfterABadDestination(): void
    {
        [$cronos] = $this->montar([
            new Response(200, '{"status":"queued"}'),
            new Response(200, '{"status":"queued"}'),
        ]);

        $resultados = iterator_to_array(
            $cronos->messages()->sendMany('1', ['+5511999999999', '1234', '+5511888888888'], 'oi'),
            false,
        );

        self::assertCount(3, $resultados);
        self::assertTrue($resultados[0][1]->accepted());
        self::assertInstanceOf(ValidationException::class, $resultados[1][1]);
        self::assertTrue($resultados[2][1]->accepted());
    }

    public function testSendManyStopsWhenTheDailyCapIsReached(): void
    {
        [$cronos, $transporte] = $this->montar([
            new Response(403, '{"detail":"Você atingiu o limite diário de disparos: 720/720."}'),
        ]);

        $resultados = iterator_to_array(
            $cronos->messages()->sendMany('1', ['+5511999999999', '+5511888888888'], 'oi'),
            false,
        );

        self::assertCount(1, $resultados, 'insistir depois do teto so queima o teto por minuto');
        self::assertInstanceOf(DailyLimitReached::class, $resultados[0][1]);
        self::assertCount(1, $transporte->requests);
    }
}
