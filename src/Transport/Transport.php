<?php

declare(strict_types=1);

namespace SophiaLabs\CronosFlow\Transport;

/**
 * Por onde a requisição sai.
 *
 * Existe por duas razões. A primeira é teste: o dublê entrega a resposta que o
 * caso precisa e guarda o que o SDK montou, sem rede. A segunda é integração:
 * quem já tem um cliente HTTP configurado no projeto — com proxy corporativo,
 * log, métrica — implementa esta interface e passa no construtor, em vez de
 * ter dois clientes HTTP com configurações diferentes no mesmo processo.
 */
interface Transport
{
    public function send(Request $request): Response;

    /**
     * Lê uma resposta `text/event-stream` linha a linha.
     *
     * @param callable(string): bool $onLine devolve `false` para encerrar o stream
     */
    public function stream(Request $request, callable $onLine): void;
}
