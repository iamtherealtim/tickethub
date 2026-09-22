<?php

namespace App\Libraries;

/** Tiny key/value settings store backed by the `settings` table. */
class Settings
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (db_connect()->table('settings')->get()->getResultArray() as $row) {
                self::$cache[$row['skey']] = $row['svalue'];
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
        $db = db_connect();
        if ($db->table('settings')->where('skey', $key)->countAllResults()) {
            $db->table('settings')->where('skey', $key)->update(['svalue' => $value]);
        } else {
            $db->table('settings')->insert(['skey' => $key, 'svalue' => $value]);
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
}
