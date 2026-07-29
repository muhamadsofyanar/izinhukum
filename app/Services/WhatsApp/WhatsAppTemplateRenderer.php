<?php

namespace App\Services\WhatsApp;

class WhatsAppTemplateRenderer
{
    public function render(string $body, array $variables): string
    {
        $normalized = [];
        foreach ($variables as $key => $value) {
            $normalized[(string) $key] = is_scalar($value) || $value === null
                ? trim((string) ($value ?? ''))
                : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return trim((string) preg_replace_callback(
            '/{{\s*([a-zA-Z0-9_]+)\s*}}/',
            fn (array $matches): string => $normalized[$matches[1]] ?? '-',
            $body,
        ));
    }
}
