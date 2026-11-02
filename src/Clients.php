<?php

declare(strict_types=1);

namespace SophiaLabs\CronosFlow;

use SophiaLabs\CronosFlow\Model\Client;
use SophiaLabs\CronosFlow\Model\QrEvent;
use SophiaLabs\CronosFlow\Model\SessionStatus;

/** Os números cadastrados na conta — os que enviam. */
final class Clients
{
    public function __construct(private readonly CronosClient $cronos)
    {
    }

    /**
     * Lista os números da conta. `$q` filtra por nome ou número.
     *
     * @return list<Client>
     */
    public function list(?string $q = null): array
    {
        $payload = $this->cronos->request('GET', '/api/clients', query: ['q' => $q]);
        $rows = is_array($payload) ? $payload : [];

        return array_values(array_map(
            static fn (array $row): Client => Client::fromApi($row),
            array_filter($rows, 'is_array'),
        ));
    }

    /**
     * Cadastra um número.
     *
     * Cadastrar não conecta: a sessão sobe quando alguém lê o QR code, pela
     * plataforma ou por `qrEvents()`.
     *
     * @param list<string>|null $messageDefault
     */
    public function create(
        string $name,
        string $number,
        ?array $messageDefault = null,
        ?int $waitMinDefault = null,
        ?int $waitMaxDefault = null,
    ): Client {
        Validation::waitWindow($waitMinDefault, $waitMaxDefault);

        $body = ['name' => $name, 'number' => Validation::destination($number)];
        if ($messageDefault !== null) {
            $body['message_default'] = array_values($messageDefault);
        }
        if ($waitMinDefault !== null) {
            $body['wait_min_default'] = $waitMinDefault;
        }
        if ($waitMaxDefault !== null) {
            $body['wait_max_default'] = $waitMaxDefault;
        }

        $payload = $this->cronos->request('POST', '/api/clients', json: $body);

        return Client::fromApi(is_array($payload) ? $payload : []);
    }

    /**
     * Altera só os campos enviados; os ausentes ficam como estão.
     *
     * @param array<string, mixed> $fields
     */
    public function update(int|string $clientId, array $fields): Client
    {
        Validation::waitWindow(
            isset($fields['wait_min_default']) ? (int) $fields['wait_min_default'] : null,
            isset($fields['wait_max_default']) ? (int) $fields['wait_max_default'] : null,
        );
        if (isset($fields['number'])) {
            $fields['number'] = Validation::destination((string) $fields['number']);
        }

        $path = '/api/clients/' . Validation::clientId($clientId);
        $payload = $this->cronos->request('PATCH', $path, json: $fields);

        return Client::fromApi(is_array($payload) ? $payload : []);
    }

    /**
     * Apaga o número da conta.
     *
     * A API responde 204 mesmo para id que não existe ou não é seu: ela não
     * confirma quais ids existem. Por isso aqui também não há retorno.
     */
    public function delete(int|string $clientId): void
    {
        $this->cronos->request('DELETE', '/api/clients/' . Validation::clientId($clientId));
    }

    /** Diz se a sessão daquele número está conectada agora. */
    public function status(int|string $clientId): SessionStatus
    {
        $path = '/api/clients/' . Validation::clientId($clientId) . '/status';
        $payload = $this->cronos->request('GET', $path);

        return SessionStatus::fromApi(is_array($payload) ? $payload : []);
    }

    /**
     * Abre o stream de conexão e entrega cada evento ao callback, na hora.
     *
     * Use para conectar o número sem abrir a plataforma: mostre o conteúdo de
     * `$evento->qr()` como QR code e leia com o aparelho.
     *
     *     $cronos->clients()->onQrEvent('1', function (QrEvent $evento): bool {
     *         if ($evento->qr() !== null) {
     *             mostrar($evento->qr());
     *         }
     *
     *         return ! $evento->isLoggedIn();  // false fecha o stream
     *     });
     *
     * É callback, e não `Generator`, de propósito: o QR precisa aparecer na
     * tela **enquanto** o stream está aberto. Um gerador alimentado por
     * callback só entregaria os eventos depois de o stream fechar — e aí o
     * código do QR já teria expirado, que é o oposto de servir para alguma
     * coisa.
     *
     * O stream fecha sozinho em 120 segundos. Devolva `false` assim que o login
     * acontecer: cada stream aberto segura uma vaga sua (3 por chave) e uma do
     * servidor.
     *
     * @param callable(QrEvent): bool $onEvent
     */
    public function onQrEvent(int|string $clientId, callable $onEvent): void
    {
        $path = '/api/clients/' . Validation::clientId($clientId) . '/qr/stream';
        $this->cronos->stream($path, $onEvent);
    }
}
