<?php

namespace App\Core;

final class Response
{
    public function __construct(
        public mixed $data = null,
        public int $status = 200,
        public array $headers = [],
    ) {}

    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        return new self($data, $status, $headers);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self(null, $status, ['Location' => $url]);
    }

    public function withHeaders(array $headers): self
    {
        $this->headers = $headers + $this->headers;
        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }
        }

        if ($this->data !== null && $this->status !== 204) {
            if (is_string($this->data) && str_starts_with($this->headers['Content-Type'] ?? '', 'text/html')) {
                echo $this->data;
                return;
            }
            if (!isset($this->headers['Content-Type'])) {
                header('Content-Type: application/json');
            }
            echo json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }
    }
}
