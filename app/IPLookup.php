<?php
/**
 * IP Geolocation and ISP / ASN intelligence service.
 * Enriches student IP addresses with ISP (PalTel, Ooredoo, Hadara, Jawwal, etc.), City, ASN, and VPN flags.
 * Uses high-speed local MySQL caching in `ip_cache` table.
 */
final class IPLookup
{
    private static bool $tableEnsured = false;
    private static array $memoryCache = [];

    private static function ensureTable(): void
    {
        if (self::$tableEnsured) return;
        try {
            Database::execute("
                CREATE TABLE IF NOT EXISTS ip_cache (
                    ip VARCHAR(45) PRIMARY KEY,
                    country VARCHAR(100) DEFAULT '',
                    city VARCHAR(100) DEFAULT '',
                    isp VARCHAR(150) DEFAULT '',
                    org VARCHAR(150) DEFAULT '',
                    as_number VARCHAR(100) DEFAULT '',
                    is_proxy TINYINT(1) DEFAULT 0,
                    is_mobile TINYINT(1) DEFAULT 0,
                    raw_json TEXT NULL,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            self::$tableEnsured = true;
        } catch (\Throwable $e) {}
    }

    /**
     * Resolve a single IP address to geographical & ISP metadata.
     */
    public static function resolve(string $ip): array
    {
        $ip = trim($ip);
        if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1' || str_starts_with($ip, '192.168.') || str_starts_with($ip, '10.') || str_starts_with($ip, '172.16.')) {
            return [
                'ip'        => $ip ?: '127.0.0.1',
                'country'   => 'Local Network',
                'city'      => 'Localhost',
                'isp'       => 'Local Network / Private LAN',
                'org'       => 'Intranet',
                'as_number' => 'LAN',
                'is_proxy'  => false,
                'is_mobile' => false,
            ];
        }

        if (isset(self::$memoryCache[$ip])) {
            return self::$memoryCache[$ip];
        }

        self::ensureTable();

        // 1. Check local database cache
        try {
            $cached = Database::fetchOne('SELECT * FROM ip_cache WHERE ip = ? LIMIT 1', [$ip]);
            if ($cached && !empty($cached['isp'])) {
                $res = [
                    'ip'        => $ip,
                    'country'   => (string)$cached['country'],
                    'city'      => (string)$cached['city'],
                    'isp'       => (string)$cached['isp'],
                    'org'       => (string)$cached['org'],
                    'as_number' => (string)$cached['as_number'],
                    'is_proxy'  => (bool)$cached['is_proxy'],
                    'is_mobile' => (bool)$cached['is_mobile'],
                ];
                self::$memoryCache[$ip] = $res;
                return $res;
            }
        } catch (\Throwable $e) {}

        // 2. Fetch from Geolocation API (ip-api.com json endpoint)
        $info = self::fetchFromApi($ip);

        // 3. Save to database cache
        try {
            Database::execute(
                'INSERT INTO ip_cache (ip, country, city, isp, org, as_number, is_proxy, is_mobile, raw_json, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE country = VALUES(country), city = VALUES(city), isp = VALUES(isp), org = VALUES(org), as_number = VALUES(as_number), is_proxy = VALUES(is_proxy), is_mobile = VALUES(is_mobile), raw_json = VALUES(raw_json)',
                [
                    $ip,
                    $info['country'],
                    $info['city'],
                    $info['isp'],
                    $info['org'],
                    $info['as_number'],
                    $info['is_proxy'] ? 1 : 0,
                    $info['is_mobile'] ? 1 : 0,
                    json_encode($info, JSON_UNESCAPED_UNICODE),
                ]
            );
        } catch (\Throwable $e) {}

        self::$memoryCache[$ip] = $info;
        return $info;
    }

    /**
     * Batch resolve multiple IPs.
     */
    public static function resolveBatch(array $ips): array
    {
        $res = [];
        foreach (array_unique(array_filter($ips)) as $ip) {
            $res[$ip] = self::resolve($ip);
        }
        return $res;
    }

    private static function fetchFromApi(string $ip): array
    {
        $default = [
            'ip'        => $ip,
            'country'   => 'Palestine',
            'city'      => 'Gaza',
            'isp'       => 'Internet Service Provider',
            'org'       => 'Broadband Network',
            'as_number' => 'AS12975',
            'is_proxy'  => false,
            'is_mobile' => false,
        ];

        try {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 2.5,
                    'header'  => "User-Agent: ExamMonitor-Forensics/1.0\r\n",
                ],
            ]);

            $url = "http://ip-api.com/json/{$ip}?fields=status,message,country,regionName,city,isp,org,as,mobile,proxy,hosting,query";
            $raw = @file_get_contents($url, false, $ctx);
            if ($raw === false) {
                return $default;
            }

            $data = json_decode($raw, true);
            if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
                return $default;
            }

            $country = (string)($data['country'] ?? 'Palestine');
            $city = (string)($data['city'] ?? ($data['regionName'] ?? 'Gaza'));
            $isp = (string)($data['isp'] ?? 'PalTel / Cellular');
            $org = (string)($data['org'] ?? $isp);
            $as = (string)($data['as'] ?? '');
            $isProxy = !empty($data['proxy']) || !empty($data['hosting']);
            $isMobile = !empty($data['mobile']);

            // Clean Arabic/English representation
            if ($country === 'State of Palestine' || $country === 'Palestinian Territory') {
                $country = 'فلسطين (Palestine)';
            }
            if ($city === 'Gaza' || $city === 'Gaza City') {
                $city = 'غزة (Gaza)';
            } elseif ($city === 'Khan Yunis') {
                $city = 'خان يونس (Khan Yunis)';
            } elseif ($city === 'Rafah') {
                $city = 'رفح (Rafah)';
            } elseif ($city === 'Deir al-Balah' || $city === 'Dayr al Balah') {
                $city = 'دير البلح (Deir al-Balah)';
            } elseif ($city === 'Hebron') {
                $city = 'الخليل (Hebron)';
            } elseif ($city === 'Nablus') {
                $city = 'نابلس (Nablus)';
            } elseif ($city === 'Ramallah') {
                $city = 'رام الله (Ramallah)';
            }

            return [
                'ip'        => $ip,
                'country'   => $country,
                'city'      => $city,
                'isp'       => $isp,
                'org'       => $org,
                'as_number' => $as,
                'is_proxy'  => $isProxy,
                'is_mobile' => $isMobile,
            ];
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
