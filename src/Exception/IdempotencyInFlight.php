<?php

declare(strict_types=1);

namespace SophiaLabs\CronosFlow\Exception;

/**
 * 409: outra requisição com a mesma `Idempotency-Key` ainda está em voo.
 *
 * Quem cai aqui deve **esperar**, não trocar a chave: trocar a chave é mandar a
 * mensagem duas vezes.
 */
final class IdempotencyInFlight extends ConflictException
{
}
