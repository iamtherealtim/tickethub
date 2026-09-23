<?php

namespace App\Libraries;

/**
 * Tiny key/value settings store backed by the `settings` table.
 *
 * Secrets (SMTP password, Entra client secret, PDQ API key, inbound webhook
 * secret) are encrypted at rest with the framework encrypter whenever
 * `encryption.key` is configured, and stored as `enc:<base64 ciphertext>`.
 * Without a key they are stored in plaintext and the admin page says so.
 * Reads are transparent: a value written before the key existed still comes
 * back as-is, and a value that fails to decrypt (rotated key) is returned raw
 * rather than blowing up the page that asked for it.
 */
class Settings
{
    public const SENSITIVE_KEYS = ['mail_password', 'azure_client_secret', 'pdq_api_key', 'inbound_email_secret', 'oidc_client_secret', 'ldap_bind_password'];

    private const ENC_PREFIX = 'enc:';

    /** Cache of decrypted values, keyed by skey. */
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (db_connect()->table('settings')->get()->getResultArray() as $row) {
                self::$cache[$row['skey']] = self::decode($row['skey'], (string) $row['svalue']);
            }
        }

        return self::$cache;
    }

    public static function get(string $key, string $default = ''): string
    {
        return self::all()[$key] ?? $default;
    }

    public static function set(string $key, string $value): void
    {
        $db     = db_connect();
        $stored = self::encode($key, $value);
        if ($db->table('settings')->where('skey', $key)->countAllResults()) {
            $db->table('settings')->where('skey', $key)->update(['svalue' => $stored]);
        } else {
            $db->table('settings')->insert(['skey' => $key, 'svalue' => $stored]);
        }
        if (self::$cache !== null) {
            self::$cache[$key] = $value;
        }
    }

    /** Save many keys at once; empty password-ish values keep the stored secret. */
    public static function saveMany(array $pairs, array $keepIfBlank = []): void
    {
        foreach ($pairs as $k => $v) {
            if (in_array($k, $keepIfBlank, true) && trim((string) $v) === '') {
                continue;
            }
            self::set($k, trim((string) $v));
        }
    }

    /** True when `encryption.key` is set, i.e. secrets can be encrypted at rest. */
    public static function encryptionAvailable(): bool
    {
        return trim((string) config('Encryption')->key) !== '';
    }

    /**
     * Any sensitive key that is still stored in plaintext. Used by the admin
     * page to nudge operators who configured the key after saving secrets.
     */
    public static function plaintextSecrets(): array
    {
        $out = [];
        foreach (db_connect()->table('settings')->whereIn('skey', self::SENSITIVE_KEYS)->get()->getResultArray() as $row) {
            if ((string) $row['svalue'] !== '' && ! str_starts_with((string) $row['svalue'], self::ENC_PREFIX)) {
                $out[] = $row['skey'];
            }
        }

        return $out;
    }

    private static function encode(string $key, string $value): string
    {
        return in_array($key, self::SENSITIVE_KEYS, true) ? self::encryptValue($value, $key) : $value;
    }

    private static function decode(string $key, string $value): string
    {
        return in_array($key, self::SENSITIVE_KEYS, true) ? self::decryptValue($value, $key) : $value;
    }

    /**
     * Encrypt a secret for storage anywhere (settings rows, webhook
     * endpoints, …) as "enc:<base64>", or return it unchanged when no
     * encryption.key is configured.
     */
    public static function encryptValue(string $value, string $label = 'value'): string
    {
        if ($value === '' || ! self::encryptionAvailable()) {
            return $value;
        }

        try {
            return self::ENC_PREFIX . base64_encode(service('encrypter')->encrypt($value));
        } catch (\Throwable $e) {
            log_message('error', 'Settings: could not encrypt {key}: {msg}', ['key' => $label, 'msg' => $e->getMessage()]);

            return $value;
        }
    }

    /** Inverse of encryptValue(); plaintext (stored before a key existed) passes through. */
    public static function decryptValue(string $value, string $label = 'value'): string
    {
        if (! str_starts_with($value, self::ENC_PREFIX)) {
            return $value;
        }
        $raw = base64_decode(substr($value, strlen(self::ENC_PREFIX)), true);
        if ($raw === false || $raw === '') {
            return $value;
        }

        try {
            return (string) service('encrypter')->decrypt($raw);
        } catch (\Throwable $e) {
            // Key missing or rotated: hand back what is stored so nothing 500s.
            log_message('error', 'Settings: could not decrypt {key}: {msg}', ['key' => $label, 'msg' => $e->getMessage()]);

            return $value;
        }
    }
}
