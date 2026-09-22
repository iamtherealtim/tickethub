<?php

namespace App\Commands;

use App\Libraries\Mailer;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Daily digest: one email per user who opted for "digest instead of instant
 * email" (users.notify_digest), listing their unread in-app notifications
 * from the last 24 hours. Schedule once a day, e.g. `0 7 * * *`.
 */
class Digest extends BaseCommand
{
    protected $group       = 'TicketHub';
    protected $name        = 'tickethub:digest';
    protected $description = 'Emails users on the daily digest a summary of their unread notifications from the last 24 hours.';
    protected $usage       = 'tickethub:digest [--dry-run] [--hours=24] [--all]';
    protected $options     = [
        '--dry-run' => 'Print what would be sent without sending anything.',
        '--hours'   => 'Look-back window in hours (default 24).',
        '--all'     => 'Include users who have not opted into the digest.',
    ];

    public function run(array $params)
    {
        helper(['tickethub', 'url']);
        $dry   = array_key_exists('dry-run', $params) || CLI::getOption('dry-run') !== null;
        $all   = array_key_exists('all', $params) || CLI::getOption('all') !== null;
        $hours = (int) ($params['hours'] ?? CLI::getOption('hours') ?? 24) ?: 24;
        $since = date('Y-m-d H:i:s', time() - $hours * 3600);
        $db    = db_connect();

        $users = $db->table('users')->select('id, name, email')->where('active', 1);
        if (! $all) {
            $users->where('notify_digest', 1);
        }
        $users = $users->orderBy('id')->get()->getResultArray();
        if (! $users) {
            CLI::write('No users on the daily digest.', 'green');

            return;
        }
        if (! $dry && ! Mailer::configured()) {
            CLI::error('SMTP is not configured (Admin → Email settings); nothing sent. Use --dry-run to preview.');

            return;
        }

        $sent = 0;
        $skipped = 0;
        foreach ($users as $u) {
            $rows = $db->table('notifications')->where('user_id', (int) $u['id'])->where('read_at', null)
                ->where('created_at >=', $since)->orderBy('created_at', 'DESC')->limit(50)->get()->getResultArray();
            if (! $rows) {
                $skipped++;

                continue;
            }
            $subject = 'Your TicketHub digest — ' . count($rows) . ' new ' . (count($rows) === 1 ? 'notification' : 'notifications');
            $body    = $this->render($u, $rows, $hours);
            if ($dry) {
                CLI::write('— ' . $u['email'] . ': ' . $subject, 'yellow');
                foreach ($rows as $r) {
                    CLI::write('    • ' . $r['title'] . ($r['body'] ? ' — ' . $r['body'] : ''));
                }

                continue;
            }
            if (Mailer::send($u['email'], $subject, $body)) {
                $sent++;
                CLI::write('Sent to ' . $u['email'] . ' (' . count($rows) . ')', 'green');
            } else {
                CLI::error('Failed for ' . $u['email'] . ' — see writable/logs');
            }
        }
        CLI::write(($dry ? 'Dry run: ' : '') . count($users) . ' user(s) on the digest, ' . $skipped . ' with nothing new'
            . ($dry ? '' : ', ' . $sent . ' email(s) sent') . '.', 'green');
    }

    private function render(array $user, array $rows, int $hours): string
    {
        $lines = ['Hi ' . explode(' ', trim($user['name']))[0] . ',', '', 'Here is what happened in TicketHub in the last ' . $hours . ' hours:', ''];
        $byKind = [];
        foreach ($rows as $r) {
            $byKind[$r['kind']][] = $r;
        }
        $labels = ['reply' => 'Replies', 'assigned' => 'Assigned to you', 'escalated' => 'Escalations', 'approval' => 'Approvals waiting'];
        foreach ($byKind as $kind => $items) {
            $lines[] = strtoupper($labels[$kind] ?? ucfirst($kind));
            foreach ($items as $r) {
                $lines[] = '  • ' . $r['title'] . ($r['body'] ? ' — ' . mb_substr($r['body'], 0, 120) : '') . ($r['url'] ? "\n    " . $r['url'] : '');
            }
            $lines[] = '';
        }
        $lines[] = 'Open your notifications: ' . site_url('app/dashboard');
        $lines[] = 'Change how often you hear from us: ' . site_url('account/notifications');
        $lines[] = '';
        $lines[] = '— TicketHub';

        return implode("\n", $lines);
    }
}
