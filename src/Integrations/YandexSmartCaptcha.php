<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Integrations;

final class YandexSmartCaptcha
{
    public const VALIDATE_URL = 'https://smartcaptcha.cloud.yandex.ru/validate';
    public const SCRIPT_URL = 'https://smartcaptcha.cloud.yandex.ru/captcha.js';
    public const TOKEN_FIELD = 'smart-token';

    /**
     * @return array{ok:bool,fail_open:bool,message:string}
     */
    public function verify(string $secret, string $token, string $ip): array
    {
        if ($secret === '' || $token === '') {
            return [
                'ok'        => false,
                'fail_open' => false,
                'message'   => 'missing',
            ];
        }

        $response = wp_safe_remote_post(
            self::VALIDATE_URL,
            [
                'timeout' => 3,
                'body'    => [
                    'secret' => $secret,
                    'token'  => $token,
                    'ip'     => $ip,
                ],
            ]
        );

        if (is_wp_error($response)) {
            return [
                'ok'        => true,
                'fail_open' => true,
                'message'   => $response->get_error_message(),
            ];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if ($code !== 200) {
            return [
                'ok'        => true,
                'fail_open' => true,
                'message'   => 'http_' . $code,
            ];
        }

        $decoded = json_decode($body, true);
        $status = is_array($decoded) ? (string) ($decoded['status'] ?? '') : '';

        return [
            'ok'        => $status === 'ok',
            'fail_open' => false,
            'message'   => is_array($decoded) ? (string) ($decoded['message'] ?? $status) : 'invalid_json',
        ];
    }

    public function posted_token(): string
    {
        if (! isset($_POST[self::TOKEN_FIELD]) || ! is_string($_POST[self::TOKEN_FIELD])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- login form token from Yandex widget.
            return '';
        }

        return sanitize_text_field(wp_unslash($_POST[self::TOKEN_FIELD])); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- login form token from Yandex widget.
    }
}
