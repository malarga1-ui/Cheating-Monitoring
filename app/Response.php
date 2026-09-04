<?php
/**
 * HTTP response helpers (JSON-first API).
 */
final class Response
{
    public static function json($data, int $status = 200): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (PHP_SAPI !== 'cli') {
            http_response_code($status);
            $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
            header("Access-Control-Allow-Origin: $origin");
            header('Access-Control-Allow-Credentials: true');
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
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (PHP_SAPI !== 'cli') {
            http_response_code($status);
            $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
            header("Access-Control-Allow-Origin: $origin");
            header('Access-Control-Allow-Credentials: true');
            header('Cache-Control: no-store');
        }
        exit;
    }
}
