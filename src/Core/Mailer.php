<?php

namespace App\Core;

/**
 * Sends mail through one of three drivers (MAIL_MAILER):
 *   log  - write the message to the log (default for local dev)
 *   mail - PHP's mail()
 *   smtp - built-in SMTP client (SSL on 465, STARTTLS on 587, AUTH LOGIN/PLAIN)
 */
final class Mailer
{
    public static function send(string $toEmail, string $toName, MailMessage $message): void
    {
        $driver = strtolower((string) config('mail.mailer', 'log'));

        match ($driver) {
            'smtp'  => (new self())->viaSmtp($toEmail, $toName, $message),
            'mail'  => (new self())->viaMail($toEmail, $toName, $message),
            default => Logger::info('MAIL (log driver)', ['to' => $toEmail, 'subject' => $message->subject, 'body' => $message->toText()]),
        };
    }

    // ── MIME ─────────────────────────────────────────────────────────────

    private function encodeHeader(string $value): string
    {
        return preg_match('/[^\x20-\x7e]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
    }

    private function address(string $email, string $name): string
    {
        $name = str_replace(['"', "\r", "\n"], '', $name);
        return $name !== '' ? $this->encodeHeader($name) . " <{$email}>" : $email;
    }

    /** @return array{headers:string[], body:string} */
    private function build(string $toEmail, string $toName, MailMessage $m): array
    {
        $boundary = 'b_' . bin2hex(random_bytes(12));
        $from     = (string) config('mail.from.address');
        $fromName = (string) config('mail.from.name');
        $domain   = substr(strrchr($from, '@') ?: '@localhost', 1);

        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'From: ' . $this->address($from, $fromName),
            'To: ' . $this->address($toEmail, $toName),
            'Subject: ' . $this->encodeHeader(str_replace(["\r", "\n"], ' ', $m->subject)),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
        ];

        $part = fn(string $type, string $content) => "--{$boundary}\r\nContent-Type: {$type}; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($content));

        $body = $part('text/plain', $m->toText()) . $part('text/html', $m->toHtml()) . "--{$boundary}--\r\n";

        return ['headers' => $headers, 'body' => $body];
    }

    private function viaMail(string $toEmail, string $toName, MailMessage $m): void
    {
        $built = $this->build($toEmail, $toName, $m);
        // mail() adds To/Subject itself.
        $headers = array_values(array_filter($built['headers'], fn($h) => !preg_match('/^(To|Subject):/i', $h)));
        if (!mail($toEmail, $this->encodeHeader($m->subject), $built['body'], implode("\r\n", $headers))) {
            throw new \RuntimeException('mail() failed');
        }
    }

    // ── SMTP ─────────────────────────────────────────────────────────────

    /** @var resource */
    private $socket;

    private function viaSmtp(string $toEmail, string $toName, MailMessage $m): void
    {
        $host   = (string) config('mail.host');
        $port   = (int) config('mail.port');
        $enc    = strtolower((string) config('mail.encryption'));
        $user   = config('mail.username');
        $pass   = config('mail.password');
        $from   = (string) config('mail.from.address');
        $implicitTls = $enc === 'ssl' || $port === 465;

        $this->socket = @stream_socket_client(($implicitTls ? 'ssl://' : 'tcp://') . "{$host}:{$port}", $errno, $errstr, 15);
        if (!$this->socket) {
            throw new \RuntimeException("SMTP connect failed: {$errstr} ({$errno})");
        }
        stream_set_timeout($this->socket, 15);

        try {
            $this->expect(220);
            $ehlo = $this->command('EHLO ' . (gethostname() ?: 'localhost'), 250);

            if (!$implicitTls && ($enc === 'tls' || stripos($ehlo, 'STARTTLS') !== false)) {
                $this->command('STARTTLS', 220);
                if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('SMTP STARTTLS negotiation failed');
                }
                $ehlo = $this->command('EHLO ' . (gethostname() ?: 'localhost'), 250);
            }

            if ($user) {
                if (stripos($ehlo, 'AUTH') !== false && stripos($ehlo, 'LOGIN') === false && stripos($ehlo, 'PLAIN') !== false) {
                    $this->command('AUTH PLAIN ' . base64_encode("\0{$user}\0{$pass}"), 235);
                } else {
                    $this->command('AUTH LOGIN', 334);
                    $this->command(base64_encode((string) $user), 334);
                    $this->command(base64_encode((string) $pass), 235);
                }
            }

            $this->command("MAIL FROM:<{$from}>", 250);
            $this->command("RCPT TO:<{$toEmail}>", [250, 251]);
            $this->command('DATA', 354);

            $built = $this->build($toEmail, $toName, $m);
            $data  = implode("\r\n", $built['headers']) . "\r\n\r\n" . $built['body'];
            // dot-stuffing
            $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $data));
            $data = str_replace("\n", "\r\n", $data);
            fwrite($this->socket, $data . "\r\n.\r\n");
            $this->expect(250);

            $this->command('QUIT', 221);
        } finally {
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
        }
    }

    private function command(string $line, int|array $expect): string
    {
        fwrite($this->socket, $line . "\r\n");
        return $this->expect($expect);
    }

    private function expect(int|array $codes): string
    {
        $response = '';
        while (($line = fgets($this->socket, 1024)) !== false) {
            $response .= $line;
            // Multi-line replies use "250-"; the last line uses "250 ".
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, (array) $codes, true)) {
            throw new \RuntimeException('SMTP error: ' . trim($response));
        }
        return $response;
    }
}
