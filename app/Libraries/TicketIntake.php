<?php

namespace App\Libraries;

/**
 * Canonical ticket creation + email intake, shared by controllers,
 * the inbound webhook, and the Graph mailbox poller.
 */
class TicketIntake
{
    private $db;

    public function __construct(private ?int $actorId = null)
    {
        $this->db = db_connect();
        helper('tickethub');
    }

    private function userById(?int $id): ?array
    {
        return $id ? $this->db->table('users')->where('id', $id)->get()->getRowArray() : null;
    }

    private function nextTicketCode(string $type): string
    {
        $prefix = $type === 'Incident' ? 'INC' : 'SR';
        $row = $this->db->query(
            "SELECT MAX(CAST(SUBSTRING_INDEX(code,'-',-1) AS UNSIGNED)) AS n FROM tickets WHERE code LIKE ?",
            [$prefix . '-%']
        )->getRowArray();
        $n = (int) ($row['n'] ?? 0);
        if ($n === 0) {
            $n = $prefix === 'INC' ? 2100 : 4400;
        }

        return $prefix . '-' . ($n + 1);
    }

    /** First-match-wins routing. Returns [group_id, agent_id|null, priority|null, description|null, rule|null]. */
    private function route(string $subject, string $category, string $source): array
    {
        $rules = $this->db->table('routing_rules')->where('active', 1)->orderBy('position')->orderBy('id')->get()->getResultArray();
        foreach ($rules as $r) {
            $hit = match ($r['match_type']) {
                'Category'         => strcasecmp($r['match_value'], $category) === 0,
                'Subject contains' => mb_stripos($subject, $r['match_value']) !== false,
                'Source'           => strcasecmp($r['match_value'], $source) === 0,
                default            => false,
            };
            if ($hit) {
                $g = $this->db->table('groups')->where('id', $r['group_id'])->get()->getRowArray();
                $desc = 'Routed to ' . ($g['name'] ?? '?') . ' (' . strtolower($r['match_type']) . ' "' . $r['match_value'] . '")';

                return [(int) $r['group_id'], $r['agent_id'] ? (int) $r['agent_id'] : null, $r['priority'] ?: null, $desc, $r];
            }
        }

        return [(int) (Settings::get('default_group_id', '1') ?: 1), null, null, null, null];
    }

    /**
     * The active agent in a group carrying the fewest open tickets. Routing has
     * always been able to name one fixed person; that concentrates work on
     * whoever was named when the rule was written, regardless of load.
     *
     * Ties break on the lowest id so the choice is stable and testable.
     */
    private function leastBusyIn(int $groupId): ?int
    {
        $rows = $this->db->query(
            'SELECT u.id, (
                 SELECT COUNT(*) FROM tickets t
                 WHERE t.agent_id = u.id AND t.status IN ("New", "Open", "Pending")
             ) AS load_n
             FROM users u
             WHERE u.group_id = ? AND u.active = 1 AND u.role IN ("Administrator", "Supervisor", "Agent")
             ORDER BY load_n ASC, u.id ASC
             LIMIT 1',
            [$groupId]
        )->getRowArray();

        return $rows ? (int) $rows['id'] : null;
    }

    public function create(array $o): array
    {
        $type     = $o['type'] ?? 'Incident';
        $priority = $o['priority'] ?? 'Medium';
        $groupId  = $o['group_id'] ?? null;
        $agentId  = $o['agent_id'] ?? null;
        $routedBy = null;

        $routedRule = null;
        $wasRouted  = false;
        if (! $groupId) {
            $wasRouted = true;
            [$groupId, $routedAgent, $routedPriority, $routedBy, $routedRule] = $this->route(
                (string) $o['subject'],
                (string) ($o['category'] ?? 'Software'),
                (string) ($o['source'] ?? 'Portal')
            );
            $agentId ??= $routedAgent;
            if ($routedPriority) {
                $priority = $routedPriority;
            }
        }

        // Nobody named by routing: hand it to whoever in the team has least on.
        // Off by default so existing behaviour is unchanged until switched on.
        $balanced = false;
        if (! $agentId && $groupId && Settings::get('auto_assign', '0') === '1') {
            $agentId = $this->leastBusyIn((int) $groupId);
            $balanced = $agentId !== null;
        }

        [$fr, $res] = th_sla_targets($priority);
        // A catalog item advertises its own turnaround ("3 business days"). That is
        // a promise shown to the requester, so it overrides the priority default
        // rather than being decoration on the catalog card.
        if (! empty($o['sla'])) {
            $res = th_parse_duration((string) $o['sla'], $res);
        }
        $now       = date('Y-m-d H:i:s');
        $requester = $this->userById((int) $o['requester_id']);

        $data = [
            'code'         => $this->nextTicketCode($type),
            'subject'      => $o['subject'],
            'requester_id' => $o['requester_id'],
            'agent_id'     => $agentId,
            'group_id'     => $groupId,
            'status'       => $o['status'] ?? 'New',
            'priority'     => $priority,
            'type'         => $type,
            'category'     => $o['category'] ?? 'Software',
            'source'       => $o['source'] ?? 'Portal',
            'site'         => $requester['site'] ?? '—',
            'tags'         => json_encode($o['tags'] ?? []),
            'escalated'    => 0,
            // Walked against the fulfilling group's calendar, so a 4-hour target
            // raised at 17:00 on a Friday lands Monday morning, not Saturday.
            'fr_due'       => date('Y-m-d H:i:s', th_due_at(time(), $fr, $groupId)),
            'res_due'      => date('Y-m-d H:i:s', th_due_at(time(), $res, $groupId)),
            'created_at'   => $now,
            'updated_at'   => $now,
        ];
        $this->db->table('tickets')->insert($data);
        $data['id'] = $this->db->insertID();

        $this->db->table('ticket_messages')->insert([
            'ticket_id' => $data['id'], 'kind' => 'description', 'user_id' => $o['requester_id'],
            'body' => $o['body'], 'attachments' => json_encode($o['attachments'] ?? []), 'created_at' => $now,
        ]);
        if ($balanced) {
            $this->db->table('ticket_messages')->insert([
                'ticket_id' => $data['id'], 'kind' => 'system', 'user_id' => null,
                'body' => 'Auto-assigned to ' . ($this->userById((int) $agentId)['name'] ?? '?') . ' (fewest open tickets)',
                'attachments' => '[]', 'created_at' => $now,
            ]);
        }
        if ($routedBy) {
            $this->db->table('ticket_messages')->insert([
                'ticket_id' => $data['id'], 'kind' => 'system', 'user_id' => null,
                'body' => $routedBy, 'attachments' => '[]', 'created_at' => $now,
            ]);
        }
        if ($wasRouted) {
            $g = $this->db->table('groups')->where('id', $groupId)->get()->getRowArray();
            $this->db->table('automation_log')->insert([
                'kind' => 'routing',
                'rule_id' => $routedRule['id'] ?? null,
                'rule_name' => $routedRule ? ($routedRule['match_type'] . ' "' . $routedRule['match_value'] . '"') : '(fallback team)',
                'ticket_id' => $data['id'], 'ticket_code' => $data['code'],
                'trigger_event' => 'Ticket is created',
                'summary' => 'placed in ' . ($g['name'] ?? '?')
                    . ($routedRule && $routedRule['priority'] ? ', priority ' . $routedRule['priority'] : '')
                    . ($routedRule && $routedRule['agent_id'] ? ', auto-assigned' : ''),
                'created_at' => $now,
            ]);
        }

        try {
            Mailer::sendTemplate('Ticket created', $data, $requester ?? [], $agentId ? $this->userById($agentId) : null);
            if ($agentId && $agentId !== ($this->actorId ?: 0)) {
                Mailer::sendTemplate('Ticket assigned', $data, $requester ?? [], $this->userById($agentId));
            }
        } catch (\Throwable $e) {
            log_message('error', 'TicketIntake notify failed: {msg}', ['msg' => $e->getMessage()]);
        }

        try {
            (new AutomationEngine())->event('Ticket is created', (int) $data['id']);
        } catch (\Throwable $e) {
            log_message('error', 'Automation event failed: {msg}', ['msg' => $e->getMessage()]);
        }

        return $data;
    }

    /**
     * Store raw attachment bytes (from email intake, where there is no upload).
     * Returns the {n,f,s} descriptor used by ticket_messages.attachments, or null.
     */
    public static function storeRawAttachment(string $filename, string $bytes): ?array
    {
        $allowed = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf', 'txt', 'log', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'json', 'eml', 'msg'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (! in_array($ext, $allowed, true)) {
            log_message('info', 'Inbound attachment "{f}" skipped — type not allowed.', ['f' => $filename]);

            return null;
        }
        if (strlen($bytes) > 10 * 1024 * 1024) {
            log_message('info', 'Inbound attachment "{f}" skipped — larger than 10 MB.', ['f' => $filename]);

            return null;
        }
        $dir = WRITEPATH . 'uploads/tickets';
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        if (file_put_contents($dir . DIRECTORY_SEPARATOR . $stored, $bytes) === false) {
            return null;
        }

        return ['n' => mb_substr($filename, 0, 150), 'f' => $stored, 's' => strlen($bytes)];
    }

    /**
     * Shared email intake for the webhook and the Graph poller.
     * $attachments: list of {n,f,s} descriptors already stored on disk.
     * Returns ['ok' => bool, 'ticket' => code|null, 'action' => created|replied|skipped, 'reason' => ...].
     */
    public function inboundEmail(string $from, string $subject, string $text, array $attachments = []): array
    {
        if (preg_match('/<([^>]+)>/', $from, $m)) {
            $from = $m[1];
        }
        $from = strtolower(trim($from));
        $subject = trim($subject);
        $text = trim($text);
        if ($from === '' || ($subject === '' && $text === '')) {
            return ['ok' => false, 'ticket' => null, 'action' => 'skipped', 'reason' => 'empty message'];
        }

        $user = $this->db->table('users')->where('email', $from)->where('active', 1)->get()->getRowArray();
        if (! $user) {
            log_message('info', 'Inbound email from unknown sender {from} skipped.', ['from' => $from]);

            return ['ok' => false, 'ticket' => null, 'action' => 'skipped', 'reason' => 'unknown sender ' . $from];
        }

        $now = date('Y-m-d H:i:s');

        // Reply to an existing ticket?
        if (preg_match('/\b((?:INC|SR)-\d{3,6})\b/i', $subject, $m)) {
            $t = $this->db->table('tickets')->where('code', strtoupper($m[1]))->get()->getRowArray();
            // Only the requester or an agent may append to a ticket by quoting its
            // code — codes are sequential, so anyone could otherwise guess one.
            $isAgent = in_array($user['role'], ['Administrator', 'Supervisor', 'Agent'], true);
            if ($t && ! $isAgent && (int) $t['requester_id'] !== (int) $user['id']) {
                log_message('info', 'Inbound email referenced {code} from a non-participant; filing a new ticket instead.', ['code' => $t['code']]);
                $t = null;
            }
            if ($t) {
                $this->db->table('ticket_messages')->insert([
                    'ticket_id' => $t['id'], 'kind' => 'reply', 'user_id' => $user['id'],
                    'body' => $text !== '' ? $text : $subject,
                    'attachments' => json_encode($attachments), 'created_at' => $now,
                ]);
                $upd = ['updated_at' => $now];
                if ($t['status'] === 'Pending') {
                    $upd['status'] = 'Open';
                } elseif (! th_is_open($t)) {
                    $upd['status'] = 'Open';
                    $upd['resolved_at'] = null;
                }
                $this->db->table('tickets')->where('id', $t['id'])->update(th_status_change($t, $upd));

                return ['ok' => true, 'ticket' => $t['code'], 'action' => 'replied', 'reason' => ''];
            }
        }

        $t = $this->create([
            'subject' => $subject !== '' ? mb_substr($subject, 0, 250) : 'Email from ' . $user['name'],
            'body' => $text !== '' ? $text : '(no body)',
            'requester_id' => (int) $user['id'],
            'type' => 'Incident', 'source' => 'Email',
            'attachments' => $attachments,
        ]);

        return ['ok' => true, 'ticket' => $t['code'], 'action' => 'created', 'reason' => ''];
    }
}
