<?php

namespace App\Libraries;

/**
 * Executes structured automation rules.
 *
 * A rule = trigger (when_event) + conditions (ALL must match) + actions.
 *  - "Ticket is created" / "Ticket is updated": fired inline via event().
 *  - "Time is reached": evaluated by run() from `php spark tickets:cron`
 *    (or Admin → Run now); fires at most once per (rule, ticket).
 *
 * Condition fields: subject, description, category, priority, status, type,
 * source, group (name), tag, requester_email, hours_since_created,
 * hours_since_updated, hours_since_resolved, sla_pct.
 * Operators: contains, not_contains, equals, not_equals, starts_with, gte.
 *
 * Actions: set_status, set_priority, move_group, assign_agent, add_tag,
 * escalate, add_note, email_requester, email_agent.
 */
class AutomationEngine
{
    public const CONDITION_FIELDS = [
        'subject', 'description', 'category', 'priority', 'status', 'type', 'source',
        'group', 'tag', 'requester_email',
        'hours_since_created', 'hours_since_updated', 'hours_since_resolved', 'sla_pct',
    ];
    public const OPERATORS    = ['contains', 'not_contains', 'equals', 'not_equals', 'starts_with', 'gte'];
    public const ACTION_TYPES = ['set_status', 'set_priority', 'move_group', 'assign_agent', 'add_tag', 'escalate', 'add_note', 'email_requester', 'email_agent', 'email_template', 'webhook'];

    private $db;

    public function __construct()
    {
        $this->db = db_connect();
        helper('tickethub');
    }

    /* ---------- entry points ---------- */

    /** Cron pass: evaluate all "Time is reached" rules. Returns action lines. */
    public function run(): array
    {
        $out   = [];
        $rules = $this->db->table('automations')->where('active', 1)->where('when_event', 'Time is reached')->get()->getResultArray();
        if (! $rules) {
            return $out;
        }
        $tickets = $this->db->table('tickets')->get()->getResultArray();

        foreach ($rules as $rule) {
            foreach ($tickets as $t) {
                if ($this->alreadyRan((int) $rule['id'], (int) $t['id'])) {
                    continue;
                }
                if (! $this->matches($rule, $t)) {
                    continue;
                }
                $summary = $this->execute($rule, $t);
                $this->db->table('automation_runs')->insert([
                    'rule_id' => $rule['id'], 'ticket_id' => $t['id'], 'created_at' => date('Y-m-d H:i:s'),
                ]);
                $out[] = $t['code'] . ' — "' . $rule['name'] . '": ' . $summary;
            }
        }

        return $out;
    }

    /** Event pass for one ticket (Ticket is created / Ticket is updated). */
    public function event(string $trigger, int $ticketId): array
    {
        $out   = [];
        $rules = $this->db->table('automations')->where('active', 1)->where('when_event', $trigger)->get()->getResultArray();
        if (! $rules) {
            return $out;
        }
        foreach ($rules as $rule) {
            // Re-read inside the loop so later rules see earlier rules' changes.
            $t = $this->db->table('tickets')->where('id', $ticketId)->get()->getRowArray();
            if (! $t || ! $this->matches($rule, $t)) {
                continue;
            }
            $out[] = $t['code'] . ' — "' . $rule['name'] . '": ' . $this->execute($rule, $t);
        }

        return $out;
    }

    /**
     * POST the ticket to an external URL so automations can reach Slack, Teams,
     * PagerDuty or anything else that speaks HTTP. Guarded by the same outbound
     * URL check the admin integrations use, so a rule cannot be pointed at
     * localhost or cloud metadata.
     *
     * Failures are reported, never thrown: a dead endpoint must not stop the
     * rest of a rule from running.
     */
    private function postWebhook(string $url, array $t, array $requester, ?array $agent): string
    {
        $url = trim($url);
        if ($err = th_outbound_url_error($url)) {
            return 'webhook not sent (' . $err . ')';
        }

        $payload = [
            'event'     => 'automation',
            'ticket'    => [
                'code' => $t['code'], 'subject' => $t['subject'], 'status' => $t['status'],
                'priority' => $t['priority'], 'type' => $t['type'], 'category' => $t['category'],
                'url' => site_url('app/tickets/' . $t['code']),
            ],
            'requester' => ['name' => $requester['name'] ?? null, 'email' => $requester['email'] ?? null],
            'agent'     => $agent ? ['name' => $agent['name'], 'email' => $agent['email']] : null,
            'sent_at'   => date('c'),
        ];

        try {
            $res = service('curlrequest', null, null, false)->post($url, [
                'json'        => $payload,
                'timeout'     => 5,
                'http_errors' => false,
                'headers'     => ['User-Agent' => 'TicketHub-Automation/1.0'],
            ]);
            $code = $res->getStatusCode();

            return $code >= 200 && $code < 300
                ? 'webhook delivered (' . $code . ')'
                : 'webhook rejected (HTTP ' . $code . ')';
        } catch (\Throwable $e) {
            log_message('error', 'Webhook to {url} failed: {msg}', ['url' => $url, 'msg' => $e->getMessage()]);

            return 'webhook failed (' . mb_substr($e->getMessage(), 0, 60) . ')';
        }
    }

    private function alreadyRan(int $ruleId, int $ticketId): bool
    {
        return (bool) $this->db->table('automation_runs')->where('rule_id', $ruleId)->where('ticket_id', $ticketId)->countAllResults();
    }

    /* ---------- condition evaluation ---------- */

    private function matches(array $rule, array $t): bool
    {
        $conditions = json_decode($rule['conditions'] ?? '[]', true) ?: [];

        foreach ($conditions as $c) {
            if (! $this->test($c['field'] ?? '', $c['op'] ?? '', (string) ($c['value'] ?? ''), $t)) {
                return false;
            }
        }

        return true; // no conditions = match everything
    }

    private function fieldValue(string $field, array $t): string|float|null
    {
        switch ($field) {
            case 'subject':   return $t['subject'];
            case 'category':  return $t['category'];
            case 'priority':  return $t['priority'];
            case 'status':    return $t['status'];
            case 'type':      return $t['type'];
            case 'source':    return $t['source'];

            case 'description':
                $m = $this->db->table('ticket_messages')->where('ticket_id', $t['id'])->where('kind', 'description')->get()->getRowArray();

                return $m['body'] ?? '';

            case 'group':
                $g = $this->db->table('groups')->where('id', $t['group_id'])->get()->getRowArray();

                return $g['name'] ?? '';

            case 'tag':
                return implode(' ', json_decode($t['tags'] ?? '[]', true) ?: []);

            case 'requester_email':
                $u = $this->db->table('users')->where('id', $t['requester_id'])->get()->getRowArray();

                return $u['email'] ?? '';

            case 'hours_since_created':
                return (time() - strtotime($t['created_at'])) / 3600;

            case 'hours_since_updated':
                return (time() - strtotime($t['updated_at'])) / 3600;

            case 'hours_since_resolved':
                return $t['resolved_at'] ? (time() - strtotime($t['resolved_at'])) / 3600 : null;

            case 'sla_pct':
                $total = strtotime($t['res_due']) - strtotime($t['created_at']);

                return $total > 0 ? ((time() - strtotime($t['created_at'])) / $total) * 100 : null;

            default:
                return null;
        }
    }

    private function test(string $field, string $op, string $value, array $t): bool
    {
        $actual = $this->fieldValue($field, $t);
        if ($actual === null) {
            return false;
        }

        if ($op === 'gte') {
            return is_numeric($value) && (float) $actual >= (float) $value;
        }

        $a = mb_strtolower((string) $actual);
        $v = mb_strtolower(trim($value));

        return match ($op) {
            'contains'     => $v !== '' && str_contains($a, $v),
            'not_contains' => ! str_contains($a, $v),
            'equals'       => $a === $v,
            'not_equals'   => $a !== $v,
            'starts_with'  => $v !== '' && str_starts_with($a, $v),
            default        => false,
        };
    }

    /* ---------- action execution ---------- */

    private function execute(array $rule, array $t): string
    {
        $actions = json_decode($rule['actions'] ?? '[]', true) ?: [];
        $now  = date('Y-m-d H:i:s');
        $upd  = ['updated_at' => $now];
        $done = [];

        $requester = $this->db->table('users')->where('id', $t['requester_id'])->get()->getRowArray() ?? [];
        $agent     = $t['agent_id'] ? $this->db->table('users')->where('id', $t['agent_id'])->get()->getRowArray() : null;
        $vars      = Mailer::vars($t, $requester, $agent);

        foreach ($actions as $a) {
            $type  = $a['type'] ?? '';
            $value = (string) ($a['value'] ?? '');

            switch ($type) {
                case 'set_status':
                    if (isset(TH_STATUS[$value])) {
                        $upd['status'] = $value;
                        $upd['resolved_at'] = $value === 'Resolved' ? $now : ($value === 'Closed' ? ($t['resolved_at'] ?? $now) : null);
                        $upd = th_status_change($t, $upd);
                        $done[] = 'status → ' . $value;
                    }
                    break;

                case 'set_priority':
                    if (isset(TH_PRIORITY[$value])) {
                        $upd['priority'] = $value;
                        $done[] = 'priority → ' . $value;
                    }
                    break;

                case 'move_group':
                    $g = $this->db->table('groups')->where('id', (int) $value)->get()->getRowArray();
                    if ($g) {
                        $upd['group_id'] = (int) $value;
                        $done[] = 'moved to ' . $g['name'];
                    }
                    break;

                case 'assign_agent':
                    $u = $this->db->table('users')->where('id', (int) $value)->where('active', 1)->get()->getRowArray();
                    if ($u) {
                        $upd['agent_id'] = (int) $value;
                        $agent = $u;
                        $done[] = 'assigned to ' . $u['name'];
                    }
                    break;

                case 'add_tag':
                    $tag = strtolower(preg_replace('/\s+/', '-', trim($value)));
                    if ($tag !== '') {
                        $tags = json_decode($t['tags'] ?? '[]', true) ?: [];
                        if (! in_array($tag, $tags, true)) {
                            $tags[] = $tag;
                            $upd['tags'] = json_encode($tags);
                            $done[] = 'tagged "' . $tag . '"';
                        }
                    }
                    break;

                case 'escalate':
                    if (! (int) $t['escalated']) {
                        $upd['escalated'] = 1;
                        $done[] = 'escalated';
                    }
                    break;

                case 'add_note':
                    $this->db->table('ticket_messages')->insert([
                        'ticket_id' => $t['id'], 'kind' => 'note', 'user_id' => null,
                        'body' => strtr($value, $vars), 'attachments' => '[]', 'created_at' => $now,
                    ]);
                    $done[] = 'note added';
                    break;

                case 'email_requester':
                    if (! empty($requester['email'])) {
                        Mailer::send($requester['email'], strtr('Update on {{ticket.id}} — {{ticket.subject}}', $vars), strtr($value, $vars));
                        $done[] = 'emailed requester';
                    }
                    break;

                case 'email_agent':
                    if (! empty($agent['email'])) {
                        Mailer::send($agent['email'], strtr('{{ticket.id}} needs attention — {{ticket.subject}}', $vars), strtr($value, $vars));
                        $done[] = 'emailed assignee';
                    } else {
                        $done[] = 'no assignee to email';
                    }
                    break;

                case 'webhook':
                    $done[] = $this->postWebhook($value, $t, $requester, $agent);
                    break;

                case 'email_template':
                    // Subject/body come from Admin → Email templates, so admins can edit them.
                    $tpl = $this->db->table('email_templates')->where('id', (int) $value)->where('active', 1)->get()->getRowArray();
                    if (! $tpl) {
                        $done[] = 'email template missing or disabled';
                        break;
                    }
                    // Same addressing rules as the trigger-event mail, so a
                    // template reads the same whichever path sends it.
                    $to = Mailer::recipients($tpl, $t, $requester, $agent);
                    if ($to) {
                        $subject = strtr($tpl['subject'], $vars);
                        $body    = strtr($tpl['body'] ?: ($subject . "\n\n" . site_url('portal/tickets/' . $t['code'])), $vars);
                        $sent = 0;
                        foreach ($to as $addr) {
                            $sent += Mailer::send($addr, $subject, $body) ? 1 : 0;
                        }
                        // Report what actually left. Saying "sent" while SMTP is off
                        // makes the cron log useless for diagnosing silent mail.
                        $done[] = $sent > 0
                            ? 'sent template "' . $tpl['name'] . '" to ' . $sent . ' recipient' . ($sent === 1 ? '' : 's')
                            : 'template "' . $tpl['name'] . '" NOT sent (SMTP off or refused) — ' . count($to) . ' recipient' . (count($to) === 1 ? '' : 's') . ' resolved';
                    } else {
                        $done[] = 'template "' . $tpl['name'] . '" has no reachable ' . strtolower($tpl['recipient']);
                    }
                    break;
            }
        }

        if (count($upd) > 1) {
            $this->db->table('tickets')->where('id', $t['id'])->update($upd);
        }
        $summary = $done ? implode(', ', $done) : 'no applicable changes';
        $this->db->table('ticket_messages')->insert([
            'ticket_id' => $t['id'], 'kind' => 'system', 'user_id' => null,
            'body' => 'Automation "' . $rule['name'] . '": ' . $summary,
            'attachments' => '[]', 'created_at' => $now,
        ]);
        $this->db->query('UPDATE automations SET runs = runs + 1 WHERE id = ?', [$rule['id']]);
        $this->db->table('automation_log')->insert([
            'kind' => 'automation', 'rule_id' => $rule['id'], 'rule_name' => $rule['name'],
            'ticket_id' => $t['id'], 'ticket_code' => $t['code'],
            'trigger_event' => $rule['when_event'], 'summary' => mb_substr($summary, 0, 500),
            'created_at' => $now,
        ]);

        return $summary;
    }
}
