<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Ошибка, которую можно показать клиенту как есть (400, 404, ...).
 */
final class HttpException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
