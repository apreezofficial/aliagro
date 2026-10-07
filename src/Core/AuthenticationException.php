<?php

namespace App\Core;

class AuthenticationException extends \RuntimeException
{
    public function __construct(string $message = 'Unauthenticated.')
    {
        parent::__construct($message);
    }
}
