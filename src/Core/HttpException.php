<?php

namespace App\Core;

/**
 * Thrown anywhere to short-circuit with a JSON error:
 * {"message": "...", ...$extra} and the given HTTP status.
 */
class HttpException extends \RuntimeException
{
    public function __construct(public int $status, string $message = '', public array $extra = [], public array $headers = [])
    {
        parent::__construct($message);
    }
}
