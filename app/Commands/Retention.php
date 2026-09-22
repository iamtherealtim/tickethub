<?php

namespace App\Commands;

use App\Libraries\Audit;
use App\Libraries\Settings;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Data retention.
 *
 *   php spark tickethub:retention [--dry-run]
 *
 * Driven by the settings under Admin → Data retention:
 *   retention_closed_days            purge Closed tickets untouched for N days (0 = keep forever)
 *   retention_purge_attachments      also delete their attachment files (0/1)
 *   retention_audit_days             purge audit_log rows older than N days (0 = keep)
 *   retention_webhook_delivery_days  purge webhook_deliveries older than N days (0 = keep)
 *   retention_notification_days      purge READ notifications older than N days (0 = keep)
 * Plus an orphan sweep: files in writable/uploads/tickets that no message
 * references and that are older than 7 days.
 *
 * plan() is shared with the admin tab so "what would be purged" and "what is
 * purged" can never disagree.
 */
class Retention extends BaseCommand
{
    protected $group       = 'TicketHub';
    protected $name        = 'tickethub:retention';
    protected $description = 'Purges closed tickets, audit rows, webhook deliveries, read notifications and orphaned uploads per the retention settings.';
    protected $usage       = 'tickethub:retention [--dry-run]';
    protected $options     = ['--dry-run' => 'Report what would be removed without deleting anything.'];

    public const ORPHAN_DAYS = 7;

    public const SETTING_KEYS = [
        'retention_closed_days', 'retention_purge_attachments', 'retention_audit_days',
        'retention_webhook_delivery_days', 'retention_notification_days',
    ];

    /** Tables holding rows that belong to a ticket, keyed by their ticket column. */
    private const DEPENDENTS = [
        'ticket_messages' => 'ticket_id', 'ticket_tasks' => 'ticket_id', 'ticket_time_entries' => 'ticket_id',
        'ticket_watchers' => 'ticket_id', 'ticket_approvals' => 'ticket_id', 'ticket_field_values' => 'ticket_id',
        'ticket_assets' => 'ticket_id', 'notifications' => 'ticket_id', 'automation_runs' => 'ticket_id',
        'automation_log' => 'ticket_id',
    ];

    /** Current settings as ints. */
    public static function settings(): array
    {
        return [
            'closed_days'        => max(0, (int) Settings::get('retention_closed_days', '0')),
            'purge_attachments'  => Settings::get('retention_purge_attachments', '0') === '1',
            'audit_days'         => max(0, (int) Settings::get('retention_audit_days', '0')),
            'webhook_days'       => max(0, (int) Settings::get('retention_webhook_delivery_days', '0')),
            'notification_days'  => max(0, (int) Settings::get('retention_notification_days', '0')),
        ];
    }

    /**
     * What a run would remove right now. Returns counts plus the ticket ids and
     * orphan file names so run() can act on exactly what was reported.
     */
    public static function plan(): array
    {
        $db = db_connect();
        $s  = self::settings();
        $cutoff = static fn (int $days) => date('Y-m-d H:i:s', time() - $days * 86400);
        $plan = [
            'settings' => $s,
            'tickets' => 0, 'ticket_ids' => [], 'messages' => 0, 'attachment_files' => 0, 'attachment_bytes' => 0,
            'audit_rows' => 0, 'webhook_deliveries' => 0, 'notifications' => 0,
            'orphan_files' => [], 'orphan_bytes' => 0,
        ];

        if ($s['closed_days'] > 0) {
            $ids = array_map('intval', array_column(
                $db->table('tickets')->select('id')->where('status', 'Closed')->where('updated_at <', $cutoff($s['closed_days']))->get()->getResultArray(),
                'id'
            ));
            $plan['ticket_ids'] = $ids;
            $plan['tickets']    = count($ids);
            if ($ids) {
                $plan['messages'] = (int) $db->table('ticket_messages')->whereIn('ticket_id', $ids)->countAllResults();
                foreach ($db->table('ticket_messages')->select('attachments')->whereIn('ticket_id', $ids)->where('attachments IS NOT NULL', null, false)->get()->getResultArray() as $m) {
                    foreach (json_decode($m['attachments'] ?? '[]', true) ?: [] as $a) {
                        if (! empty($a['f'])) {
                            $plan['attachment_files']++;
                            $plan['attachment_bytes'] += (int) ($a['s'] ?? 0);
                        }
                    }
                }
            }
        }
        if ($s['audit_days'] > 0 && $db->tableExists('audit_log')) {
            $plan['audit_rows'] = (int) $db->table('audit_log')->where('created_at <', $cutoff($s['audit_days']))->countAllResults();
        }
        if ($s['webhook_days'] > 0 && $db->tableExists('webhook_deliveries')) {
            $plan['webhook_deliveries'] = (int) $db->table('webhook_deliveries')->where('created_at <', $cutoff($s['webhook_days']))->countAllResults();
        }
        if ($s['notification_days'] > 0 && $db->tableExists('notifications')) {
            $plan['notifications'] = (int) $db->table('notifications')->where('read_at IS NOT NULL', null, false)
                ->where('created_at <', $cutoff($s['notification_days']))->countAllResults();
        }

        // Orphan sweep: on-disk files nothing references, older than a week.
        $dir = WRITEPATH . 'uploads/tickets';
        if (is_dir($dir)) {
            $referenced = [];
            foreach ($db->table('ticket_messages')->select('attachments')->where('attachments IS NOT NULL', null, false)->where('attachments !=', '[]')->get()->getResultArray() as $m) {
                foreach (json_decode($m['attachments'] ?? '[]', true) ?: [] as $a) {
                    if (! empty($a['f'])) {
                        $referenced[$a['f']] = true;
                    }
                }
            }
            $limit = time() - self::ORPHAN_DAYS * 86400;
            foreach (scandir($dir) ?: [] as $f) {
                $path = $dir . DIRECTORY_SEPARATOR . $f;
                if ($f === '.' || $f === '..' || $f === 'index.html' || $f === '.htaccess' || ! is_file($path)) {
                    continue;
                }
                if (! isset($referenced[$f]) && filemtime($path) < $limit) {
                    $plan['orphan_files'][] = $f;
                    $plan['orphan_bytes']  += (int) filesize($path);
                }
            }
        }

        return $plan;
    }

    public function run(array $params)
    {
        helper('tickethub');
        $dry = array_key_exists('dry-run', $params) || CLI::getOption('dry-run') !== null;
        $db  = db_connect();
        $p   = self::plan();
        $s   = $p['settings'];

        $d = static fn (int $days) => $days ? $days . 'd' : 'keep';
        CLI::write(($dry ? '[dry run] ' : '') . 'Retention settings: closed tickets ' . $d($s['closed_days'])
            . ($s['closed_days'] ? ($s['purge_attachments'] ? ' (+attachments)' : ' (attachments kept)') : '')
            . ', audit ' . $d($s['audit_days']) . ', webhook deliveries ' . $d($s['webhook_days'])
            . ', read notifications ' . $d($s['notification_days']), 'yellow');

        $done = [];
        $now  = date('Y-m-d H:i:s');
        $cutoff = static fn (int $days) => date('Y-m-d H:i:s', time() - $days * 86400);

        // 1. Closed tickets and everything hanging off them.
        if ($p['tickets']) {
            $ids = $p['ticket_ids'];
            $files = [];
            if ($s['purge_attachments']) {
                foreach ($db->table('ticket_messages')->select('attachments')->whereIn('ticket_id', $ids)->get()->getResultArray() as $m) {
                    foreach (json_decode($m['attachments'] ?? '[]', true) ?: [] as $a) {
                        if (! empty($a['f']) && preg_match('/^[a-f0-9]{32}\.[a-z0-9]+$/i', $a['f'])) {
                            $files[] = $a['f'];
                        }
                    }
                }
            }
            if (! $dry) {
                foreach (array_chunk($ids, 500) as $chunk) {
                    foreach (self::DEPENDENTS as $table => $col) {
                        if ($db->tableExists($table)) {
                            $db->table($table)->whereIn($col, $chunk)->delete();
                        }
                    }
                    if ($db->tableExists('ticket_links')) {
                        $db->table('ticket_links')->whereIn('from_id', $chunk)->delete();
                        $db->table('ticket_links')->whereIn('to_id', $chunk)->delete();
                    }
                    $db->table('tickets')->whereIn('id', $chunk)->delete();
                }
                $removed = 0;
                foreach (array_unique($files) as $f) {
                    $path = WRITEPATH . 'uploads/tickets/' . $f;
                    if (is_file($path) && @unlink($path)) {
                        $removed++;
                    }
                }
            } else {
                $removed = count(array_unique($files));
            }
            $done[] = $p['tickets'] . ' closed ticket(s) older than ' . $s['closed_days'] . ' days (' . $p['messages'] . ' messages'
                . ($s['purge_attachments'] ? ', ' . $removed . ' attachment file(s)' : ', attachment files kept') . ')';
        }

        // 2. Audit rows.
        if ($p['audit_rows']) {
            if (! $dry) {
                $db->table('audit_log')->where('created_at <', $cutoff($s['audit_days']))->delete();
            }
            $done[] = $p['audit_rows'] . ' audit row(s) older than ' . $s['audit_days'] . ' days';
        }

        // 3. Webhook deliveries.
        if ($p['webhook_deliveries']) {
            if (! $dry) {
                $db->table('webhook_deliveries')->where('created_at <', $cutoff($s['webhook_days']))->delete();
            }
            $done[] = $p['webhook_deliveries'] . ' webhook deliver(ies) older than ' . $s['webhook_days'] . ' days';
        }

        // 4. Read notifications.
        if ($p['notifications']) {
            if (! $dry) {
                $db->table('notifications')->where('read_at IS NOT NULL', null, false)->where('created_at <', $cutoff($s['notification_days']))->delete();
            }
            $done[] = $p['notifications'] . ' read notification(s) older than ' . $s['notification_days'] . ' days';
        }

        // 5. Orphaned uploads.
        if ($p['orphan_files']) {
            $n = 0;
            foreach ($p['orphan_files'] as $f) {
                if ($dry || @unlink(WRITEPATH . 'uploads/tickets/' . $f)) {
                    $n++;
                }
            }
            $done[] = $n . ' orphaned upload(s) older than ' . self::ORPHAN_DAYS . ' days (' . number_format($p['orphan_bytes'] / 1024, 1) . ' KB)';
        }

        if (! $done) {
            CLI::write('Nothing to purge.', 'green');

            return;
        }
        foreach ($done as $line) {
            CLI::write('• ' . ($dry ? 'would remove ' : 'removed ') . $line, $dry ? 'yellow' : 'green');
        }
        if (! $dry) {
            Audit::log('retention.run', implode('; ', $done), null);
            CLI::write('Retention run recorded in the audit log at ' . $now . '.', 'green');
        }
    }
}
