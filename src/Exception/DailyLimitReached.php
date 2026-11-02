<?php

declare(strict_types=1);

namespace SophiaLabs\CronosFlow\Exception;

/**
 * 403 com o teto diário do plano.
 *
 * Não adianta repetir: a cota vira à meia-noite UTC (21h em Brasília). É o
 * único 403 que passa sozinho com o tempo.
 */
final class DailyLimitReached extends CronosException
{
}
