<?php

namespace App\Libraries;

/**
 * Optional PDQ Connect integration — pulls the device inventory into the
 * asset register. Configured under Admin → Integrations; everything is off
 * until an API key is saved and the toggle enabled.
 *
 * Talks to the PDQ Connect REST API (default https://app.pdq.com/v1/api,
 * Bearer-token auth, GET /devices with page/pageSize). Field names are read
 * defensively so minor API-shape differences don't break the sync.
 */
class PdqConnect
{
    public static function configured(): bool
    {
        return Settings::get('pdq_enabled') === '1' && Settings::get('pdq_api_key') !== '';
    }

    private static function request(string $path, array $query = []): array
    {
        $base = rtrim(Settings::get('pdq_base_url', 'https://app.pdq.com/v1/api'), '/');
        // Re-check at call time as well as on save — settings can be changed elsewhere.
        helper('tickethub');
        if ($err = th_outbound_url_error($base)) {
            return ['ok' => false, 'error' => 'Refusing to call the configured URL — ' . $err, 'data' => null];
        }
        $url = $base . $path . ($query ? '?' . http_build_query($query) : '');

        try {
            $client = service('curlrequest', ['timeout' => 20, 'http_errors' => false]);
            $res = $client->get($url, ['headers' => [
                'Authorization' => 'Bearer ' . Settings::get('pdq_api_key'),
                'Accept'        => 'application/json',
            ]]);
            $code = $res->getStatusCode();
            $json = json_decode((string) $res->getBody(), true);
            if ($code >= 400) {
                $msg = is_array($json) ? ($json['error']['message'] ?? $json['message'] ?? ('HTTP ' . $code)) : 'HTTP ' . $code;

                return ['ok' => false, 'error' => $msg, 'data' => null];
            }

            return ['ok' => true, 'error' => '', 'data' => $json];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'data' => null];
        }
    }

    public static function testConnection(): array
    {
        $res = self::request('/devices', ['pageSize' => 1, 'page' => 1]);
        if (! $res['ok']) {
            return ['ok' => false, 'message' => 'Connection failed: ' . $res['error']];
        }
        $list = self::deviceList($res['data']);

        return ['ok' => true, 'message' => 'Connected — the API answered' . (is_array($list) ? ' (' . count($list) . ' device(s) on the first page)' : '')];
    }

    /** Tolerate {data:[...]}, {devices:[...]}, or a bare array. */
    private static function deviceList($json): array
    {
        if (! is_array($json)) {
            return [];
        }
        if (isset($json['data']) && is_array($json['data'])) {
            return $json['data'];
        }
        if (isset($json['devices']) && is_array($json['devices'])) {
            return $json['devices'];
        }

        return array_is_list($json) ? $json : [];
    }

    private static function field(array $d, array $names): string
    {
        foreach ($names as $n) {
            if (isset($d[$n]) && is_scalar($d[$n]) && trim((string) $d[$n]) !== '') {
                return trim((string) $d[$n]);
            }
        }

        return '';
    }

    private static function guessType(string $model, string $hostname, string $os): string
    {
        $hay = mb_strtolower($model . ' ' . $hostname . ' ' . $os);
        if (str_contains($hay, 'server') || str_contains($hay, 'vmware') || str_contains($hay, 'virtual')) {
            return 'Server';
        }

        return 'Laptop';
    }

    /** Pull all devices and upsert into the asset register. */
    public static function sync(): array
    {
        if (! self::configured()) {
            return ['ok' => false, 'message' => 'PDQ Connect is not configured.', 'created' => 0, 'updated' => 0];
        }

        $db = db_connect();
        $created = 0;
        $updated = 0;
        $seen = 0;
        $pageSize = 100;

        for ($page = 1; $page <= 50; $page++) {
            $res = self::request('/devices', ['pageSize' => $pageSize, 'page' => $page]);
            if (! $res['ok']) {
                return ['ok' => false, 'message' => 'Sync failed on page ' . $page . ': ' . $res['error'], 'created' => $created, 'updated' => $updated];
            }
            $devices = self::deviceList($res['data']);
            if (! $devices) {
                break;
            }

            foreach ($devices as $d) {
                if (! is_array($d)) {
                    continue;
                }
                $seen++;
                $deviceId = self::field($d, ['id', 'deviceId', 'device_id']);
                $hostname = self::field($d, ['hostname', 'name']);
                $serial   = self::field($d, ['serialNumber', 'serial_number', 'serial']);
                $model    = self::field($d, ['model']);
                $os       = self::field($d, ['osVersion', 'os_version', 'os']);
                $lastUser = self::field($d, ['lastUser', 'last_user']);
                if ($deviceId === '' && $serial === '' && $hostname === '') {
                    continue;
                }

                // Match order: previously synced device id → serial number → hostname.
                $existing = null;
                if ($deviceId !== '') {
                    $existing = $db->table('assets')->where('pdq_device_id', $deviceId)->get()->getRowArray();
                }
                if (! $existing && $serial !== '' && $serial !== '—') {
                    $existing = $db->table('assets')->where('serial', $serial)->get()->getRowArray();
                }
                if (! $existing && $hostname !== '') {
                    $existing = $db->table('assets')->where('name', $hostname)->where('pdq_device_id', null)->get()->getRowArray();
                }

                $now = date('Y-m-d H:i:s');
                $fields = array_filter([
                    'name'   => $hostname !== '' ? $hostname : null,
                    'model'  => $model !== '' ? $model : null,
                    'serial' => $serial !== '' ? $serial : null,
                    'os'     => $os !== '' ? $os : null,
                ], static fn ($v) => $v !== null);
                $fields['pdq_device_id'] = $deviceId !== '' ? $deviceId : null;
                $fields['pdq_synced_at'] = $now;

                if ($existing) {
                    $db->table('assets')->where('id', $existing['id'])->update($fields);
                    $updated++;
                } else {
                    do {
                        $tag = 'AST-' . random_int(1000, 9999);
                    } while ($db->table('assets')->where('tag', $tag)->countAllResults());
                    $db->table('assets')->insert($fields + [
                        'tag'    => $tag,
                        'type'   => self::guessType($model, $hostname, $os),
                        'name'   => $hostname !== '' ? $hostname : ($serial !== '' ? $serial : $deviceId),
                        'model'  => $model !== '' ? $model : '—',
                        'serial' => $serial !== '' ? $serial : '—',
                        'os'     => $os !== '' ? $os : '—',
                        'site'   => '—',
                        'status' => 'In use',
                    ]);
                    $created++;
                }
                unset($lastUser); // reserved for a future "last seen by" mapping
            }

            if (count($devices) < $pageSize) {
                break;
            }
        }

        Settings::set('pdq_last_sync', date('Y-m-d H:i:s'));

        return [
            'ok' => true,
            'message' => 'Synced ' . $seen . ' device(s) — ' . $created . ' new asset(s), ' . $updated . ' updated',
            'created' => $created, 'updated' => $updated,
        ];
    }

    /** Hourly auto-sync hook for tickets:cron. */
    public static function maybeAutoSync(): ?string
    {
        if (! self::configured() || Settings::get('pdq_auto_sync') !== '1') {
            return null;
        }
        $last = Settings::get('pdq_last_sync');
        if ($last !== '' && strtotime($last) > time() - 3600) {
            return null;
        }
        $res = self::sync();

        return 'PDQ Connect: ' . $res['message'];
    }
}
