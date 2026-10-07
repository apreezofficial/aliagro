<?php

namespace App\Core;

class ValidationException extends \RuntimeException
{
    /** @param array<string, string[]> $errors */
    public function __construct(public array $errors)
    {
        parent::__construct('Validation failed.');
    }
}
