<?php
/**
 * HTTP response helpers (JSON-first API).
 */
final class Response
{
    public static function json($data, int $status = 200): void
    {
        if (PHP_SAPI !== 'cli') {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo em_json_encode($data);
        exit;
    }

    public static function ok($data = null): void
    {
        self::json(['ok' => true, 'data' => $data]);
    }

    public static function error(string $message, int $status = 400, array $extra = []): void
    {
        $body = array_merge(['ok' => false, 'error' => $message], $extra);
        self::json($body, $status);
    }

    public static function empty(int $status = 204): void
    {
        if (PHP_SAPI !== 'cli') {
            http_response_code($status);
            header('Cache-Control: no-store');
        }
        exit;
    }
}
