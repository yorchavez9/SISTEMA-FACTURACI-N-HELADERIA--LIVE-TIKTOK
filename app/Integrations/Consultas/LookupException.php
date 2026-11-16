<?php

namespace App\Integrations\Consultas;

use RuntimeException;

class LookupException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $notFound = false)
    {
        parent::__construct($message);
    }
}
