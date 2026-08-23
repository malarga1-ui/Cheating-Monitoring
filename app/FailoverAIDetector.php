<?php
/**
 * Failover AI Content Detector — RapidAPI Multi-Provider
 * =======================================================
 * Sequential failover chain: tries 4 AI detection APIs one by one,
 * stops at the first success. 3-second timeout per provider.
 *
 * Providers:
 *   1. ai-content-detector6  (POST /v5/ai-detector)
 *   2. ai-content-detector7  (POST /detect)
 *   3. ai-content-detector-ai-gpt (POST /api/detectText/)
 *   4. ai-content-detector1  (GET ?text=)
 *
 * Returns: { status, ai_score (0-100), provider, reason? }
 */
final class FailoverAIDetector
{
    private string $apiKey;
    private int    $timeoutSec = 3;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * Detect AI-generated content with sequential failover.
     *
     * @param string $text  The answer text to analyze
     * @return array{status:string, ai_score:float, provider:string, reason?:string}
     */
    public function detect(string $text): array
    {
        $cleanText = trim($text);

        if (str_word_count($cleanText) < 10) {
            return [
                'status'   => 'SKIPPED',
                'ai_score' => 0.0,
                'provider' => 'NONE',
                'reason'   => 'Text is too short (< 10 words)',
            ];
        }

        $providers = [
            'Provider_1' => fn() => $this->callProvider1($cleanText),
            'Provider_2' => fn() => $this->callProvider2($cleanText),
            'Provider_3' => fn() => $this->callProvider3($cleanText),
            'Provider_4' => fn() => $this->callProvider4($cleanText),
        ];

        foreach ($providers as $providerName => $callable) {
            $score = $callable();
            if ($score !== null) {
                return [
                    'status'   => 'SUCCESS',
                    'ai_score' => round(max(0.0, min(100.0, $score)), 2),
                    'provider' => $providerName,
                ];
            }
        }

        return [
            'status'   => 'FAILED',
            'ai_score' => 0.0,
            'provider' => 'ALL_FAILED',
            'reason'   => 'All API providers failed or quota exhausted',
        ];
    }

    /* ── Provider 1: ai-content-detector6 ─────────────────────── */

    private function callProvider1(string $text): ?float
    {
        $score = $this->sendRequest(
            'POST',
            'https://ai-content-detector6.p.rapidapi.com/v5/ai-detector',
            'ai-content-detector6.p.rapidapi.com',
            ['text' => $text],
            fn($data) => isset($data['score']) ? (float)$data['score'] * 100 : null
        );
        return $score;
    }

    /* ── Provider 2: ai-content-detector7 ─────────────────────── */

    private function callProvider2(string $text): ?float
    {
        return $this->sendRequest(
            'POST',
            'https://ai-content-detector7.p.rapidapi.com/detect',
            'ai-content-detector7.p.rapidapi.com',
            ['text' => $text],
            function ($data) {
                if (isset($data['ai_score']))       return (float)$data['ai_score'] * 100;
                if (isset($data['fake_probability'])) return (float)$data['fake_probability'];
                if (isset($data['score']))           return (float)$data['score'] * 100;
                return null;
            }
        );
    }

    /* ── Provider 3: ai-content-detector-ai-gpt ──────────────── */

    private function callProvider3(string $text): ?float
    {
        return $this->sendRequest(
            'POST',
            'https://ai-content-detector-ai-gpt.p.rapidapi.com/api/detectText/',
            'ai-content-detector-ai-gpt.p.rapidapi.com',
            ['text' => $text],
            function ($data) {
                if (isset($data['fake_probability'])) return (float)$data['fake_probability'];
                if (isset($data['ai_score']))         return (float)$data['ai_score'] * 100;
                return null;
            }
        );
    }

    /* ── Provider 4: ai-content-detector1 (GET) ──────────────── */

    private function callProvider4(string $text): ?float
    {
        $url = 'https://ai-content-detector1.p.rapidapi.com/?text=' . urlencode($text);
        return $this->sendRequest(
            'GET',
            $url,
            'ai-content-detector1.p.rapidapi.com',
            [],
            function ($data) {
                if (isset($data['fake_probability'])) return (float)$data['fake_probability'];
                if (isset($data['score']))            return (float)$data['score'] * 100;
                return null;
            }
        );
    }

    /* ── Unified cURL sender ──────────────────────────────────── */

    private function sendRequest(
        string  $method,
        string  $url,
        string  $host,
        array   $body,
        callable $parser
    ): ?float {
        $ch = curl_init();

        $headers = [
            'x-rapidapi-host: ' . $host,
            'x-rapidapi-key: ' . $this->apiKey,
        ];

        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeoutSec,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_SSL_VERIFYPEER => true,
        ];

        if ($method === 'POST') {
            $headers[]           = 'Content-Type: application/json';
            $options[CURLOPT_POST]       = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }

        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200 && $response !== false && $response !== '') {
            $data = json_decode($response, true);
            if (is_array($data)) {
                $result = $parser($data);
                if ($result !== null && is_numeric($result)) {
                    return (float)$result;
                }
            }
        }

        error_log("[FailoverAI] Provider failed: $host HTTP=$httpCode err=$curlErr");
        return null;
    }
}
