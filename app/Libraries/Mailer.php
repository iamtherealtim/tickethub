<?php

namespace App\Libraries;

/**
 * Sends notification emails from the SMTP settings configured in the admin UI,
 * using the (editable) rows in email_templates. Failures are logged, never fatal —
 * a broken mail server must not block ticket work.
 */
class Mailer
{
    public static function configured(): bool
    {
        return Settings::get('mail_enabled') === '1' && Settings::get('mail_host') !== '';
    }

    /** Raw send. Returns true on success. */
    public static function send(string $to, string $subject, string $body): bool
    {
        if (! self::configured()) {
            log_message('info', 'Mailer: skipped "{subject}" to {to} — SMTP not enabled/configured.', ['subject' => $subject, 'to' => $to]);

            return false;
        }

        $email = service('email', null, false);
        $email->initialize([
            'protocol'   => 'smtp',
            'SMTPHost'   => Settings::get('mail_host'),
            'SMTPPort'   => (int) Settings::get('mail_port', '587'),
            'SMTPUser'   => Settings::get('mail_username'),
            'SMTPPass'   => Settings::get('mail_password'),
            'SMTPCrypto' => Settings::get('mail_encryption', 'tls') === 'none' ? '' : Settings::get('mail_encryption', 'tls'),
            'SMTPTimeout' => 5,
            'mailType'   => 'text',
            'newline'    => "\r\n",
        ]);

        try {
            $email->setFrom(Settings::get('mail_from_email'), Settings::get('mail_from_name', 'TicketHub'));
            $email->setTo($to);
            $email->setSubject($subject);
            $email->setMessage($body);
            if ($email->send(false)) {
                return true;
            }
            log_message('error', 'Mailer: send failed for "{subject}" to {to}: {debug}', [
                'subject' => $subject, 'to' => $to, 'debug' => strip_tags($email->printDebugger(['headers'])),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Mailer: exception sending to {to}: {msg}', ['to' => $to, 'msg' => $e->getMessage()]);
        }

        return false;
    }

    /** Placeholder map shared by templates and automation actions. */
    public static function vars(array $ticket, array $requester, ?array $agent, array $extra = []): array
    {
        return [
            '{{ticket.id}}'        => $ticket['code'],
            '{{ticket.subject}}'   => $ticket['subject'],
            '{{ticket.status}}'    => $ticket['status'],
            '{{ticket.priority}}'  => $ticket['priority'],
            '{{ticket.due}}'       => date('j M Y, H:i', strtotime($ticket['res_due'])),
            '{{ticket.url}}'       => site_url('portal/tickets/' . $ticket['code']),
            '{{ticket.agent_url}}' => site_url('app/tickets/' . $ticket['code']),
            '{{requester.name}}'   => $requester['name'] ?? '',
            '{{requester.first}}'  => explode(' ', $requester['name'] ?? '')[0],
            '{{agent.name}}'       => $agent['name'] ?? 'the service desk',
            '{{agent.first}}'      => explode(' ', $agent['name'] ?? 'there')[0],
            '{{message}}'          => $extra['message'] ?? '',
        ];
    }

    /**
     * Who a template addresses. 'Group' means the queue rather than one person:
     * the assignee owns it once there is one, otherwise every active agent in the
     * ticket group. That unassigned case is the one that matters — a ticket
     * nobody has picked up is exactly the ticket about to breach its SLA, and
     * aliasing 'Group' to the (absent) assignee sent those warnings nowhere.
     *
     * @return list<string> deduplicated addresses; empty means nobody to tell
     */
    public static function recipients(array $tpl, array $ticket, array $requester, ?array $agent): array
    {
        $emails = match ($tpl['recipient']) {
            'Agent' => [$agent['email'] ?? null],
            'Group' => ! empty($agent['email'])
                ? [$agent['email']]
                : array_column(db_connect()->table('users')
                    ->where('group_id', (int) ($ticket['group_id'] ?? 0))
                    ->where('active', 1)
                    ->whereIn('role', ['Administrator', 'Supervisor', 'Agent'])
                    ->get()->getResultArray(), 'email'),
            default => [$requester['email'] ?? null],
        };

        return array_values(array_unique(array_filter($emails)));
    }

    /** Email addresses of everyone copied on a ticket. */
    public static function watchers(int $ticketId): array
    {
        if (! $ticketId) {
            return [];
        }
        try {
            $rows = db_connect()->query(
                'SELECT u.email FROM ticket_watchers w JOIN users u ON u.id = w.user_id WHERE w.ticket_id = ? AND u.active = 1',
                [$ticketId]
            )->getResultArray();
        } catch (\Throwable $e) {
            log_message('error', 'Watcher lookup failed: {msg}', ['msg' => $e->getMessage()]);

            return [];
        }

        return array_values(array_filter(array_column($rows, 'email')));
    }

    /**
     * Send the template bound to a trigger event for a ticket.
     * $extra: ['message' => reply body] etc.
     */
    public static function sendTemplate(string $trigger, array $ticket, array $requester, ?array $agent, array $extra = []): void
    {
        if (! self::configured()) {
            return;
        }
        $tpl = db_connect()->table('email_templates')
            ->where('trigger_event', $trigger)->where('active', 1)->get()->getRowArray();
        if (! $tpl) {
            return;
        }

        // Watchers are copied on every ticket notification regardless of who the
        // template addresses — that is the whole point of watching.
        $to = array_values(array_unique(array_merge(
            self::recipients($tpl, $ticket, $requester, $agent),
            self::watchers((int) ($ticket['id'] ?? 0))
        )));
        if (! $to) {
            return;
        }

        $vars = self::vars($ticket, $requester, $agent, $extra);

        $subject = strtr($tpl['subject'], $vars);
        $body    = strtr($tpl['body'] ?? ($subject . "\n\n" . site_url('portal/tickets/' . $ticket['code'])), $vars);

        foreach ($to as $addr) {
            self::send($addr, $subject, $body);
        }
    }
}
