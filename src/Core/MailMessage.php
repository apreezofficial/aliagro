<?php

namespace App\Core;

/**
 * Fluent builder mirroring Illuminate's MailMessage
 * (subject / greeting / line / action / outro) with a simple responsive HTML layout.
 */
final class MailMessage
{
    public string $subject = '';
    private ?string $greeting = null;
    /** @var array<int, array{type:string, text:string, url?:string}> */
    private array $parts = [];

    public function subject(string $subject): self { $this->subject = $subject; return $this; }
    public function greeting(string $greeting): self { $this->greeting = $greeting; return $this; }
    public function line(string $text): self { $this->parts[] = ['type' => 'line', 'text' => $text]; return $this; }
    public function action(string $text, string $url): self { $this->parts[] = ['type' => 'action', 'text' => $text, 'url' => $url]; return $this; }

    public function toText(): string
    {
        $out = ($this->greeting ?? 'Hello!') . "\n\n";
        foreach ($this->parts as $p) {
            $out .= $p['type'] === 'action'
                ? "{$p['text']}: {$p['url']}\n\n"
                : str_replace('**', '', $p['text']) . "\n\n";
        }
        return $out . "Regards,\n" . config('app.name');
    }

    public function toHtml(): string
    {
        $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        // Escape first, then re-introduce **bold** markers as <strong>.
        $md = fn(string $s) => preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $e($s));

        $body = '<h1 style="font-size:18px;margin:0 0 16px;color:#1f2937">' . $e($this->greeting ?? 'Hello!') . '</h1>';
        foreach ($this->parts as $p) {
            if ($p['type'] === 'line') {
                $body .= '<p style="font-size:15px;line-height:1.6;color:#374151;margin:0 0 16px">' . $md($p['text']) . '</p>';
                continue;
            }
            $body .= '<p style="text-align:center;margin:24px 0"><a href="' . $e($p['url']) . '" '
                . 'style="background:#16a34a;color:#fff;text-decoration:none;padding:12px 24px;border-radius:6px;display:inline-block;font-weight:600">'
                . $e($p['text']) . '</a></p>'
                . '<p style="font-size:12px;color:#6b7280;margin:0 0 16px;word-break:break-all">If you\'re having trouble clicking the button, copy and paste this URL into your browser: '
                . '<a href="' . $e($p['url']) . '" style="color:#16a34a">' . $e($p['url']) . '</a></p>';
        }
        $body .= '<p style="font-size:15px;color:#374151;margin:24px 0 0">Regards,<br>' . $e((string) config('app.name')) . '</p>';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif">'
            . '<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 12px">'
            . '<table width="100%" style="max-width:600px" cellpadding="0" cellspacing="0">'
            . '<tr><td style="text-align:center;padding:0 0 16px;font-size:22px;font-weight:700;color:#16a34a">' . $e((string) config('app.name')) . '</td></tr>'
            . '<tr><td style="background:#fff;border-radius:8px;padding:32px">' . $body . '</td></tr>'
            . '<tr><td style="text-align:center;padding:16px;font-size:12px;color:#9ca3af">&copy; ' . date('Y') . ' ' . $e((string) config('app.name')) . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }
}
