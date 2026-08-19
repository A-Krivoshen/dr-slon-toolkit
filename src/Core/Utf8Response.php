<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Core;

final class Utf8Response
{
    public static function normalize(string $body): string
    {
        if (str_starts_with($body, "\xEF\xBB\xBF")) {
            $body = substr($body, 3);
        }

        $body = str_replace(["\r\n", "\r"], "\n", $body);

        if (function_exists('wp_check_invalid_utf8')) {
            $checked = wp_check_invalid_utf8($body, true);
            $body = is_string($checked) ? $checked : $body;
        }

        return $body;
    }

    public static function send(string $body, string $content_type, string $request_method): void
    {
        $body = self::normalize($body);

        header('Content-Type: ' . $content_type . '; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        status_header(200);

        if ($request_method === 'HEAD') {
            exit;
        }

        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- UTF-8 plain text / markdown for agents.
        exit;
    }
}
