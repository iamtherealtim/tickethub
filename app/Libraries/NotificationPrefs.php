<?php

namespace App\Libraries;

/**
 * Per-user notification preferences (user_notification_prefs). A missing row
 * means "yes, notify me"; the daily-digest flag on the user turns every
 * instant email off in favour of the tickethub:digest summary.
 *
 * Callers (Mailer, TicketIntake) ask NotificationPrefs::wants($userId,
 * $trigger, 'email'|'in_app') before sending.
 */
class NotificationPrefs
{
    /** In-app notification kinds (notifications.kind) and their labels. */
    public const IN_APP = [
        'reply'     => 'Someone replies on my ticket',
        'assigned'  => 'A ticket is assigned to me',
        'escalated' => 'A ticket I am on is escalated',
        'approval'  => 'A request needs my approval',
    ];

    /** @var array<int, array<string, array{email:int,in_app:int}>> rows per user, loaded once per request */
    private static array $rows = [];

    /** @var array<int, bool> */
    private static array $digest = [];

    /**
     * Should this user get this trigger over this channel?
     * Default true; a user on the daily digest never gets instant email.
     */
    public static function wants(int $userId, string $trigger, string $channel = 'email'): bool
    {
        if ($userId <= 0) {
            return true;
        }
        $channel = $channel === 'in_app' ? 'in_app' : 'email';
        if ($channel === 'email' && self::digest($userId)) {
            return false;
        }
        $rows = self::load($userId);

        return isset($rows[$trigger]) ? (bool) $rows[$trigger][$channel] : true;
    }

    /** True when the user asked for a daily digest instead of instant email. */
    public static function digest(int $userId): bool
    {
        if (! array_key_exists($userId, self::$digest)) {
            try {
                $row = db_connect()->table('users')->select('notify_digest')->where('id', $userId)->get()->getRowArray();
                self::$digest[$userId] = (bool) ($row['notify_digest'] ?? 0);
            } catch (\Throwable $e) {
                self::$digest[$userId] = false; // column not migrated yet
            }
        }

        return self::$digest[$userId];
    }

    /** All preference rows for a user, keyed by trigger. */
    public static function load(int $userId): array
    {
        if (! array_key_exists($userId, self::$rows)) {
            self::$rows[$userId] = [];

            try {
                foreach (db_connect()->table('user_notification_prefs')->where('user_id', $userId)->get()->getResultArray() as $r) {
                    self::$rows[$userId][$r['trigger_event']] = ['email' => (int) $r['email'], 'in_app' => (int) $r['in_app']];
                }
            } catch (\Throwable $e) {
                // table not migrated yet — everything defaults to on
            }
        }

        return self::$rows[$userId];
    }

    /** Replace a user's preferences. $prefs = [trigger => ['email' => bool, 'in_app' => bool]]. */
    public static function save(int $userId, array $prefs, bool $digest): void
    {
        $db = db_connect();
        $db->table('user_notification_prefs')->where('user_id', $userId)->delete();
        $rows = [];
        foreach (self::triggers() as $trigger => $label) {
            $p = $prefs[$trigger] ?? [];
            $rows[] = [
                'user_id'       => $userId,
                'trigger_event' => $trigger,
                'email'         => ! empty($p['email']) ? 1 : 0,
                'in_app'        => ! empty($p['in_app']) ? 1 : 0,
            ];
        }
        if ($rows) {
            $db->table('user_notification_prefs')->insertBatch($rows);
        }
        $db->table('users')->where('id', $userId)->update(['notify_digest' => $digest ? 1 : 0]);
        unset(self::$rows[$userId], self::$digest[$userId]);
    }

    /**
     * Every event a user can tune: the email template triggers plus the in-app
     * kinds. Returns [trigger => human label].
     */
    public static function triggers(): array
    {
        static $list = null;
        if ($list !== null) {
            return $list;
        }
        $list = [];

        try {
            foreach (db_connect()->table('email_templates')->select('trigger_event, name')->orderBy('id')->get()->getResultArray() as $t) {
                $list[$t['trigger_event']] = self::label($t['trigger_event'], $t['name']);
            }
        } catch (\Throwable $e) {
            // no templates table — in-app kinds only
        }
        foreach (self::IN_APP as $k => $label) {
            $list[$k] = $label;
        }

        return $list;
    }

    /** Which channels make sense for a trigger (email templates → email, in-app kinds → in_app). */
    public static function channelsFor(string $trigger): array
    {
        return isset(self::IN_APP[$trigger]) ? ['in_app'] : ['email'];
    }

    private static function label(string $trigger, string $name): string
    {
        return match ($trigger) {
            'Ticket created'    => 'My ticket is received',
            'Public reply sent' => 'An agent replies on my ticket',
            'Status → Resolved' => 'My ticket is resolved',
            'Ticket assigned'   => 'A ticket is assigned to me (email)',
            '80% of SLA'        => 'An SLA is about to breach',
            'Request approved'  => 'My request is approved',
            'Request rejected'  => 'My request is rejected',
            'Ticket escalated'  => 'A ticket in my group is escalated (email)',
            default             => $name ?: $trigger,
        };
    }
}
