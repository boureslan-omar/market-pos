<?php
// ─── License system ────────────────────────────────────────────────────────────
// Machine-locked, offline-only, lifetime license.
// Keys are RSA-signed by the developer's private key (never deployed).
// This file only contains the public key — it cannot forge licenses.

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

define('LIC_FILE',    __DIR__ . '/../license.lic');
define('LIC_SESSION', 'lic_validated');

// ── Machine fingerprint ────────────────────────────────────────────────────────
// Uses Windows MachineGuid (stable across reboots/NIC changes) + C: volume serial.
// Degrades gracefully if exec() is unavailable.

function getMachineId(): string {
    static $cached = null;
    if ($cached !== null) return $cached;

    // 1. Windows MachineGuid from registry
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
        // 2. C: volume serial (secondary)
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

// Format machine ID as XXXX-XXXX-XXXX-XXXX for readability
function formatMachineId(): string {
    return implode('-', str_split(getMachineId(), 4));
}

// ── License validation ─────────────────────────────────────────────────────────
// Returns the client name on success, false on failure.

function validateLicense(string $key): string|false {
    $key = trim($key);

    // Format: base64(machineId|clientName) . '.' . base64(RSA_signature)
    $dotPos = strrpos($key, '.');
    if ($dotPos === false) return false;

    $data = base64_decode(substr($key, 0, $dotPos), true);
    $sig  = base64_decode(substr($key, $dotPos + 1), true);
    if (!$data || $sig === false) return false;

    $parts = explode('|', $data, 2);
    if (count($parts) !== 2) return false;
    [$machineId, $clientName] = $parts;

    // Verify RSA signature — public key cannot forge, only verify
    $pubKey = openssl_get_publickey(LIC_PUBLIC_KEY);
    if (!$pubKey) return false;
    if (openssl_verify($data, $sig, $pubKey, OPENSSL_ALGO_SHA256) !== 1) return false;

    // Check machine match
    if (strtoupper($machineId) !== getMachineId()) return false;

    return $clientName;
}

// ── Page-level check (call from layout.php) ────────────────────────────────────
// Cached in session so the file is only read once per session.

function checkLicense(): void {
    // Already validated this session
    if (!empty($_SESSION[LIC_SESSION])) return;

    // No license file → activation required
    if (!file_exists(LIC_FILE)) {
        redirectToActivation('No license found.');
        return;
    }

    $key    = trim(file_get_contents(LIC_FILE));
    $client = validateLicense($key);

    if ($client === false) {
        redirectToActivation('License is invalid or belongs to a different machine.');
        return;
    }

    $_SESSION[LIC_SESSION] = $client; // cache: valid
}

function redirectToActivation(string $reason): void {
    // Don't redirect if already on the activation page
    $self = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
    if ($self === 'activate.php') return;

    $msg = urlencode($reason);
    header("Location: /dahdouh/pages/activate.php?reason=$msg");
    exit;
}

function getLicenseClient(): string {
    return $_SESSION[LIC_SESSION] ?? '';
}
