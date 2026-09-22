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
 *
 * Settings written: pdq_last_sync (last successful pass, full or partial),
 * pdq_last_error (last failure message, '' when clean), pdq_next_page (page
 * to resume from when a budgeted run stopped early, '' when finished).
 */
class PdqConnect
{
    /** Serial strings vendors ship instead of a real serial; never a match key. */
    private const PLACEHOLDER_SERIALS = [
        '', '—', '-', '0', '00', 'none', 'n/a', 'na', 'null', 'unknown', 'not available', 'not specified',
        'to be filled by o.e.m.', 'to be filled by oem', 'default string', 'system serial number',
        'serial number', 'chassis serial number', 'base board serial number', 'oem', '1234567890', '123456789',
    ];

    /** Seconds a web request may spend syncing before handing over to cron. */
    public const HTTP_BUDGET_SECONDS = 20;

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

    /** True when a serial is real enough to identify one machine. */
    public static function usableSerial(string $serial): bool
    {
        $s = strtolower(trim($serial));

        return $s !== '' && strlen($s) >= 4 && ! in_array($s, self::PLACEHOLDER_SERIALS, true) && ! preg_match('/^[0\-\s.]+$/', $s);
    }

    private static function guessType(string $model, string $hostname, string $os): string
    {
        $hay = mb_strtolower($model . ' ' . $hostname . ' ' . $os);
        if (str_contains($hay, 'server') || str_contains($hay, 'vmware') || str_contains($hay, 'virtual')) {
            return 'Server';
        }

        return 'Laptop';
    }

    /**
     * Map PDQ's "last logged-on user" (an email, DOMAIN\user or bare
     * account name) to a users.id. Email is exact; a local part is only used
     * when exactly one active user has it, so we never guess between two Sams.
     *
     * @param array<string, array> $byEmail  email => user
     * @param array<string, int[]> $byLocal  local part => [user ids]
     */
    private static function resolveUser(string $lastUser, array $byEmail, array $byLocal): ?int
    {
        $u = strtolower(trim($lastUser));
        if ($u === '') {
            return null;
        }
        if (str_contains($u, '\\')) {
            $u = substr($u, strrpos($u, '\\') + 1); // DOMAIN\user → user
        }
        if (str_contains($u, '@')) {
            if (isset($byEmail[$u])) {
                return (int) $byEmail[$u]['id'];
            }
            $u = explode('@', $u, 2)[0]; // user@otherdomain → try the local part
        }
        $ids = $byLocal[$u] ?? [];

        return count($ids) === 1 ? $ids[0] : null;
    }

    /**
     * Pull devices and upsert into the asset register.
     *
     * $maxPages / $maxSeconds bound the run: 0 means unbounded. Web requests
     * default to HTTP_BUDGET_SECONDS so the admin page never hangs; the cron
     * path passes 0 and finishes whatever a budgeted run left behind.
     */
    public static function sync(int $maxPages = 0, ?int $maxSeconds = null): array
    {
        if (! self::configured()) {
            return ['ok' => false, 'message' => 'PDQ Connect is not configured.', 'created' => 0, 'updated' => 0, 'partial' => false];
        }
        if ($maxSeconds === null) {
            $maxSeconds = is_cli() ? 0 : self::HTTP_BUDGET_SECONDS;
        }

        $db = db_connect();
        $created = 0;
        $updated = 0;
        $assigned = 0;
        $seen = 0;
        $skipped = 0;
        $pageSize = 100;
        $started = microtime(true);
        $now = date('Y-m-d H:i:s');

        // Directory for last-user mapping, built once per run.
        $byEmail = [];
        $byLocal = [];
        foreach ($db->table('users')->select('id, email')->where('active', 1)->get()->getResultArray() as $u) {
            $email = strtolower(trim((string) $u['email']));
            if ($email === '') {
                continue;
            }
            $byEmail[$email] = $u;
            $local = explode('@', $email, 2)[0];
            $byLocal[$local][] = (int) $u['id'];
        }

        // Resume where a budgeted pass stopped, so nothing is re-walked.
        $startPage = max(1, (int) Settings::get('pdq_next_page', '1'));
        $page = $startPage;
        $partial = false;
        $pagesDone = 0;

        for (; $page <= 500; $page++) {
            if ($maxPages > 0 && $pagesDone >= $maxPages) {
                $partial = true;
                break;
            }
            if ($maxSeconds > 0 && (microtime(true) - $started) >= $maxSeconds) {
                $partial = true;
                break;
            }
            $res = self::request('/devices', ['pageSize' => $pageSize, 'page' => $page]);
            if (! $res['ok']) {
                $msg = 'Sync stopped on page ' . $page . ': ' . $res['error']
                    . ' (' . $created . ' new, ' . $updated . ' updated before the failure)';
                // Partial progress still counts as a sync; the error is kept for the admin page.
                Settings::set('pdq_last_sync', $now);
                Settings::set('pdq_last_error', $msg);
                Settings::set('pdq_next_page', (string) $page);
                log_message('error', 'PDQ Connect: {msg}', ['msg' => $msg]);

                return ['ok' => false, 'message' => $msg, 'created' => $created, 'updated' => $updated, 'partial' => true];
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
                $lastUser = self::field($d, ['lastUser', 'last_user', 'lastLoggedOnUser', 'last_logged_on_user']);
                if ($deviceId === '' && $serial === '' && $hostname === '') {
                    $skipped++;

                    continue;
                }
                $serialOk = self::usableSerial($serial);

                // Match order: previously synced device id → real serial → hostname (unsynced only).
                $existing = null;
                if ($deviceId !== '') {
                    $existing = $db->table('assets')->where('pdq_device_id', $deviceId)->get()->getRowArray();
                }
                if (! $existing && $serialOk) {
                    $existing = $db->table('assets')->where('serial', $serial)->get()->getRowArray();
                }
                if (! $existing && $hostname !== '') {
                    $existing = $db->table('assets')->where('name', $hostname)->where('pdq_device_id', null)->get()->getRowArray();
                }

                $fields = array_filter([
                    'name'   => $hostname !== '' ? $hostname : null,
                    'model'  => $model !== '' ? $model : null,
                    'serial' => $serialOk ? $serial : null,
                    'os'     => $os !== '' ? $os : null,
                ], static fn ($v) => $v !== null);
                // A device id we already hold is never blanked by a payload without one.
                if ($deviceId !== '') {
                    $fields['pdq_device_id'] = $deviceId;
                }
                $fields['pdq_synced_at'] = $now;

                $userId = self::resolveUser($lastUser, $byEmail, $byLocal);
                if ($userId !== null) {
                    $fields['user_id'] = $userId;
                }

                if ($existing) {
                    if ($userId !== null && (int) ($existing['user_id'] ?? 0) !== $userId) {
                        $assigned++;
                        if ($existing['status'] === 'In stock') {
                            $fields['status'] = 'In use';
                        }
                    }
                    $db->table('assets')->where('id', $existing['id'])->update($fields);
                    $updated++;
                } else {
                    do {
                        $tag = 'AST-' . random_int(1000, 9999);
                    } while ($db->table('assets')->where('tag', $tag)->countAllResults());
                    if ($userId !== null) {
                        $assigned++;
                    }
                    $db->table('assets')->insert($fields + [
                        'tag'    => $tag,
                        'type'   => self::guessType($model, $hostname, $os),
                        'name'   => $hostname !== '' ? $hostname : ($serialOk ? $serial : $deviceId),
                        'model'  => $model !== '' ? $model : '—',
                        'serial' => $serialOk ? $serial : '—',
                        'os'     => $os !== '' ? $os : '—',
                        'site'   => '—',
                        'status' => $userId !== null ? 'In use' : 'In stock',
                    ]);
                    $created++;
                }
            }
            $pagesDone++;

            if (count($devices) < $pageSize) {
                $page++; // loop would have advanced; keep "next page" honest below
                break;
            }
        }

        Settings::set('pdq_last_sync', $now);
        Settings::set('pdq_last_error', '');
        Settings::set('pdq_next_page', $partial ? (string) $page : '');

        $summary = 'Synced ' . $seen . ' device(s) — ' . $created . ' new asset(s), ' . $updated . ' updated'
            . ($assigned ? ', ' . $assigned . ' holder(s) matched' : '')
            . ($skipped ? ', ' . $skipped . ' skipped (no identifier)' : '')
            . ($startPage > 1 ? ' (resumed from page ' . $startPage . ')' : '');
        if ($partial) {
            $summary .= ' — partial, cron will continue from page ' . $page;
        }

        return [
            'ok' => true,
            'message' => $summary,
            'created' => $created, 'updated' => $updated, 'partial' => $partial,
        ];
    }

    /** Hourly auto-sync hook for tickets:cron — unbounded, and immediate when a web run left work behind. */
    public static function maybeAutoSync(): ?string
    {
        if (! self::configured() || Settings::get('pdq_auto_sync') !== '1') {
            return null;
        }
        $resume = Settings::get('pdq_next_page') !== '';
        $last = Settings::get('pdq_last_sync');
        if (! $resume && $last !== '' && strtotime($last) > time() - 3600) {
            return null;
        }
        $res = self::sync(0, 0);

        return 'PDQ Connect: ' . $res['message'];
    }
}
