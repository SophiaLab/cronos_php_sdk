<?php

declare(strict_types=1);

namespace SophiaLabs\CronosFlow\Exception;

/** 5xx fora do 502: falha do lado da API, já repetida pelo SDK. */
final class ServerException extends CronosException
{
}
