<?php

declare(strict_types=1);

namespace SophiaLabs\CronosFlow\Exception;

use RuntimeException;

/**
 * Base de tudo o que o SDK levanta.
 *
 * Capture esta para pegar qualquer falha vinda da API ou do caminho até ela.
 * As filhas existem porque o mesmo status HTTP cobre situações que pedem
 * reações opostas: o `403` da API é tanto "a chave não vale" quanto "a cota do
 * dia acabou" quanto "a conta está bloqueada" — a primeira é erro de
 * configuração, a segunda passa à meia-noite, a terceira exige falar com o
 * suporte. Ninguém deveria precisar ler string de mensagem para decidir isso.
 *
 * Nenhuma exceção carrega a chave de API.
 */
class CronosException extends RuntimeException
{
    /**
     * @param mixed $detail o `detail` cru da resposta, quando houver
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly mixed $detail = null,
    ) {
        parent::__construct($message, $status ?? 0);
    }
}
