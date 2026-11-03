<?php

declare(strict_types=1);

namespace SophiaLabs\CronosFlow;

use InvalidArgumentException;
use SophiaLabs\CronosFlow\Exception\CronosException;
use SophiaLabs\CronosFlow\Exception\ErrorFactory;
use SophiaLabs\CronosFlow\Exception\TransportException;
use SophiaLabs\CronosFlow\Model\SendResult;
use SophiaLabs\CronosFlow\Transport\CurlTransport;
use SophiaLabs\CronosFlow\Transport\Multipart;
use SophiaLabs\CronosFlow\Transport\Request;
use SophiaLabs\CronosFlow\Transport\Response;
use SophiaLabs\CronosFlow\Transport\Transport;

/**
 * O cliente da API pública do CronosFlow.
 *
 *     $cronos = new CronosClient(getenv('CRONOS_API_KEY'));
 *
 *     if ($cronos->clients()->status('1')->loggedIn) {
 *         $cronos->messages()->text('1', '+5511999999999', 'Seu pedido saiu para entrega.');
 *     }
 *
 * Desenho em uma frase: **toda mensagem passa por um funil só** (`send()`), e é
 * nesse funil que moram as quatro coisas que separam um SDK de um `curl_exec`:
 *
 * 1. o **ritmo** entre envios do mesmo número, que é o que protege o chip;
 * 2. a **`Idempotency-Key`** gerada uma vez por mensagem e repetida em toda
 *    tentativa, que impede a mesma mensagem de sair duas vezes quando a rede
 *    falha no meio;
 * 3. a **repetição com espera crescente**, só em 429 e 5xx — repetir um 422 de
 *    validação não conserta nada e ainda gasta o teto por minuto;
 * 4. a **tradução do erro**, para quem integra reagir num `catch`.
 *
 * A chave de API nunca é escrita em log, em `__debugInfo` ou em mensagem de
 * erro: ela vale como senha da conta inteira e deve viver no ambiente do
 * servidor.
 */
final class CronosClient
{
    public const DEFAULT_BASE_URL = 'https://api.flow.sophialabs.com.br';
    public const VERSION = '0.1.0';

    private const RETRIABLE = [429, 500, 502, 503, 504];

    public readonly string $baseUrl;

    private readonly string $apiKey;
    private readonly Transport $transport;
    private SendPacer $pacer;
    private readonly Clients $clients;
    private readonly Messages $messages;

    /** @var callable(float): void */
    private $sleep;

    /**
     * @param string      $apiKey        a chave `cf_...`. Leia do ambiente do servidor; ela vale como senha da conta
     * @param string      $baseUrl       outra URL da API, para quem roda contra um ambiente próprio
     * @param SendPolicy|null $policy    o ritmo e a política de repetição
     * @param Transport|null  $transport transporte próprio (teste, proxy corporativo, log)
     * @param bool        $allowInsecure permite `http://`; só para desenvolvimento local
     */
    public function __construct(
        string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        public readonly SendPolicy $policy = new SendPolicy(),
        ?Transport $transport = null,
        bool $allowInsecure = false,
        ?callable $sleep = null,
    ) {
        if (trim($apiKey) === '') {
            throw new InvalidArgumentException('apiKey vazia. A chave sai do painel, em Chaves de API.');
        }

        $cleanedUrl = rtrim($baseUrl, '/');
        if (! str_starts_with($cleanedUrl, 'https://') && ! $allowInsecure) {
            throw new InvalidArgumentException(
                "baseUrl sem https: {$cleanedUrl}. A chave viajaria em claro. "
                . 'Em desenvolvimento local, passe allowInsecure: true.',
            );
        }

        $this->apiKey = trim($apiKey);
        $this->baseUrl = $cleanedUrl;
        $this->transport = $transport ?? new CurlTransport();
        $this->pacer = new SendPacer($this->policy);
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
        $this->clients = new Clients($this);
        $this->messages = new Messages($this);
    }

    /** Os números cadastrados na conta: o que envia. */
    public function clients(): Clients
    {
        return $this->clients;
    }

    /** As rotas de envio. Toda uma delas passa pelo ritmo e pela idempotência. */
    public function messages(): Messages
    {
        return $this->messages;
    }

    /** Troca o controlador de ritmo. Serve ao teste, que injeta relógio próprio. */
    public function withPacer(SendPacer $pacer): self
    {
        $this->pacer = $pacer;

        return $this;
    }

    /**
     * O que aparece em `var_dump`: a chave entra só pelo prefixo.
     *
     * `var_dump` de objeto acaba em log de erro e em tela de debug de
     * framework. Sem isto, a chave da conta inteira iria junto.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        $parts = explode('_', $this->apiKey);
        $prefix = count($parts) >= 2 ? "{$parts[0]}_{$parts[1]}_***" : substr($this->apiKey, 0, 6) . '***';

        return ['baseUrl' => $this->baseUrl, 'apiKey' => $prefix];
    }

    // ------------------------------------------------------------------
    // Funil único
    // ------------------------------------------------------------------

    /**
     * Uma chamada, repetindo só o que vale repetir.
     *
     * A mesma `Idempotency-Key` vai em todas as tentativas — é isso que faz a
     * retentativa de rede devolver a resposta guardada em vez de enviar de novo.
     *
     * @param array<string, mixed>|null                                         $json
     * @param array<string, string>                                             $fields
     * @param array<string, array{name: string, content: string, type: string}> $files
     * @param array<string, string|null>                                        $query
     */
    public function request(
        string $method,
        string $path,
        ?array $json = null,
        array $fields = [],
        array $files = [],
        array $query = [],
        ?string $idempotencyKey = null,
    ): mixed {
        $url = $this->baseUrl . $path;
        $filtered = array_filter($query, static fn ($value) => $value !== null && $value !== '');
        if ($filtered !== []) {
            $url .= '?' . http_build_query($filtered);
        }

        $headers = [
            'X-API-Key' => $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'cronos-flow-sdk-php/' . self::VERSION,
        ];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $body = null;
        if ($json !== null) {
            $body = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $headers['Content-Type'] = 'application/json';
        } elseif ($fields !== [] || $files !== []) {
            $multipart = Multipart::build($fields, $files);
            $body = $multipart['body'];
            $headers['Content-Type'] = $multipart['contentType'];
        }

        $request = new Request($method, $url, $headers, $body, $this->policy->timeout);
        $lastError = null;

        for ($attempt = 1; $attempt <= $this->policy->maxAttempts; $attempt++) {
            try {
                $response = $this->transport->send($request);
            } catch (TransportException $error) {
                $lastError = $error;
                if ($attempt < $this->policy->maxAttempts) {
                    $this->waitBeforeRetry($attempt, null);

                    continue;
                }

                throw $error;
            }

            if (in_array($response->status, self::RETRIABLE, true) && $attempt < $this->policy->maxAttempts) {
                $this->waitBeforeRetry($attempt, $response);

                continue;
            }

            return $this->payloadOrThrow($response);
        }

        throw $lastError ?? new TransportException('não foi possível falar com a API.');
    }

    /**
     * Funil de toda mensagem: espera o ritmo, gera a chave, chama, traduz.
     *
     * A chave de idempotência é gerada **antes** da primeira tentativa, uma por
     * mensagem. Gerar uma por tentativa seria o mesmo que não ter nenhuma.
     *
     * @param array<string, mixed>|null                                         $json
     * @param array<string, string>                                             $fields
     * @param array<string, array{name: string, content: string, type: string}> $files
     * @param array<string, string|null>                                        $query
     */
    public function send(
        string $path,
        string $channel,
        ?array $json = null,
        array $fields = [],
        array $files = [],
        array $query = [],
        ?string $idempotencyKey = null,
    ): SendResult {
        $key = $idempotencyKey ?? self::newIdempotencyKey();
        $this->pacer->waitFor($channel);
        $payload = $this->request('POST', $path, $json, $fields, $files, $query, $key);

        return SendResult::fromApi(is_array($payload) ? $payload : [], $key);
    }

    /**
     * Lê um `text/event-stream`, entregando cada evento ao callback.
     *
     * O parser é pequeno porque o formato daqui é: um `data:` com JSON por
     * evento, e um `event:` só no fim (`timeout` ou `error`).
     *
     * @param callable(Model\QrEvent): bool $onEvent devolve `false` para fechar o stream
     */
    public function stream(string $path, callable $onEvent): void
    {
        $headers = [
            'X-API-Key' => $this->apiKey,
            'Accept' => 'text/event-stream',
            'User-Agent' => 'cronos-flow-sdk-php/' . self::VERSION,
        ];
        $request = new Request('GET', $this->baseUrl . $path, $headers, null, $this->policy->timeout, true);
        $eventName = 'message';

        $this->transport->stream($request, function (string $line) use (&$eventName, $onEvent): bool {
            $line = trim($line);
            if ($line === '') {
                return true;
            }
            if (str_starts_with($line, 'event:')) {
                $eventName = trim(substr($line, 6));

                return true;
            }
            if (! str_starts_with($line, 'data:')) {
                return true;
            }

            $body = trim(substr($line, 5));
            $data = json_decode($body, true);
            if (! is_array($data)) {
                $data = ['raw' => $body];
            }
            $kind = (string) ($data['type'] ?? $eventName);
            $keepGoing = $onEvent(new Model\QrEvent($kind, $data));

            return $keepGoing !== false && ! in_array($kind, ['timeout', 'error'], true);
        });
    }

    public static function newIdempotencyKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function waitBeforeRetry(int $attempt, ?Response $response): void
    {
        $wait = $this->policy->backoffBase ** $attempt + (mt_rand() / mt_getrandmax());

        if ($response !== null && $this->policy->respectRetryAfter) {
            $header = $response->header('Retry-After');
            if ($header !== null && is_numeric($header)) {
                $wait = max($wait, (float) $header);
            }
        }

        ($this->sleep)($wait);
    }

    private function payloadOrThrow(Response $response): mixed
    {
        $retryAfter = $response->header('Retry-After');
        $payload = $response->json();

        if ($response->status >= 400) {
            $detail = is_array($payload) && array_key_exists('detail', $payload)
                ? $payload['detail']
                : ($payload ?? mb_substr($response->body, 0, 200));

            throw ErrorFactory::from(
                $response->status,
                $detail,
                is_numeric($retryAfter) ? (float) $retryAfter : null,
            );
        }

        if ($response->status === 204 || $response->body === '') {
            return null;
        }

        if ($payload === null) {
            throw new CronosException("resposta não era JSON ({$response->status}).", $response->status);
        }

        return $payload;
    }
}
