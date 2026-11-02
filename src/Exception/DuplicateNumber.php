<?php

declare(strict_types=1);

namespace SophiaLabs\CronosFlow\Exception;

/** 409 `duplicate_number`: o número já está cadastrado na plataforma. */
final class DuplicateNumber extends ConflictException
{
}
