<?php
/**
 * Issue a license key to an existing account (used once the purchase
 * flow exists / manually by the owner).
 *
 * Usage:
 *   php scripts/generate_license.php <account-email>
 *
 * The key is random, unique, stored in accounts.license_key, and flips
 * the account to status = 'active' (trial lock removed).
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$email = strtolower(trim((string)($argv[1] ?? '')));
if ($email === '') {
    fwrite(STDERR, "Usage: php scripts/generate_license.php <account-email>\n");
    exit(1);
}

$account = Accounts::findByEmail($email);
if ($account === null) {
    fwrite(STDERR, "لا يوجد حساب بهذا البريد: $email\n");
    exit(1);
}
if ($account['role'] === 'owner') {
    fwrite(STDERR, "حساب المالك لا يحتاج مفتاح ترخيص.\n");
    exit(1);
}

$key = 'EM-' . implode('-', str_split(strtoupper(bin2hex(random_bytes(10))), 5));

Accounts::setLicenseKey((int)$account['id'], $key);

echo 'Account:   ' . $email . PHP_EOL;
echo 'License:   ' . $key . PHP_EOL;
echo 'Status:    active' . PHP_EOL;