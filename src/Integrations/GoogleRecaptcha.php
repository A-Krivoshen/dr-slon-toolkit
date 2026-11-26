<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Integrations;

final class GoogleRecaptcha
{
    public const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';
    public const SCRIPT_URL = 'https://www.google.com/recaptcha/api.js';
    public const TOKEN_FIELD = 'g-recaptcha-response';

    /**
     * Checkbox reCAPTCHA v2. A missing token fails closed. A token that was
     * sent while Google did not answer fails open, same as Yandex.
     *
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
            self::VERIFY_URL,
            [
                'timeout' => 3,
                'body'    => [
                    'secret'   => $secret,
                    'response' => $token,
                    'remoteip' => $ip,
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
        $success = is_array($decoded) && ($decoded['success'] ?? false) === true;

        return [
            'ok'        => $success,
            'fail_open' => false,
            'message'   => $success ? 'ok' : 'failed',
        ];
    }

    public function posted_token(): string
    {
        if (! isset($_POST[self::TOKEN_FIELD]) || ! is_string($_POST[self::TOKEN_FIELD])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- login form token from the reCAPTCHA widget.
            return '';
        }

        return sanitize_text_field(wp_unslash($_POST[self::TOKEN_FIELD])); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- login form token from the reCAPTCHA widget.
    }
}
