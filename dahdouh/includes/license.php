<?php
// ─── License system ────────────────────────────────────────────────────────────
// Supports two payload formats (both RSA-signed, machine-locked):
//   Old / lifetime:  base64(machineId|clientName).base64(sig)
//   New / typed:     base64(machineId|clientName|type|issuedAt|expiresAt).base64(sig)
// Only the public key lives here — it cannot forge licenses.

define('LIC_PUBLIC_KEY', <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA3g3FzPimgMZpjVNodxfJ
XSTUilY89Y4y9tODppzxk1izu2Xn+CSf021lEKJPE+wuQmJG3D1F/s+nodBDOJ59
YlIDWK6eUyAv8adSg3T5GwcAtvigiz1/cuNQLbuv+sijzWam2KXy3WQF9ymYKNO4
huy1W/yiuytVHtkDqVzMQOR5VIjc0eytcoV7ffxA8km5NXVJC3nZklCFy2xJanUQ
b2gfRR43d9hUQiqSlg3IXb+hFoT4kJDeIaPhHaetvZUMJdjMwB67qrTT/oUeKRxw
YQ2A50Xcq/IUJ/2WusAnwZj4YbYkV84d7SBfvhUmAjkFBMMr3ZoO9r8ZGCp5Fj73
owIDAQAB
-----END PUBLIC KEY-----
PEM);

define('LIC_FILE',       __DIR__ . '/../license.lic');
define('LIC_STAMP_FILE', __DIR__ . '/../license_ts.dat');
define('LIC_SESSION',    'lic_validated');

// ── Machine fingerprint ────────────────────────────────────────────────────────
// Windows MachineGuid + C: volume serial → stable 16-char hex hash.

function getMachineId(): string {
    static $cached = null;
    if ($cached !== null) return $cached;

    $guid = '';
    if (function_exists('exec')) {
        exec('reg query "HKLM\\SOFTWARE\\Microsoft\\Cryptography" /v MachineGuid 2>nul', $out);
        foreach ($out as $line) {
            if (stripos($line, 'MachineGuid') !== false) {
                $parts = preg_split('/\s+/', trim($line));
                $guid  = end($parts);
                break;
            }
        }
        $serial = '';
        exec('vol C: 2>nul', $volOut);
        foreach ($volOut as $line) {
            if (preg_match('/([0-9A-F]{4}-[0-9A-F]{4})/i', $line, $m)) {
                $serial = strtoupper(str_replace('-', '', $m[1]));
                break;
            }
        }
    }

    $raw    = ($guid ?: php_uname('n')) . '|' . ($serial ?? 'NOSN');
    $cached = strtoupper(substr(hash('sha256', $raw), 0, 16));
    return $cached;
}

function formatMachineId(): string {
    return implode('-', str_split(getMachineId(), 4));
}

// ── Anti-clock-rollback (Layer 2 — yearly licenses only) ──────────────────────
// Writes a HMAC-protected timestamp after each successful check.
// If current time < stored time, the clock was rolled back → reject.

function checkClockRollback(): bool {
    if (!file_exists(LIC_STAMP_FILE)) return true; // first run, no baseline yet
    $content = trim((string)file_get_contents(LIC_STAMP_FILE));
    $parts   = explode('.', $content, 2);
    if (count($parts) !== 2) return true; // unreadable → reset baseline
    [$storedTs, $storedHmac] = $parts;
    $expected = hash_hmac('sha256', $storedTs, getMachineId());
    if (!hash_equals($expected, $storedHmac)) return true; // tampered → reset baseline
    return time() >= (int)$storedTs;
}

function updateLicenseTimestamp(): void {
    $ts   = (string)time();
    $hmac = hash_hmac('sha256', $ts, getMachineId());
    @file_put_contents(LIC_STAMP_FILE, $ts . '.' . $hmac);
}

// ── License validation ─────────────────────────────────────────────────────────
// Returns array on success, false on failure.
// Keys: client (string), type ('lifetime'|'yearly'), issued_at (int), expires_at (int, 0=never)

function validateLicense(string $key): array|false {
    $key    = trim($key);
    $dotPos = strrpos($key, '.');
    if ($dotPos === false) return false;

    $data = base64_decode(substr($key, 0, $dotPos), true);
    $sig  = base64_decode(substr($key, $dotPos + 1), true);
    if ($data === false || $sig === false || $sig === '') return false;

    $pubKey = openssl_get_publickey(LIC_PUBLIC_KEY);
    if (!$pubKey) return false;
    if (openssl_verify($data, $sig, $pubKey, OPENSSL_ALGO_SHA256) !== 1) return false;

    $parts = explode('|', $data);

    // Old format: machineId|clientName → treated as lifetime, no date checks
    if (count($parts) === 2) {
        [$machineId, $clientName] = $parts;
        if (strtoupper($machineId) !== getMachineId()) return false;
        return ['client' => $clientName, 'type' => 'lifetime', 'issued_at' => 0, 'expires_at' => 0];
    }

    // New format: machineId|clientName|type|issuedAt|expiresAt
    if (count($parts) === 5) {
        [$machineId, $clientName, $type, $issuedAt, $expiresAt] = $parts;
        if (strtoupper($machineId) !== getMachineId()) return false;

        $issuedAt  = (int)$issuedAt;
        $expiresAt = (int)$expiresAt;
        $now       = time();

        // Layer 1 anti-rollback: clock must be at or after issue date (signed, unbypassable)
        if ($issuedAt > 0 && $now < $issuedAt) return false;

        // Yearly expiry
        if ($type === 'yearly' && $expiresAt > 0 && $now > $expiresAt) return false;

        return ['client' => $clientName, 'type' => $type, 'issued_at' => $issuedAt, 'expires_at' => $expiresAt];
    }

    return false;
}

// ── Page-level check ───────────────────────────────────────────────────────────

function checkLicense(): void {
    if (!empty($_SESSION[LIC_SESSION])) {
        $info = $_SESSION[LIC_SESSION];
        if (($info['type'] ?? '') === 'yearly' && ($info['expires_at'] ?? 0) > 0) {
            if (time() > $info['expires_at']) {
                unset($_SESSION[LIC_SESSION]);
                redirectToActivation('Your annual license has expired. Please contact your software provider.');
                return;
            }
            if (!checkClockRollback()) {
                unset($_SESSION[LIC_SESSION]);
                redirectToActivation('System clock anomaly detected. License suspended.');
                return;
            }
            updateLicenseTimestamp();
        }
        return;
    }

    if (!file_exists(LIC_FILE)) {
        redirectToActivation('No license found.');
        return;
    }

    $key  = trim((string)file_get_contents(LIC_FILE));
    $info = validateLicense($key);

    if ($info === false) {
        redirectToActivation('License is invalid or belongs to a different machine.');
        return;
    }

    if ($info['type'] === 'yearly') {
        if (!checkClockRollback()) {
            redirectToActivation('System clock anomaly detected. License suspended.');
            return;
        }
        updateLicenseTimestamp();
    }

    $_SESSION[LIC_SESSION] = $info;
}

function redirectToActivation(string $reason): void {
    $self = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
    if ($self === 'activate.php') return;
    header('Location: /dahdouh/pages/activate.php?reason=' . urlencode($reason));
    exit;
}

// Returns client name string — backward-compatible with any code that calls this.
function getLicenseClient(): string {
    $info = $_SESSION[LIC_SESSION] ?? null;
    if (is_array($info)) return $info['client'] ?? '';
    if (is_string($info)) return $info;
    return '';
}

// Returns full license info array for display purposes.
function getLicenseInfo(): array {
    $info = $_SESSION[LIC_SESSION] ?? null;
    if (is_array($info)) return $info;
    if (is_string($info)) return ['client' => $info, 'type' => 'lifetime', 'issued_at' => 0, 'expires_at' => 0];
    return [];
}
