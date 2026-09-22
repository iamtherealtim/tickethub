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

    /**
     * Raw send. Returns true on success. With $html the message goes out as
     * HTML with $body as the plain-text alternative.
     */
    public static function send(string $to, string $subject, string $body, ?string $html = null): bool
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
            'mailType'   => $html !== null ? 'html' : 'text',
            'newline'    => "\r\n",
        ]);

        try {
            $email->setFrom(Settings::get('mail_from_email'), Settings::get('mail_from_name', 'TicketHub'));
            $email->setTo($to);
            $email->setSubject($subject);
            if ($html !== null) {
                $email->setMessage($html);
                $email->setAltMessage($body);
            } else {
                $email->setMessage($body);
            }
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
        // Rating links only exist for a resolved ticket with a token; elsewhere
        // the placeholders collapse to the portal ticket page.
        $token   = $ticket['csat_token'] ?? null;
        $rateUrl = $token ? site_url('portal/rate/' . $token . '/0') : site_url('portal/tickets/' . $ticket['code']);
        $lines   = [];
        foreach ([1 => 'Poor', 2 => 'Fair', 3 => 'OK', 4 => 'Good', 5 => 'Excellent'] as $n => $label) {
            $lines[] = $n . ' — ' . $label . ': ' . ($token ? site_url('portal/rate/' . $token . '/' . $n) : $rateUrl);
        }
        $rateLinks = implode("\n", $lines);

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
            '{{rate_url}}'         => $rateUrl,
            '{{rate_links}}'       => $rateLinks,
        ];
    }

    /**
     * Make sure a resolved ticket carries a CSAT token (minted the first time
     * the resolution email goes out) and return it. Null when the ticket
     * cannot be rated: not resolved, already rated, or not saved yet.
     */
    public static function csatToken(array &$ticket): ?string
    {
        if (empty($ticket['id']) || ! in_array($ticket['status'] ?? '', ['Resolved', 'Closed'], true)) {
            return null;
        }
        $db = db_connect();
        if (! $db->fieldExists('csat_token', 'tickets')) {
            return null;
        }
        $row = $db->table('tickets')->select('csat_token, csat_score')->where('id', (int) $ticket['id'])->get()->getRowArray();
        if (! $row || $row['csat_score'] !== null) {
            return null;
        }
        if (empty($row['csat_token'])) {
            $row['csat_token'] = bin2hex(random_bytes(24));
            $db->table('tickets')->where('id', (int) $ticket['id'])->update(['csat_token' => $row['csat_token']]);
        }
        $ticket['csat_token'] = $row['csat_token'];

        return $row['csat_token'];
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
     * $extra: ['message' => reply body] etc. $extra['to'] (list of addresses)
     * replaces the template's own addressing — escalation, for one, goes to the
     * group's supervisors rather than whoever the template names.
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
        $to = array_values(array_unique(array_filter(array_merge(
            isset($extra['to']) ? (array) $extra['to'] : self::recipients($tpl, $ticket, $requester, $agent),
            self::watchers((int) ($ticket['id'] ?? 0))
        ))));
        if (! $to) {
            return;
        }

        // The resolution email is the one place a CSAT token is minted: every
        // path that resolves a ticket ends up here.
        if ($trigger === 'Status → Resolved') {
            self::csatToken($ticket);
        }

        $vars = self::vars($ticket, $requester, $agent, $extra);

        $subject = strtr($tpl['subject'], $vars);
        $raw     = $tpl['body'] ?? ($subject . "\n\n" . site_url('portal/tickets/' . $ticket['code']));
        $body    = strtr($raw, $vars);

        // A Markdown reply goes out as HTML (rendered body inside the escaped
        // template text) with the plain text as the alternative part.
        $html = null;
        if ($trigger === 'Public reply sent' && ($extra['format'] ?? '') === 'markdown' && isset($extra['message'])) {
            $html = self::htmlBody($raw, $vars, (string) $extra['message']);
        }

        // Per-user opt-outs, when that module is installed.
        $to = self::filterByPrefs($to, $trigger);

        foreach ($to as $addr) {
            self::send($addr, $subject, $body, $html);
        }
    }

    /** Drop recipients who turned this trigger off in their notification preferences. */
    private static function filterByPrefs(array $to, string $trigger): array
    {
        if (! $to || ! class_exists(\App\Libraries\NotificationPrefs::class)) {
            return $to;
        }
        $ids = [];
        foreach (db_connect()->table('users')->select('id, email')->whereIn('email', $to)->get()->getResultArray() as $u) {
            $ids[strtolower($u['email'])] = (int) $u['id'];
        }

        return array_values(array_filter($to, static function ($addr) use ($ids, $trigger) {
            $id = $ids[strtolower($addr)] ?? null;
            try {
                return $id === null || \App\Libraries\NotificationPrefs::wants($id, $trigger);
            } catch (\Throwable $e) {
                return true;
            }
        }));
    }

    /**
     * HTML version of a text template: the template's own text is escaped
     * with line breaks kept, URLs become links, and {{message}} is the
     * rendered Markdown.
     */
    private static function htmlBody(string $rawTemplate, array $vars, string $markdown): string
    {
        helper('tickethub');
        $htmlVars = [];
        foreach ($vars as $k => $v) {
            $htmlVars[$k] = nl2br(esc((string) $v));
        }
        foreach (['{{ticket.url}}', '{{ticket.agent_url}}', '{{rate_url}}'] as $k) {
            if (isset($vars[$k])) {
                $htmlVars[$k] = '<a href="' . esc($vars[$k], 'attr') . '">' . esc($vars[$k]) . '</a>';
            }
        }
        $htmlVars['{{message}}'] = '<div style="margin:12px 0;padding:12px 14px;border-left:3px solid #CFE7E1;background:#F7FAF9">'
            . th_markdown($markdown) . '</div>';

        $inner = strtr(nl2br(esc($rawTemplate)), $htmlVars);

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
            . 'pre{background:#10141C;color:#E3E6EC;padding:10px 12px;border-radius:6px;overflow:auto}'
            . 'code{font-family:Menlo,Consolas,monospace;font-size:13px}'
            . 'blockquote{border-left:3px solid #E3E6EC;margin:8px 0;padding-left:10px;color:#5B6577}img{max-width:100%}'
            . '</style></head><body style="font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;font-size:14px;line-height:1.55;color:#10141C">'
            . '<div style="max-width:640px">' . $inner . '</div></body></html>';
    }
}
