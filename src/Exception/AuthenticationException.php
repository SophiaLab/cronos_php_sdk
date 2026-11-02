<?php

declare(strict_types=1);

namespace SophiaLabs\CronosFlow\Exception;

/** 401: o cabeçalho `X-API-Key` não foi enviado. */
final class AuthenticationException extends CronosException
{
}
