<?php

namespace App\Libraries;

/**
 * Canonical ticket creation + email intake, shared by controllers,
 * the inbound webhook, and the Graph mailbox poller.
 */
class TicketIntake
{
    /** Name of the MySQL advisory lock serialising code generation + insert. */
    public const CODE_LOCK = 'tickethub_code';

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

    /**
     * Next free code for a type. Only meaningful while the caller holds
     * CODE_LOCK: two requests reading MAX(code) at the same instant otherwise
     * both get the same number and the second insert hits the unique key.
     */
    public static function nextTicketCode(string $type): string
    {
        $prefix = $type === 'Incident' ? 'INC' : 'SR';
        $row = db_connect()->query(
            "SELECT MAX(CAST(SUBSTRING_INDEX(code,'-',-1) AS UNSIGNED)) AS n FROM tickets WHERE code LIKE ?",
            [$prefix . '-%']
        )->getRowArray();
        $n = (int) ($row['n'] ?? 0);
        if ($n === 0) {
            $n = $prefix === 'INC' ? 2100 : 4400;
        }

        return $prefix . '-' . ($n + 1);
    }

    /**
     * Run $fn while holding the code lock. Waits up to 5 s; if the lock cannot
     * be had (or the server has no GET_LOCK) the work still runs — the unique
     * key on tickets.code is the last line of defence and insertLocked()
     * retries once on a duplicate.
     */
    public static function withCodeLock(callable $fn)
    {
        $db = db_connect();
        $held = false;
        try {
            $row  = $db->query('SELECT GET_LOCK(?, 5) AS got', [self::CODE_LOCK])->getRowArray();
            $held = (int) ($row['got'] ?? 0) === 1;
            if (! $held) {
                log_message('warning', 'TicketIntake: could not obtain the ticket-code lock within 5 s; proceeding unlocked.');
            }
        } catch (\Throwable $e) {
            log_message('warning', 'TicketIntake: GET_LOCK unavailable ({msg}); proceeding unlocked.', ['msg' => $e->getMessage()]);
        }
        try {
            return $fn();
        } finally {
            if ($held) {
                try {
                    $db->query('SELECT RELEASE_LOCK(?)', [self::CODE_LOCK]);
                } catch (\Throwable $e) {
                    // connection gone — the lock died with it
                }
            }
        }
    }

    /** Insert a ticket row with a freshly generated code; retries once on a duplicate code. */
    private function insertLocked(array $data): array
    {
        return self::withCodeLock(function () use ($data) {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $data['code'] = self::nextTicketCode($data['type']);
                try {
                    $this->db->table('tickets')->insert($data);
                    $data['id'] = (int) $this->db->insertID();

                    return $data;
                } catch (\Throwable $e) {
                    $dup = str_contains($e->getMessage(), 'Duplicate') || ($this->db->error()['code'] ?? 0) === 1062;
                    if (! $dup || $attempt === 1) {
                        throw $e;
                    }
                    log_message('warning', 'TicketIntake: duplicate code {code}, retrying once.', ['code' => $data['code']]);
                }
            }

            throw new \RuntimeException('Could not allocate a ticket code.');
        });
    }

    /* ---------- shared notification helpers (controllers + engine + intake) ---------- */

    /**
     * Persist an in-app notification for each user. Deduplicated, never throws,
     * and never notifies the actor about their own action.
     */
    public static function notifyUsers(array $userIds, string $kind, string $title, ?string $url = null, ?int $ticketId = null, ?int $actorId = null, string $body = ''): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if ($actorId) {
            $ids = array_values(array_filter($ids, static fn ($id) => $id !== $actorId));
        }
        if (! $ids) {
            return;
        }
        $now  = date('Y-m-d H:i:s');
        $rows = [];
        foreach ($ids as $id) {
            $rows[] = [
                'user_id' => $id, 'ticket_id' => $ticketId, 'kind' => mb_substr($kind, 0, 30),
                'title' => mb_substr($title, 0, 160), 'body' => $body !== '' ? mb_substr($body, 0, 255) : null,
                'url' => $url ? mb_substr($url, 0, 255) : null, 'created_at' => $now,
            ];
        }
        try {
            db_connect()->table('notifications')->insertBatch($rows);
        } catch (\Throwable $e) {
            log_message('error', 'notifyUsers failed: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    /** User ids watching a ticket. */
    public static function watcherIds(int $ticketId): array
    {
        try {
            return array_map('intval', array_column(
                db_connect()->table('ticket_watchers')->select('user_id')->where('ticket_id', $ticketId)->get()->getResultArray(),
                'user_id'
            ));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Supervisors and administrators in a group (active), as user rows. */
    public static function groupApprovers(?int $groupId): array
    {
        if (! $groupId) {
            return [];
        }

        return db_connect()->table('users')
            ->where('group_id', $groupId)->where('active', 1)
            ->whereIn('role', ['Administrator', 'Supervisor'])
            ->get()->getResultArray();
    }

    /**
     * Everything an escalation promises: email "Ticket escalated" to the group's
     * supervisors/administrators and the assignee, an in-app notification for
     * each, and the "updated" automation event. Shared by the escalate button
     * and the automation action so both keep the same promise.
     */
    public static function notifyEscalation(array $t, ?int $actorId = null, string $why = ''): void
    {
        $db        = db_connect();
        $requester = $db->table('users')->where('id', $t['requester_id'])->get()->getRowArray() ?? [];
        $agent     = ! empty($t['agent_id']) ? $db->table('users')->where('id', $t['agent_id'])->get()->getRowArray() : null;

        $targets = self::groupApprovers((int) ($t['group_id'] ?? 0));
        if ($agent) {
            $targets[] = $agent;
        }
        $emails = array_values(array_unique(array_filter(array_column($targets, 'email'))));
        $ids    = array_map('intval', array_column($targets, 'id'));

        try {
            Mailer::sendTemplate('Ticket escalated', $t, $requester, $agent, ['to' => $emails, 'message' => $why]);
        } catch (\Throwable $e) {
            log_message('error', 'Escalation mail failed: {msg}', ['msg' => $e->getMessage()]);
        }
        self::notifyUsers(
            $ids,
            'escalated',
            $t['code'] . ' escalated to ' . $t['priority'] . ' priority',
            site_url('app/tickets/' . $t['code']),
            (int) $t['id'],
            $actorId,
            $t['subject']
        );
        try {
            (new AutomationEngine())->event('Ticket is updated', (int) $t['id']);
        } catch (\Throwable $e) {
            log_message('error', 'Automation event failed: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    /** In-app notice to the assignee and watchers that a public reply landed. */
    public static function notifyReply(array $t, ?int $actorId, string $who): void
    {
        $ids = self::watcherIds((int) $t['id']);
        if (! empty($t['agent_id'])) {
            $ids[] = (int) $t['agent_id'];
        }
        self::notifyUsers($ids, 'reply', $who . ' replied on ' . $t['code'], site_url('app/tickets/' . $t['code']), (int) $t['id'], $actorId, $t['subject']);
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
        // Code is allocated and inserted under the same advisory lock.
        $data = $this->insertLocked($data);

        $descRow = [
            'ticket_id' => $data['id'], 'kind' => 'description', 'user_id' => $o['requester_id'],
            'body' => $o['body'], 'attachments' => json_encode($o['attachments'] ?? []), 'created_at' => $now,
        ];
        if (($o['format'] ?? '') === 'markdown' && self::hasMessageFormat()) {
            $descRow['format'] = 'markdown';
        }
        if (! empty($o['message_id'])) {
            $descRow['message_id'] = mb_substr((string) $o['message_id'], 0, 255);
        }
        $this->db->table('ticket_messages')->insert($descRow);
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
        if ($agentId) {
            self::notifyUsers([(int) $agentId], 'assigned', $data['code'] . ' assigned to you', site_url('app/tickets/' . $data['code']), (int) $data['id'], $this->actorId, $data['subject']);
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

    /* ---------- message format / email hygiene ---------- */

    /** Has the ticket_messages.format column been migrated in? Cached per request. */
    public static function hasMessageFormat(): bool
    {
        static $has = null;
        if ($has === null) {
            try {
                $has = db_connect()->fieldExists('format', 'ticket_messages');
            } catch (\Throwable $e) {
                $has = false;
            }
        }

        return $has;
    }

    /**
     * Cut the quoted history off an email reply: everything from the first
     * "On ... wrote:" line, a forwarded "From:" header block, an Outlook
     * "-----Original Message-----" divider, or the first run of ">" lines.
     */
    public static function stripQuotedHistory(string $text): string
    {
        $lines = preg_split('/\r?\n/', $text) ?: [];
        $keep  = [];
        $n     = count($lines);
        for ($i = 0; $i < $n; $i++) {
            $line = $lines[$i];
            $trim = trim($line);
            if (preg_match('/^-{2,}\s*Original Message\s*-{2,}$/i', $trim)
                || preg_match('/^_{5,}$/', $trim)
                || preg_match('/^On .{5,200}wrote:\s*$/su', $trim)
                || preg_match('/^(Le|Am|El) .{5,200}(a écrit|schrieb|escribió)\s*:\s*$/su', $trim)) {
                break;
            }
            // "On <date>, <name> wrote:" wrapped over two lines by the client.
            if (preg_match('/^On .{5,200}$/s', $trim) && $i + 1 < $n && preg_match('/wrote:\s*$/', trim($lines[$i + 1]))) {
                break;
            }
            // A forwarded/quoted header block: From: followed within a few lines by Sent:/Date:/To:/Subject:.
            if (preg_match('/^\*?From:\*?\s/i', $trim)) {
                $block = implode("\n", array_slice($lines, $i, 5));
                if (preg_match('/^\*?(Sent|Date|To|Subject):\*?\s/mi', $block)) {
                    break;
                }
            }
            if (str_starts_with($trim, '>')) {
                // Skip the quoted run, keep anything a top-poster wrote after it.
                while ($i < $n && (str_starts_with(trim($lines[$i]), '>') || trim($lines[$i]) === '')) {
                    $i++;
                }
                $i--;

                continue;
            }
            $keep[] = $line;
        }

        return trim(implode("\n", $keep));
    }

    /** The workspace visibility rule, for a user row rather than a session. */
    public static function agentCanSee(array $user, array $t): bool
    {
        if (($user['role'] ?? '') === 'Administrator') {
            return true;
        }
        $g = (int) ($user['group_id'] ?? 0);

        return ($g > 0 && (int) ($t['group_id'] ?? 0) === $g) || (int) ($t['agent_id'] ?? 0) === (int) $user['id'];
    }

    /* ---------- templates + recurring ---------- */

    /**
     * Raise a ticket from a template row: subject/body/type/priority/category/
     * group/agent come from the template, the task list is added afterwards.
     */
    public function createFromTemplate(array $tpl, int $requesterId, array $overrides = []): array
    {
        $t = $this->create($overrides + [
            'subject' => $tpl['subject'], 'body' => $tpl['body'],
            'requester_id' => $requesterId,
            'type' => $tpl['type'] ?: 'Incident', 'priority' => $tpl['priority'] ?: 'Medium',
            'category' => $tpl['category'] ?: 'Software',
            'group_id' => $tpl['group_id'] ? (int) $tpl['group_id'] : null,
            'agent_id' => $tpl['agent_id'] ? (int) $tpl['agent_id'] : null,
            'source' => 'Portal',
        ]);
        self::addTemplateTasks((int) $t['id'], $tpl);

        return $t;
    }

    /** Insert the template's task titles on a ticket (no owner). */
    public static function addTemplateTasks(int $ticketId, array $tpl): int
    {
        $tasks = json_decode((string) ($tpl['tasks'] ?? '[]'), true) ?: [];
        $rows  = [];
        foreach ($tasks as $title) {
            $title = trim((string) $title);
            if ($title !== '') {
                $rows[] = ['ticket_id' => $ticketId, 'title' => mb_substr($title, 0, 255), 'done' => 0, 'owner_id' => null];
            }
        }
        if ($rows) {
            db_connect()->table('ticket_tasks')->insertBatch($rows);
        }

        return count($rows);
    }

    /**
     * The run after $from for a schedule row, in the schedule's own timezone,
     * returned as a UTC "Y-m-d H:i:s". Weekly schedules land on `weekday`
     * (0 = Sunday … 6 = Saturday) and monthly ones on `day_of_month`,
     * clamped to the month's length; both at `at_time`.
     */
    public static function nextRun(array $r, ?\DateTimeImmutable $from = null): string
    {
        try {
            $tz = new \DateTimeZone($r['tz'] ?: 'UTC');
        } catch (\Throwable $e) {
            $tz = new \DateTimeZone('UTC');
        }
        $from ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $local = $from->setTimezone($tz);
        [$h, $m] = array_map('intval', explode(':', ($r['at_time'] ?: '09:00') . ':00'));
        $every = $r['every'] ?: 'week';
        $step  = max(1, (int) ($r['interval'] ?? 1));

        $cand = $local->setTime($h, $m, 0);
        for ($guard = 0; $guard < 400; $guard++) {
            if ($every === 'week' && $r['weekday'] !== null && $r['weekday'] !== '') {
                $wd   = (int) $r['weekday'] % 7;
                $diff = ($wd - (int) $cand->format('w') + 7) % 7;
                $cand = $cand->modify('+' . $diff . ' days')->setTime($h, $m, 0);
            } elseif ($every === 'month' && $r['day_of_month'] !== null && $r['day_of_month'] !== '') {
                $dom  = min(max(1, (int) $r['day_of_month']), (int) $cand->format('t'));
                $cand = $cand->setDate((int) $cand->format('Y'), (int) $cand->format('n'), $dom)->setTime($h, $m, 0);
            }
            if ($cand > $local) {
                return $cand->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
            $cand = match ($every) {
                'day'   => $cand->modify('+' . $step . ' days'),
                'month' => $cand->setDate((int) $cand->format('Y'), (int) $cand->format('n') + $step, 1),
                default => $cand->modify('+' . (7 * $step) . ' days'),
            };
            $cand = $cand->setTime($h, $m, 0);
        }

        return $from->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Raise every recurring ticket whose next_run_at has passed. Each row is
     * advanced in the same statement that claims it, so two overlapping runs
     * cannot both raise the same ticket; the cron lock is belt-and-braces.
     *
     * @return list<string> one line per action, for the cron report
     */
    public static function runDueRecurring(): array
    {
        $db = db_connect();
        if (! $db->tableExists('recurring_tickets')) {
            return [];
        }
        helper('tickethub');
        $now = date('Y-m-d H:i:s');
        $due = $db->query(
            'SELECT r.*, t.name AS template_name FROM recurring_tickets r JOIN ticket_templates t ON t.id = r.template_id
             WHERE r.active = 1 AND r.next_run_at <= ? ORDER BY r.next_run_at, r.id',
            [$now]
        )->getResultArray();

        $out = [];
        foreach ($due as $r) {
            $tpl = $db->table('ticket_templates')->where('id', (int) $r['template_id'])->get()->getRowArray();
            $next = self::nextRun($r);
            // Claim: only the run that moves next_run_at forward gets to create.
            $db->query('UPDATE recurring_tickets SET next_run_at = ?, last_run_at = ? WHERE id = ? AND next_run_at = ?', [$next, $now, (int) $r['id'], $r['next_run_at']]);
            if ($db->affectedRows() < 1) {
                continue;
            }
            if (! $tpl) {
                $out[] = 'Recurring #' . $r['id'] . ' skipped — template missing';

                continue;
            }
            $requester = $db->table('users')->where('id', (int) $r['requester_id'])->where('active', 1)->get()->getRowArray();
            if (! $requester) {
                $out[] = 'Recurring "' . $tpl['name'] . '" skipped — requester inactive';

                continue;
            }
            try {
                $t = (new self(null))->createFromTemplate($tpl, (int) $requester['id']);
                $db->table('ticket_messages')->insert([
                    'ticket_id' => $t['id'], 'kind' => 'system', 'user_id' => null,
                    'body' => 'Raised automatically from template "' . $tpl['name'] . '" (recurring)', 'attachments' => '[]', 'created_at' => $now,
                ]);
                $out[] = 'Recurring "' . $tpl['name'] . '" raised ' . $t['code'] . ' (next ' . $next . ' UTC)';
            } catch (\Throwable $e) {
                log_message('error', 'Recurring ticket #{id} failed: {msg}', ['id' => $r['id'], 'msg' => $e->getMessage()]);
                $out[] = 'Recurring "' . $tpl['name'] . '" failed: ' . $e->getMessage();
            }
        }

        return $out;
    }

    /* ---------- inbound email ---------- */

    /** Normalise a Message-ID / In-Reply-To token: strip angle brackets and whitespace. */
    private static function cleanMessageId(?string $id): ?string
    {
        $id = trim((string) $id, " \t\r\n<>");

        return $id !== '' ? mb_substr($id, 0, 255) : null;
    }

    /**
     * Is this mail something a mail system generated rather than a person?
     * Bounces, out-of-office and vacation replies would otherwise open tickets
     * — and, since we mail the requester on creation, answer themselves forever.
     */
    private static function looksAutomated(string $from, string $subject, array $headers): ?string
    {
        $h = [];
        foreach ($headers as $k => $v) {
            $h[strtolower(trim((string) $k))] = trim((string) $v);
        }
        $self = strtolower(trim(Settings::get('mail_from_email')));
        if ($self !== '' && $from === $self) {
            return 'sent from our own address';
        }
        if (isset($h['auto-submitted']) && strtolower($h['auto-submitted']) !== 'no') {
            return 'Auto-Submitted: ' . $h['auto-submitted'];
        }
        if (isset($h['x-auto-response-suppress'])) {
            return 'X-Auto-Response-Suppress present';
        }
        if (isset($h['precedence']) && in_array(strtolower($h['precedence']), ['bulk', 'auto_reply', 'junk', 'list'], true)) {
            return 'Precedence: ' . $h['precedence'];
        }
        $s = mb_strtolower(ltrim($subject));
        foreach (['automatic reply', 'out of office', 'autoreply', 'auto-reply', 'auto reply'] as $prefix) {
            if (str_starts_with($s, $prefix)) {
                return 'subject starts with "' . $prefix . '"';
            }
        }

        return null;
    }

    /** Find (or, per policy, create) the sender's user row. */
    private function senderUser(string $from, string $fromName): ?array
    {
        $user = $this->db->table('users')->where('email', $from)->where('active', 1)->get()->getRowArray();
        if ($user) {
            return $user;
        }
        if ($this->db->table('users')->where('email', $from)->countAllResults()) {
            log_message('warning', 'Inbound email from deactivated user {from} dropped.', ['from' => $from]);

            return null;
        }
        if (Settings::get('inbound_unknown_policy', 'create') !== 'create') {
            log_message('warning', 'Inbound email from unknown sender {from} dropped (inbound_unknown_policy=drop). Subject was not filed.', ['from' => $from]);

            return null;
        }
        $name = trim(preg_replace('/\s+/', ' ', $fromName));
        if ($name === '' || str_contains($name, '@')) {
            $name = ucwords(str_replace(['.', '_', '-'], ' ', explode('@', $from)[0]));
        }
        $now = date('Y-m-d H:i:s');
        try {
            $this->db->table('users')->insert([
                'name' => mb_substr($name, 0, 100), 'email' => mb_substr($from, 0, 150),
                // Unusable until reset: the account exists to own tickets, not to sign in.
                'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                'role' => 'Requester', 'active' => 1, 'color' => 'ink',
                'must_change_password' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Could not auto-create requester {from}: {msg}', ['from' => $from, 'msg' => $e->getMessage()]);

            return null;
        }
        $user = $this->db->table('users')->where('id', $this->db->insertID())->get()->getRowArray();
        Audit::log('user.autocreated', $from . ' (inbound email)', null);
        log_message('info', 'Inbound email: created requester {name} <{from}>.', ['name' => $name, 'from' => $from]);

        return $user;
    }

    /**
     * Shared email intake for the webhook and the Graph poller.
     *
     * $attachments: either a list of {n,f,s} descriptors already on disk, or a
     * callable returning that list — the callable form is preferred because it
     * is only invoked once the sender has been accepted, so a dropped mail
     * writes nothing to disk.
     *
     * $meta: message_id, in_reply_to, references (string|list), headers
     * (name => value), from_name.
     *
     * Returns ['ok' => bool, 'ticket' => code|null, 'action' => created|replied|skipped|ignored|duplicate, 'reason' => ...].
     */
    public function inboundEmail(string $from, string $subject, string $text, array|callable $attachments = [], array $meta = []): array
    {
        $fromName = (string) ($meta['from_name'] ?? '');
        if (preg_match('/^\s*"?([^"<]*)"?\s*<([^>]+)>/', $from, $m)) {
            $fromName = $fromName !== '' ? $fromName : trim($m[1]);
            $from = $m[2];
        }
        $from = strtolower(trim($from));
        $subject = trim($subject);
        $text = trim($text);
        $skip = static fn (string $action, string $reason) => ['ok' => false, 'ticket' => null, 'action' => $action, 'reason' => $reason];

        if ($from === '' || ($subject === '' && $text === '')) {
            return $skip('skipped', 'empty message');
        }

        // Loop guard first: nothing about an auto-reply deserves a user lookup.
        if ($why = self::looksAutomated($from, $subject, (array) ($meta['headers'] ?? []))) {
            log_message('info', 'Inbound email from {from} ignored — {why}.', ['from' => $from, 'why' => $why]);

            return $skip('ignored', 'automated mail (' . $why . ')');
        }

        $messageId = self::cleanMessageId($meta['message_id'] ?? null);
        if ($messageId && $this->db->table('ticket_messages')->where('message_id', $messageId)->countAllResults()) {
            log_message('info', 'Inbound email {id} already ingested; skipping.', ['id' => $messageId]);

            return $skip('duplicate', 'already ingested (' . $messageId . ')');
        }

        $user = $this->senderUser($from, $fromName);
        if (! $user) {
            return $skip('skipped', 'unknown or inactive sender ' . $from);
        }
        $isAgent = in_array($user['role'], ['Administrator', 'Supervisor', 'Agent'], true);
        $now     = date('Y-m-d H:i:s');

        // ---- threading: code in the subject, else In-Reply-To / References ----
        $t = null;
        if (preg_match('/\b((?:INC|SR)-\d{3,6})\b/i', $subject, $m)) {
            $t = $this->db->table('tickets')->where('code', strtoupper($m[1]))->get()->getRowArray();
        }
        if (! $t) {
            $refs = [];
            foreach ([(array) ($meta['in_reply_to'] ?? []), (array) ($meta['references'] ?? [])] as $list) {
                foreach ($list as $raw) {
                    foreach (preg_split('/\s+/', trim((string) $raw)) ?: [] as $token) {
                        if ($id = self::cleanMessageId($token)) {
                            $refs[] = $id;
                        }
                    }
                }
            }
            if ($refs) {
                $hit = $this->db->table('ticket_messages')->select('ticket_id')->whereIn('message_id', array_unique($refs))
                    ->orderBy('id', 'DESC')->get()->getRowArray();
                if ($hit) {
                    $t = $this->db->table('tickets')->where('id', (int) $hit['ticket_id'])->get()->getRowArray();
                }
            }
        }
        // Only the requester, a watcher or an agent may append to a ticket by
        // referencing it — codes are sequential, so anyone could otherwise guess one.
        if ($t && ! $isAgent && (int) $t['requester_id'] !== (int) $user['id']
            && ! in_array((int) $user['id'], self::watcherIds((int) $t['id']), true)) {
            log_message('info', 'Inbound email referenced {code} from a non-participant; filing a new ticket instead.', ['code' => $t['code']]);
            $t = null;
        }

        // ---- reopen window: an old closed ticket gets a fresh one, linked back ----
        $followUpOf = null;
        if ($t && ! th_is_open($t)) {
            $days   = max(0, (int) Settings::get('inbound_reopen_days', '5'));
            $closed = strtotime($t['resolved_at'] ?: $t['updated_at']);
            if ($closed && (time() - $closed) > $days * 86400) {
                $followUpOf = $t;
                $t = null;
            }
        }

        // Sender accepted and destination known: only now touch the disk.
        $atts = is_callable($attachments) ? (array) $attachments() : $attachments;

        if ($t) {
            // An agent answering from their mail client: the quoted history is
            // noise, and a first line of "#note" files it as a private note
            // rather than a reply the requester sees.
            $kind = 'reply';
            if ($isAgent) {
                $text = self::stripQuotedHistory($text);
                if (preg_match('/^\s*#note\b[ \t]*\r?\n?/i', $text)) {
                    $kind = 'note';
                    $text = trim((string) preg_replace('/^\s*#note\b[ \t]*\r?\n?/i', '', $text, 1));
                }
                if (! self::agentCanSee($user, $t)) {
                    log_message('warning', 'Inbound email: {who} replied to {code} outside their group scope; accepted because they were on the thread.', ['who' => $user['email'], 'code' => $t['code']]);
                }
            }
            $row = [
                'ticket_id' => $t['id'], 'kind' => $kind, 'user_id' => $user['id'],
                'body' => $text !== '' ? $text : $subject,
                'attachments' => json_encode($atts), 'created_at' => $now,
            ];
            if ($messageId) {
                $row['message_id'] = $messageId;
            }
            $this->db->table('ticket_messages')->insert($row);
            $upd = ['updated_at' => $now];
            if ($kind === 'reply') {
                if ($t['status'] === 'Pending' && ! $isAgent) {
                    $upd['status'] = 'Open';
                } elseif (! th_is_open($t)) {
                    $upd['status'] = 'Open';
                    $upd['resolved_at'] = null;
                } elseif ($isAgent && $t['status'] === 'New') {
                    $upd['status'] = 'Open';
                }
                if ($isAgent && ! $t['responded_at']) {
                    $upd['responded_at'] = $now;
                }
                if ($isAgent && empty($t['agent_id'])) {
                    $upd['agent_id'] = (int) $user['id'];
                }
            }
            $this->db->table('tickets')->where('id', $t['id'])->update(th_status_change($t, $upd));
            if (($upd['status'] ?? '') === 'Open' && ! th_is_open($t)) {
                $this->db->table('ticket_messages')->insert([
                    'ticket_id' => $t['id'], 'kind' => 'system', 'user_id' => null,
                    'body' => 'Reopened by an email reply from ' . $user['name'], 'attachments' => '[]', 'created_at' => $now,
                ]);
            }
            if (! empty($upd['agent_id'])) {
                $this->db->table('ticket_messages')->insert([
                    'ticket_id' => $t['id'], 'kind' => 'system', 'user_id' => null,
                    'body' => 'Assigned to ' . $user['name'], 'attachments' => '[]', 'created_at' => $now,
                ]);
            }
            $fresh = array_merge($t, $upd);
            if ($kind === 'reply') {
                if ($isAgent) {
                    // The same promise the reply box makes: the requester is emailed.
                    try {
                        Mailer::sendTemplate('Public reply sent', $fresh, $this->userById((int) $t['requester_id']) ?? [], $user, ['message' => $row['body']]);
                    } catch (\Throwable $e) {
                        log_message('error', 'Reply-by-email notify failed: {msg}', ['msg' => $e->getMessage()]);
                    }
                }
                self::notifyReply($fresh, (int) $user['id'], $user['name']);
            }
            try {
                (new AutomationEngine())->event('Ticket is updated', (int) $t['id']);
            } catch (\Throwable $e) {
                log_message('error', 'Automation event failed: {msg}', ['msg' => $e->getMessage()]);
            }

            return ['ok' => true, 'ticket' => $t['code'], 'action' => $kind === 'note' ? 'noted' : 'replied', 'reason' => ''];
        }

        $body = $text !== '' ? $text : '(no body)';
        if ($followUpOf) {
            $body = 'Follow-up to ' . $followUpOf['code'] . ' (closed ' . th_rel($followUpOf['resolved_at'] ?: $followUpOf['updated_at']) . ").\n\n" . $body;
        }
        $t = $this->create([
            'subject' => $subject !== '' ? mb_substr($subject, 0, 250) : 'Email from ' . $user['name'],
            'body' => $body,
            'requester_id' => $followUpOf ? (int) $followUpOf['requester_id'] : (int) $user['id'],
            'type' => $followUpOf['type'] ?? 'Incident', 'source' => 'Email',
            'category' => $followUpOf['category'] ?? 'Software',
            'group_id' => $followUpOf ? (int) $followUpOf['group_id'] : null,
            'attachments' => $atts,
            'message_id' => $messageId,
        ]);
        if ($followUpOf) {
            try {
                $this->db->table('ticket_links')->insert([
                    'from_id' => (int) $t['id'], 'to_id' => (int) $followUpOf['id'], 'kind' => 'related',
                    'created_by' => null, 'created_at' => $now,
                ]);
            } catch (\Throwable $e) {
                // already linked — harmless
            }
            $this->db->table('ticket_messages')->insert([
                'ticket_id' => $t['id'], 'kind' => 'system', 'user_id' => null,
                'body' => 'Opened from an email reply to ' . $followUpOf['code'] . ', which was closed more than '
                    . Settings::get('inbound_reopen_days', '5') . ' days ago',
                'attachments' => '[]', 'created_at' => $now,
            ]);
        }

        return ['ok' => true, 'ticket' => $t['code'], 'action' => 'created', 'reason' => ''];
    }
}
