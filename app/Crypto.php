<?php
/**
 * Application-Layer Cryptography for secure, encrypted telemetry & teacher messaging.
 * Uses AES-256-GCM authenticated encryption with SHA-256 key derivation.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    /**
     * Derive a 32-byte (256-bit) binary AES key from the shared secret.
     */
    public static function deriveKey(string $secret): string
    {
        return hash('sha256', $secret, true);
    }

    /**
     * Encrypt a data array or string using AES-256-GCM.
     *
     * @param mixed $data
     * @param string $secret
     * @return array{encrypted: true, v: 1, iv: string, data: string, tag: string}
     */
    public static function encrypt(mixed $data, string $secret): array
    {
        $plaintext = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE);
        $key = self::deriveKey($secret);
        $iv = random_bytes(12); // 96-bit IV for AES-GCM
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        return [
            'encrypted' => true,
            'v'         => 1,
            'iv'        => base64_encode($iv),
            'data'      => base64_encode($ciphertext),
            'tag'       => base64_encode($tag),
        ];
    }

    /**
     * Decrypt an encrypted envelope payload.
     *
     * @param array $envelope { encrypted: true, iv: string, data: string, tag: string }
     * @param string $secret
     * @return mixed Decrypted JSON object/array or raw string
     */
    public static function decrypt(array $envelope, string $secret): mixed
    {
        if (empty($envelope['encrypted']) || empty($envelope['data']) || empty($envelope['iv'])) {
            return $envelope;
        }

        $key = self::deriveKey($secret);
        $iv = base64_decode((string)$envelope['iv'], true);
        $ciphertext = base64_decode((string)$envelope['data'], true);
        $tag = isset($envelope['tag']) ? base64_decode((string)$envelope['tag'], true) : '';

        if ($iv === false || $ciphertext === false) {
            throw new RuntimeException('Malformed base64 ciphertext or IV');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            // Try fallback to CBC if GCM failed
            $plaintext = @openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr($iv . str_repeat("\0", 16), 0, 16));
            if ($plaintext === false) {
                throw new RuntimeException('Decryption failed (authentication tag mismatch or invalid key)');
            }
        }

        $decoded = json_decode($plaintext, true);
        return $decoded !== null ? $decoded : $plaintext;
    }

    /**
     * Helper to automatically decrypt if body is encrypted, otherwise return body as-is.
     */
    public static function decryptIfEncrypted(mixed $body, string $secret): mixed
    {
        if (!is_array($body)) {
            return $body;
        }
        if (!empty($body['encrypted']) && !empty($body['data']) && !empty($body['iv'])) {
            try {
                return self::decrypt($body, $secret);
            } catch (\Throwable $e) {
                error_log('[ExamMonitor Crypto] Decryption error: ' . $e->getMessage());
                return null;
            }
        }
        return $body;
    }
}
