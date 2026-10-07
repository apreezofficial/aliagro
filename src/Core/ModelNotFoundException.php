<?php

namespace App\Core;

class ModelNotFoundException extends \RuntimeException
{
    public function __construct(string $message = 'Resource not found.')
    {
        parent::__construct($message);
    }
}
