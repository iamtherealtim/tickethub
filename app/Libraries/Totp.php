<?php

namespace App\Libraries;

/**
 * RFC 6238 time-based one-time passwords (SHA-1, 30 s step, 6 digits), plus
 * the bits around them: base32 secrets, otpauth:// URIs for authenticator
 * apps, hashed one-time recovery codes, and at-rest encryption of the secret
 * that follows the same rule as Settings (encrypted when encryption.key is
 * set, plain otherwise, transparent on read).
 */
class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;
    public const WINDOW = 1; // accept ±1 step of clock drift

    private const ALPHABET   = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const ENC_PREFIX = 'enc:';

    /** A fresh 160-bit secret, base32 encoded (32 chars). */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $b32  = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32) ?? '');
        $bits = '';
        for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
            $v = strpos(self::ALPHABET, $b32[$i]);
            if ($v === false) {
                continue;
            }
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }

    /** The code for a given time step (defaults to now). */
    public static function code(string $secret, ?int $time = null): string
    {
        $counter = intdiv($time ?? time(), self::PERIOD);
        $msg     = pack('N*', 0) . pack('N*', $counter); // 64-bit big-endian counter
        $hash    = hash_hmac('sha1', $msg, self::base32Decode($secret), true);
        $offset  = ord($hash[19]) & 0x0F;
        $binary  = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** Constant-time check of a submitted code against the current step ±WINDOW. */
    public static function verify(string $secret, string $code, ?int $time = null): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (! preg_match('/^\d{' . self::DIGITS . '}$/', $code) || $secret === '') {
            return false;
        }
        $now = $time ?? time();
        $ok  = false;
        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            // Evaluate every window so timing does not reveal which one matched.
            if (hash_equals(self::code($secret, $now + $i * self::PERIOD), $code)) {
                $ok = true;
            }
        }

        return $ok;
    }

    /**
     * Like verify(), but returns the time step that matched (or null). Sign-in
     * stores it and refuses any step at or below the last one used, so an
     * observed code cannot be replayed inside its ±WINDOW validity period.
     */
    public static function verifyStep(string $secret, string $code, ?int $time = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (! preg_match('/^\d{' . self::DIGITS . '}$/', $code) || $secret === '') {
            return null;
        }
        $now     = $time ?? time();
        $matched = null;
        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            $t = $now + $i * self::PERIOD;
            if (hash_equals(self::code($secret, $t), $code)) {
                $matched = intdiv($t, self::PERIOD);
            }
        }

        return $matched;
    }

    /** otpauth:// URI for QR enrolment in Google Authenticator, Authy, 1Password etc. */
    public static function uri(string $secret, string $account, string $issuer = 'TicketHub'): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?' . http_build_query([
                'secret'    => $secret,
                'issuer'    => $issuer,
                'algorithm' => 'SHA1',
                'digits'    => self::DIGITS,
                'period'    => self::PERIOD,
            ], '', '&', PHP_QUERY_RFC3986);
    }

    /* ---------- recovery codes ---------- */

    /**
     * Ten fresh plain codes (xxxxx-xxxxx-xxxxx-xxxxx, 80 bits each) — show
     * once, store hashed with hashRecovery(). 80 bits is what makes the
     * unsalted SHA-256 storage safe: a leaked database copy cannot be
     * brute-forced back to usable codes the way 32-bit codes could.
     */
    public static function generateRecoveryCodes(int $count = 10): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = implode('-', str_split(strtolower(bin2hex(random_bytes(10))), 5));
        }

        return $codes;
    }

    public static function hashRecovery(array $plainCodes): array
    {
        return array_map(static fn ($c) => hash('sha256', self::normaliseRecovery($c)), $plainCodes);
    }

    public static function normaliseRecovery(string $code): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $code) ?? '');
    }

    /**
     * Consume a recovery code: returns the remaining hashed list on success,
     * null when it does not match any unused code.
     */
    public static function consumeRecovery(array $hashed, string $code): ?array
    {
        $h = hash('sha256', self::normaliseRecovery($code));
        foreach ($hashed as $i => $stored) {
            if (hash_equals((string) $stored, $h)) {
                unset($hashed[$i]);

                return array_values($hashed);
            }
        }

        return null;
    }

    /* ---------- at-rest protection of the secret ---------- */

    public static function encryptSecret(string $secret): string
    {
        if ($secret === '' || ! Settings::encryptionAvailable()) {
            return $secret;
        }

        try {
            return self::ENC_PREFIX . base64_encode(service('encrypter')->encrypt($secret));
        } catch (\Throwable $e) {
            log_message('error', 'Totp: could not encrypt secret: {msg}', ['msg' => $e->getMessage()]);

            return $secret;
        }
    }

    public static function decryptSecret(?string $stored): string
    {
        $stored = (string) $stored;
        if (! str_starts_with($stored, self::ENC_PREFIX)) {
            return $stored;
        }
        $raw = base64_decode(substr($stored, strlen(self::ENC_PREFIX)), true);
        if ($raw === false || $raw === '') {
            return '';
        }

        try {
            return (string) service('encrypter')->decrypt($raw);
        } catch (\Throwable $e) {
            log_message('error', 'Totp: could not decrypt secret: {msg}', ['msg' => $e->getMessage()]);

            return '';
        }
    }
}
