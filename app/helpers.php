<?php
/**
 * Global helpers
 */

if (!function_exists('em_config')) {
    function em_config(string $key, $default = null) {
        static $config = null;
        if ($config === null) {
            $base = require __DIR__ . '/config.php';
            $candidates = [
                __DIR__ . '/../config.local.php',
                __DIR__ . '/config.local.php',
                dirname(__DIR__, 2) . '/config.local.php',
                dirname(__DIR__) . '/config.php',
            ];
            $local = [];
            foreach ($candidates as $cand) {
                if (is_file($cand)) {
                    $loaded = require $cand;
                    if (is_array($loaded)) {
                        $local = array_replace_recursive($local, $loaded);
                    }
                }
            }
            // Also check .env file if present
            $envCandidates = [__DIR__ . '/../.env', __DIR__ . '/.env', dirname(__DIR__, 2) . '/.env'];
            foreach ($envCandidates as $envFile) {
                if (is_file($envFile)) {
                    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if ($line === '' || str_starts_with($line, '#')) continue;
                        if (str_contains($line, '=')) {
                            [$k, $v] = explode('=', $line, 2);
                            $k = trim($k);
                            $v = trim($v, " \t\n\r\0\x0B\"'");
                            if (!getenv($k)) {
                                putenv("$k=$v");
                                $_ENV[$k] = $v;
                            }
                        }
                    }
                }
            }
            $config = array_replace_recursive($base, $local);
        }
        $keys = explode('.', $key);
        $val = $config;
        foreach ($keys as $k) {
            if (!is_array($val) || !array_key_exists($k, $val)) {
                return $default;
            }
            $val = $val[$k];
        }
        return $val;
    }
}

if (!function_exists('em_client_ip')) {
    function em_client_ip(): string {
        $keys = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR',
        ];
        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /** Client IP for rate-limiting only: trusts only REMOTE_ADDR (behind proxy). */
    function em_rate_limit_ip(): string {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }
}

if (!function_exists('em_is_production')) {
    function em_is_production(): bool {
        return em_config('app.env') === 'production';
    }
}

if (!function_exists('em_iso_to_mysql')) {
    /**
     * Convert an ISO-8601 timestamp (e.g. 2026-07-10T15:35:40.992Z) to MySQL DATETIME(3).
     */
    function em_iso_to_mysql(?string $iso, ?string $fallback = null): ?string {
        if ($iso === null || $iso === '') {
            return $fallback;
        }
        try {
            $dt = new DateTimeImmutable($iso);
            return $dt->format('Y-m-d H:i:s.v');
        } catch (Throwable $e) {
            return $fallback;
        }
    }
}

if (!function_exists('em_array_get')) {
    function em_array_get(array $array, string $key, $default = null) {
        $keys = explode('.', $key);
        $val = $array;
        foreach ($keys as $k) {
            if (!is_array($val) || !array_key_exists($k, $val)) {
                return $default;
            }
            $val = $val[$k];
        }
        return $val;
    }
}

if (!function_exists('em_truncate')) {
    function em_truncate(?string $s, int $len): string {
        if ($s === null) return '';
        if (mb_strlen($s) <= $len) return $s;
        return mb_substr($s, 0, $len);
    }
}

if (!function_exists('em_json_encode')) {
    function em_json_encode($data): string {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('em_random_bytes_hex')) {
    function em_random_bytes_hex(int $bytes): string {
        try {
            return bin2hex(random_bytes($bytes));
        } catch (Throwable $e) {
            return md5(uniqid((string)mt_rand(), true));
        }
    }
}
