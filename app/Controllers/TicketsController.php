<?php

namespace App\Controllers;

use App\Libraries\TicketIntake;

class TicketsController extends BaseController
{
    private const SAVED_VIEWS = ['all', 'mine', 'group', 'unassigned', 'overdue', 'escalated', 'closed', 'every'];

    /** Views whose predicate lives in PHP (th_sla) rather than the query. */
    private const PHP_VIEWS = ['overdue'];

    /** List filters carried on the query string, saved views and the export link. */
    private const FILTER_KEYS = ['q', 'status', 'priority', 'type', 'category', 'source', 'group', 'agent', 'org', 'sort'];

    /** Organizations exist once another module's migration has added users.org_id. */
    private function orgFilterAvailable(): bool
    {
        static $ok = null;

        return $ok ??= $this->db->fieldExists('org_id', 'users') && $this->db->tableExists('organizations');
    }

    /** Views this agent can pick: their own, plus anything shared with the team. */
    private function savedViews(): array
    {
        return $this->db->query(
            'SELECT v.*, u.name AS owner FROM saved_views v JOIN users u ON u.id = v.user_id
             WHERE v.user_id = ? OR v.shared = 1 ORDER BY v.shared ASC, v.name ASC',
            [(int) $this->me['id']]
        )->getResultArray();
    }

    public function saveView()
    {
        $name = trim((string) $this->request->getPost('name'));
        if ($name === '') {
            $this->toast('Name the view', 'warn');

            return redirect()->back();
        }

        // Store the filter query rather than parsed columns: whatever filters the
        // list grows later are captured automatically.
        $qs = [];
        foreach (array_merge(['view'], self::FILTER_KEYS) as $k) {
            $v = $this->request->getPost($k);
            if ($v !== null && $v !== '') {
                $qs[$k] = $v;
            }
        }

        $this->db->table('saved_views')->insert([
            'user_id' => (int) $this->me['id'],
            'name' => mb_substr($name, 0, 40),
            'query' => mb_substr(http_build_query($qs), 0, 500),
            'shared' => $this->request->getPost('shared') ? 1 : 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->toast('View "' . $name . '" saved');

        return redirect()->to('/app/tickets?' . http_build_query($qs));
    }

    public function deleteView(int $id)
    {
        // Own views only — a shared view is still owned by whoever made it.
        $this->db->table('saved_views')->where('id', $id)->where('user_id', (int) $this->me['id'])->delete();
        $this->toast($this->db->affectedRows() ? 'View removed' : 'That view is not yours to remove',
            $this->db->affectedRows() ? 'ok' : 'warn');

        return redirect()->to('/app/tickets');
    }

    /** Saved-view predicate as SQL. The PHP-only views get their open-state pre-filter here. */
    private function applyViewWhere($b, string $view): void
    {
        $me      = (int) $this->me['id'];
        $myGroup = (int) ($this->me['group_id'] ?? 0);
        switch ($view) {
            case 'mine':       $b->whereIn('status', TH_OPEN_STATES)->where('agent_id', $me); break;
            case 'group':      $b->whereIn('status', TH_OPEN_STATES)->where('group_id', $myGroup); break;
            case 'unassigned': $b->whereIn('status', TH_OPEN_STATES)->where('agent_id', null); break;
            case 'escalated':  $b->whereIn('status', TH_OPEN_STATES)->where('escalated', 1); break;
            case 'closed':     $b->whereIn('status', ['Resolved', 'Closed']); break;
            case 'every':      break;
            default:           $b->whereIn('status', TH_OPEN_STATES); // all, overdue
        }
    }

    /** One query for every saved-view badge. */
    private function viewCounts(): array
    {
        $me      = (int) $this->me['id'];
        $myGroup = (int) ($this->me['group_id'] ?? 0);
        $now     = $this->db->escape(date('Y-m-d H:i:s'));
        $open    = "status IN ('New','Open','Pending')";
        $row = $this->scopeWhere($this->db->table('tickets'))->select(
            "COALESCE(SUM($open),0) AS c_all,
             COALESCE(SUM($open AND agent_id = $me),0) AS c_mine,
             COALESCE(SUM($open AND group_id = $myGroup),0) AS c_group,
             COALESCE(SUM($open AND agent_id IS NULL),0) AS c_unassigned,
             COALESCE(SUM($open AND res_due <= $now),0) AS c_overdue,
             COALESCE(SUM($open AND escalated = 1),0) AS c_escalated,
             COALESCE(SUM(status IN ('Resolved','Closed')),0) AS c_closed,
             COUNT(*) AS c_every",
            false
        )->get()->getRowArray() ?? [];

        $counts = [];
        foreach (self::SAVED_VIEWS as $v) {
            $counts[$v] = (int) ($row['c_' . $v] ?? 0);
        }

        return $counts;
    }

    /**
     * The list, filtered in SQL. Returns [rows, view, counts, total, page].
     * With $paginate the rows are one page (LIMIT/OFFSET unless a PHP-side view
     * predicate is active, in which case the page is sliced after filtering).
     */
    private function filtered(bool $paginate = true, int $perPage = 25): array
    {
        $r    = $this->request;
        $view = in_array($r->getGet('view'), self::SAVED_VIEWS, true) ? $r->getGet('view') : 'all';

        $b = $this->scopeWhere($this->db->table('tickets'));
        $this->applyViewWhere($b, $view);

        foreach (['status', 'priority', 'type', 'category', 'source'] as $k) {
            if (($v = (string) $r->getGet($k)) !== '') {
                $b->where($k, $v);
            }
        }
        if ($g = (int) $r->getGet('group')) {
            $b->where('group_id', $g);
        }
        if (($a = (string) $r->getGet('agent')) !== '') {
            $a === 'none' ? $b->where('agent_id', null) : $b->where('agent_id', (int) $a);
        }
        // Organization: a property of the requester, so it is applied through
        // the users row rather than a column on tickets.
        if (($org = (int) $r->getGet('org')) && $this->orgFilterAvailable()) {
            $b->where('requester_id IN (SELECT id FROM users WHERE org_id = ' . $org . ')', null, false);
        }
        if ($q = trim((string) $r->getGet('q'))) {
            $like = '%' . $this->db->escapeLikeString($q) . '%';
            $b->groupStart()
                ->like('subject', $q)->orLike('code', $q)->orLike('tags', $q)
                ->orWhere('requester_id IN (SELECT id FROM users WHERE name LIKE ' . $this->db->escape($like) . " ESCAPE '!')", null, false)
                ->orWhere('id IN (SELECT ticket_id FROM ticket_messages WHERE kind = \'description\' AND body LIKE ' . $this->db->escape($like) . " ESCAPE '!')", null, false)
                ->groupEnd();
        }

        $phpFilter = in_array($view, self::PHP_VIEWS, true);
        $total     = $phpFilter ? 0 : (int) $b->countAllResults(false);

        $sort = $r->getGet('sort') ?: 'sla';
        match ($sort) {
            'created'  => $b->orderBy('created_at', 'DESC'),
            'updated'  => $b->orderBy('updated_at', 'DESC'),
            'priority' => $b->orderBy("FIELD(priority,'Urgent','High','Medium','Low')", '', false)->orderBy('res_due', 'ASC'),
            default    => $b->orderBy('res_due', 'ASC'),
        };
        $b->orderBy('id', 'DESC');

        $page = max(1, (int) ($r->getGet('page') ?: 1));
        if (! $phpFilter) {
            if ($paginate) {
                $page = min($page, max(1, (int) ceil($total / $perPage)));
                $b->limit($perPage, ($page - 1) * $perPage);
            }
            $list = $b->get()->getResultArray();
        } else {
            $list = $b->get()->getResultArray();
            if ($view === 'overdue') {
                $list = array_values(array_filter($list, static fn ($t) => th_sla($t)['remaining'] <= 0));
            }
            $total = count($list);
            if ($paginate) {
                $page = min($page, max(1, (int) ceil($total / $perPage)));
                $list = array_slice($list, ($page - 1) * $perPage, $perPage);
            }
        }

        return [$list, $view, $this->viewCounts(), $total, $page];
    }

    public function index()
    {
        $perPage = 25;
        [$list, $view, $counts, $total, $page] = $this->filtered(true, $perPage);

        // last message per ticket for the row meta
        $lastMsgs = [];
        $ids = array_column($list, 'id');
        if ($ids) {
            foreach ($this->db->table('ticket_messages')->whereIn('ticket_id', $ids)->orderBy('created_at')->orderBy('id')->get()->getResultArray() as $m) {
                $lastMsgs[(int) $m['ticket_id']] = $m;
            }
        }

        $filters = [];
        foreach (self::FILTER_KEYS as $k) {
            $filters[$k] = (string) $this->request->getGet($k);
        }
        $filters['sort'] = $filters['sort'] ?: 'sla';

        return view('agent/tickets', $this->agentShared() + [
            'title' => 'Tickets', 'nav' => 'tickets',
            'list' => $list, 'view' => $view, 'counts' => $counts,
            'savedViews' => $this->savedViews(),
            'page' => $page, 'perPage' => $perPage, 'total' => $total,
            'users' => $this->users(), 'lastMsgs' => $lastMsgs,
            'filters' => $filters,
            'orgs' => $this->orgFilterAvailable() ? $this->db->table('organizations')->orderBy('name')->get()->getResultArray() : [],
        ]);
    }

    public function export()
    {
        [$list] = $this->filtered(false);
        $users  = $this->users();
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Code', 'Subject', 'Requester', 'Assignee', 'Status', 'Priority', 'Type', 'Category', 'Source', 'Created', 'Resolution due', 'SLA state']);
        foreach ($list as $t) {
            fputcsv($out, [
                $t['code'], $t['subject'],
                $users[(int) $t['requester_id']]['name'] ?? '',
                $t['agent_id'] ? ($users[(int) $t['agent_id']]['name'] ?? '') : 'Unassigned',
                $t['status'], $t['priority'], $t['type'], $t['category'], $t['source'],
                $t['created_at'], $t['res_due'], th_sla($t)['state'],
            ]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $this->response
            ->setHeader('Content-Type', 'text/csv')
            ->setHeader('Content-Disposition', 'attachment; filename="tickethub-tickets-' . date('Ymd-His') . '.csv"')
            ->setBody($csv);
    }

    public function create()
    {
        $p = $this->request->getPost();
        if (empty($p['subject']) || empty($p['body'])) {
            $this->toast('Subject and description are needed', 'warn');

            return redirect()->back();
        }
        $agentId = ($p['agent_id'] ?? '') !== '' ? (int) $p['agent_id'] : null;
        $groupId = ($p['group_id'] ?? '') !== '' ? (int) $p['group_id'] : null; // null = route automatically
        if (! $this->canAssignTo($agentId)) {
            $this->toast('You cannot assign tickets to that person', 'warn');

            return redirect()->back();
        }
        if ($groupId !== null && ! $this->canUseGroup($groupId)) {
            $this->toast('You cannot file tickets into that group', 'warn');

            return redirect()->back();
        }
        $t = $this->createTicket([
            'subject' => $p['subject'], 'body' => $p['body'],
            'requester_id' => (int) $p['requester_id'],
            // Coalesced: any caller that omits a key (API, a trimmed form) should
            // get the default, not a 500.
            'agent_id' => $agentId,
            'group_id' => $groupId,
            'priority' => $p['priority'] ?? 'Medium', 'type' => $p['type'] ?? 'Incident',
            'category' => $p['category'] ?? 'Software', 'source' => $p['source'] ?? 'Portal',
            'attachments' => $this->storeUploads(),
        ]);
        $this->saveCustomFields((int) $t['id'], 'agents');
        // Started from a template: its task list comes along.
        if ($tplId = (int) ($p['template_id'] ?? 0)) {
            $tpl = $this->db->table('ticket_templates')->where('id', $tplId)->get()->getRowArray();
            if ($tpl && TicketIntake::addTemplateTasks((int) $t['id'], $tpl)) {
                $this->addSystemNote((int) $t['id'], 'Tasks added from template "' . $tpl['name'] . '"');
            }
        }
        $this->toast($t['code'] . ' created');

        return redirect()->to('/app/tickets/' . $t['code']);
    }

    public function show(string $code)
    {
        $t = $this->ticketScoped($code);
        if (! $t) {
            $this->toast('Ticket not found — it may have been merged or deleted', 'warn');

            return redirect()->to('/app/tickets');
        }

        $messages = $this->db->table('ticket_messages')->where('ticket_id', $t['id'])->orderBy('created_at')->orderBy('id')->get()->getResultArray();
        $tasks    = $this->db->table('ticket_tasks')->where('ticket_id', $t['id'])->orderBy('id')->get()->getResultArray();
        $assetIds = array_column($this->db->table('ticket_assets')->where('ticket_id', $t['id'])->get()->getResultArray(), 'asset_id');
        $linkedAssets = $assetIds ? $this->db->table('assets')->whereIn('id', $assetIds)->get()->getResultArray() : [];
        $allAssets = $this->db->table('assets')->orderBy('name')->get()->getResultArray();
        $requesterCount = $this->db->table('tickets')->where('requester_id', $t['requester_id'])->countAllResults();
        $slas = $this->db->table('slas')->get()->getResultArray();
        $slaName = 'Default policy';
        foreach ($slas as $s) {
            if (str_contains($s['name'], $t['priority'])) {
                $slaName = $s['name'];
                break;
            }
        }

        $links = $this->ticketLinks((int) $t['id']);
        $timeEntries = $this->db->query(
            'SELECT e.*, u.name AS who FROM ticket_time_entries e JOIN users u ON u.id = e.user_id
             WHERE e.ticket_id = ? ORDER BY e.spent_on DESC, e.id DESC',
            [(int) $t['id']]
        )->getResultArray();
        $watchers = $this->db->query(
            'SELECT u.* FROM ticket_watchers w JOIN users u ON u.id = w.user_id WHERE w.ticket_id = ? ORDER BY u.name',
            [(int) $t['id']]
        )->getResultArray();
        $approval = $this->pendingApproval((int) $t['id']);
        $approvalHistory = $this->db->table('ticket_approvals')->where('ticket_id', $t['id'])->orderBy('id')->get()->getResultArray();

        // Custom-field values keyed by field id, for the editable Details card.
        $fieldValueMap = [];
        foreach ($this->db->table('ticket_field_values')->where('ticket_id', $t['id'])->get()->getResultArray() as $fv) {
            $fieldValueMap[(int) $fv['field_id']] = (string) $fv['value'];
        }

        // Change linkage is another migration's column; the sidebar only offers
        // it once that has run.
        $hasChanges = $this->db->fieldExists('change_id', 'tickets');
        $changes = $hasChanges
            ? $this->db->table('changes')->whereNotIn('state', ['Completed', 'Rejected', 'Cancelled'])->orderBy('code')->get()->getResultArray()
            : [];
        if ($hasChanges && ! empty($t['change_id'])) {
            // Keep the linked change selectable even when it has since closed.
            $found = false;
            foreach ($changes as $c) {
                if ((int) $c['id'] === (int) $t['change_id']) {
                    $found = true;
                    break;
                }
            }
            if (! $found && ($cur = $this->db->table('changes')->where('id', (int) $t['change_id'])->get()->getRowArray())) {
                $changes[] = $cur;
            }
        }

        return view('agent/ticket', $this->agentShared() + [
            'title' => $t['code'], 'nav' => 'tickets',
            'approval' => $approval, 'approvalHistory' => $approvalHistory, 'watchers' => $watchers,
            'timeEntries' => $timeEntries, 'links' => $links,
            'timeTotal' => array_sum(array_column($timeEntries, 'minutes')),
            'canDecide' => in_array($this->me['role'] ?? '', ['Administrator', 'Supervisor'], true),
            'canTemplate' => in_array($this->me['role'] ?? '', ['Administrator', 'Supervisor'], true) && $this->db->tableExists('ticket_templates'),
            'cannedScoped' => $this->db->fieldExists('scope', 'canned_responses'),
            't' => $t, 'messages' => $messages, 'tasks' => $tasks,
            'linkedAssets' => $linkedAssets, 'allAssets' => $allAssets,
            'users' => $this->users(), 'requesterCount' => $requesterCount,
            'slaName' => $slaName,
            'problems' => $this->db->table('problems')->orderBy('code')->get()->getResultArray(),
            'hasChanges' => $hasChanges, 'changes' => $changes,
            'fieldValueMap' => $fieldValueMap,
            'fieldValues' => $this->db->query(
                'SELECT f.label, v.value FROM ticket_field_values v JOIN ticket_fields f ON f.id = v.field_id WHERE v.ticket_id = ? ORDER BY f.id',
                [(int) $t['id']]
            )->getResultArray(),
        ]);
    }

    public function message(string $code)
    {
        $t = $this->ticketScoped($code);
        if (! $t) {
            return redirect()->to('/app/tickets');
        }
        $body = trim((string) $this->request->getPost('body'));
        $kind = $this->request->getPost('kind') === 'note' ? 'note' : 'reply';
        $atts = $this->storeUploads();
        if ($body === '' && ! $atts) {
            $this->toast('Nothing to send yet', 'warn');

            return redirect()->back();
        }
        if ($body === '') {
            $body = '(attachment)';
        }

        $now = date('Y-m-d H:i:s');

        // The status side-effects depend on the row as it is right now, so the
        // read and the write share a transaction and a row lock.
        $this->db->transStart();
        $t = $this->lockTicket((int) $t['id']) ?? $t;
        $row = [
            'ticket_id' => $t['id'], 'kind' => $kind, 'user_id' => $this->me['id'],
            'body' => $body, 'attachments' => json_encode($atts), 'created_at' => $now,
        ];
        if (TicketIntake::hasMessageFormat()) {
            $row['format'] = 'markdown';
        }
        $this->db->table('ticket_messages')->insert($row);
        $upd = ['updated_at' => $now];

        if (! $t['agent_id']) {
            $upd['agent_id'] = $this->me['id'];
            $this->addSystemNote((int) $t['id'], 'Assigned to ' . $this->me['name']);
        }
        if ($kind === 'reply') {
            if (! $t['responded_at']) {
                $upd['responded_at'] = $now;
            }
            if ($t['status'] === 'New') {
                $upd['status'] = 'Open';
            }
        }
        if ($this->request->getPost('resolve_on_send')) {
            $upd['status'] = 'Resolved';
            $upd['resolved_at'] = $now;
            $this->addSystemNote((int) $t['id'], 'Status changed to Resolved');
        }
        $upd = th_status_change($t, $upd);
        $this->db->table('tickets')->where('id', $t['id'])->update($upd);
        $this->db->transComplete();

        $fresh = array_merge($t, $upd);
        if ($kind === 'reply') {
            $this->notify('Public reply sent', $fresh, ['message' => $body, 'format' => $row['format'] ?? 'text']);
            TicketIntake::notifyReply($fresh, (int) $this->me['id'], $this->me['name']);
        }
        if (($upd['status'] ?? '') === 'Resolved') {
            $this->notify('Status → Resolved', $fresh);
        }
        $this->fireUpdated((int) $t['id']);
        $this->toast($kind === 'reply' ? 'Reply sent to requester' : 'Private note saved');

        return redirect()->to('/app/tickets/' . $code);
    }

    /**
     * resolved_at follows the status: set when resolving, kept when closing a
     * resolved ticket (closing is not a second resolution), cleared only when
     * the ticket goes back to an open state.
     */
    private function resolvedAtFor(array $t, string $status, string $now): array
    {
        if ($status === 'Resolved') {
            return ['resolved_at' => $now];
        }
        if (in_array($status, TH_OPEN_STATES, true)) {
            return ['resolved_at' => null];
        }
        if ($status === 'Closed' && empty($t['resolved_at'])) {
            return ['resolved_at' => $now]; // closed straight from open: this is the resolution
        }

        return [];
    }

    public function update(string $code)
    {
        $t = $this->ticketScoped($code);
        if (! $t) {
            return redirect()->to('/app/tickets');
        }
        $field = (string) $this->request->getPost('field');
        $value = (string) $this->request->getPost('value');
        $now   = date('Y-m-d H:i:s');
        $back  = redirect()->to('/app/tickets/' . $code);

        $this->db->transStart();
        $t     = $this->lockTicket((int) $t['id']) ?? $t;
        $upd   = ['updated_at' => $now];
        $notes = [];      // system notes to write with the update
        $after = [];      // notifications, sent once the transaction is done
        $fail  = function (string $msg) use ($back) {
            $this->db->transComplete(); // nothing written yet
            $this->toast($msg, 'warn');

            return $back;
        };

        switch ($field) {
            case 'agent':
                $agentId = $value !== '' ? (int) $value : null;
                if (! $this->canAssignTo($agentId)) {
                    return $fail('You cannot assign this ticket to that person');
                }
                $upd['agent_id'] = $agentId;
                $notes[] = $agentId ? 'Assigned to ' . ($this->userById($agentId)['name'] ?? '?') : 'Unassigned';
                if ($agentId && $agentId !== (int) $this->me['id']) {
                    $after[] = function () use ($t, $upd, $agentId) {
                        $fresh = array_merge($t, $upd);
                        $this->notify('Ticket assigned', $fresh);
                        $this->notifyUsers([$agentId], 'assigned', $t['code'] . ' assigned to you by ' . $this->me['name'], site_url('app/tickets/' . $t['code']), (int) $t['id'], $t['subject']);
                    };
                }
                break;

            case 'group':
                $groupId = (int) $value;
                if (! $this->canUseGroup($groupId, $t)) {
                    return $fail('You cannot move this ticket into that group');
                }
                $upd['group_id'] = $groupId;
                $g = $this->db->table('groups')->where('id', $groupId)->get()->getRowArray();
                $notes[] = 'Moved to ' . ($g['name'] ?? '?');
                break;

            case 'status':
                if (! isset(TH_STATUS[$value])) {
                    return $fail('That is not a valid status');
                }
                $upd['status'] = $value;
                $upd += $this->resolvedAtFor($t, $value, $now);
                $upd = th_status_change($t, $upd);
                $notes[] = 'Status changed to ' . $value;
                if ($value === 'Resolved') {
                    $after[] = fn () => $this->notify('Status → Resolved', array_merge($t, $upd));
                }
                break;

            case 'priority':
            case 'type':
            case 'category':
                // Whitelist: these values are rendered into class names and attributes.
                $allowed = match ($field) {
                    'priority' => array_keys(TH_PRIORITY),
                    'type'     => ['Incident', 'Service request'],
                    default    => TH_CATEGORIES,
                };
                if (! in_array($value, $allowed, true)) {
                    return $fail('That is not a valid ' . $field);
                }
                $upd[$field] = $value;
                $notes[] = ucfirst($field) . ' set to ' . $value;
                break;

            case 'subject':
                if (trim($value) === '') {
                    return $fail('The subject cannot be empty');
                }
                $upd['subject'] = mb_substr(trim($value), 0, 250);
                $notes[] = 'Subject edited';
                break;

            case 'problem':
                $upd['problem_id'] = $value !== '' ? (int) $value : null;
                if ($value !== '') {
                    $p = $this->db->table('problems')->where('id', (int) $value)->get()->getRowArray();
                    if (! $p) {
                        return $fail('That problem no longer exists');
                    }
                    $notes[] = 'Linked to problem ' . $p['code'];
                } else {
                    $notes[] = 'Unlinked from problem';
                }
                break;

            case 'change':
                if (! $this->db->fieldExists('change_id', 'tickets')) {
                    return $fail('Change linkage is not available yet');
                }
                $upd['change_id'] = $value !== '' ? (int) $value : null;
                if ($value !== '') {
                    $c = $this->db->table('changes')->where('id', (int) $value)->get()->getRowArray();
                    if (! $c) {
                        return $fail('That change no longer exists');
                    }
                    $notes[] = 'Linked to change ' . $c['code'];
                } else {
                    $notes[] = 'Unlinked from change';
                }
                break;

            default:
                $this->db->transComplete();

                return $back;
        }

        $this->db->table('tickets')->where('id', $t['id'])->update($upd);
        foreach ($notes as $n) {
            $this->addSystemNote((int) $t['id'], $n);
        }
        $this->db->transComplete();

        foreach ($after as $fn) {
            $fn();
        }
        $this->fireUpdated((int) $t['id']);
        $this->toast('Ticket updated');

        return $back;
    }

    /** Custom-field values from the editable Details card (inputs cf_{id}). */
    public function fields(string $code)
    {
        $t = $this->ticketScoped($code);
        if (! $t) {
            return redirect()->to('/app/tickets');
        }
        $this->saveCustomFields((int) $t['id'], 'agents', true);
        $this->addSystemNote((int) $t['id'], 'Details updated by ' . $this->me['name']);
        $this->fireUpdated((int) $t['id']);
        $this->toast('Details saved');

        return redirect()->to('/app/tickets/' . $code);
    }

    public function resolve(string $code)
    {
        $t = $this->ticketScoped($code);
        if ($t) {
            $this->db->transStart();
            $t = $this->lockTicket((int) $t['id']) ?? $t;
            $this->db->table('tickets')->where('id', $t['id'])->update(th_status_change($t, [
                'status' => 'Resolved', 'resolved_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]));
            $this->addSystemNote((int) $t['id'], 'Status changed to Resolved');
            $this->db->transComplete();
            $this->notify('Status → Resolved', array_merge($t, ['status' => 'Resolved']));
            $this->fireUpdated((int) $t['id']);
            $this->toast($code . ' resolved');
        }

        return redirect()->back();
    }

    public function reopen(string $code)
    {
        $t = $this->ticketScoped($code);
        if ($t) {
            $this->db->transStart();
            $t = $this->lockTicket((int) $t['id']) ?? $t;
            $this->db->table('tickets')->where('id', $t['id'])->update(th_status_change($t, ['status' => 'Open', 'resolved_at' => null, 'updated_at' => date('Y-m-d H:i:s')]));
            $this->addSystemNote((int) $t['id'], 'Ticket reopened');
            $this->db->transComplete();
            $this->fireUpdated((int) $t['id']);
            $this->toast($code . ' reopened');
        }

        return redirect()->back();
    }

    public function escalate(string $code)
    {
        $t = $this->ticketScoped($code);
        if ($t) {
            $priority = $t['priority'] === 'Urgent' ? 'Urgent' : 'High';
            $upd = ['escalated' => 1, 'priority' => $priority, 'updated_at' => date('Y-m-d H:i:s')];
            $this->db->table('tickets')->where('id', $t['id'])->update($upd);
            $this->addSystemNote((int) $t['id'], 'Escalated to ' . $priority . ' priority by ' . $this->me['name']);
            // Email + bell for the group's supervisors and the assignee, then the
            // "updated" automation event.
            TicketIntake::notifyEscalation(array_merge($t, $upd), (int) $this->me['id'], 'Escalated by ' . $this->me['name']);
            $this->toast('Escalated — supervisors and the assignee notified', 'warn');
        }

        return redirect()->back();
    }

    public function delete(string $code)
    {
        $t = $this->ticketScoped($code);
        if ($t) {
            $this->deleteTicketRows([(int) $t['id']]);
            $this->toast($code . ' deleted', 'bad');
        }

        return redirect()->to('/app/tickets');
    }

    private function deleteTicketRows(array $ids): void
    {
        if (! $ids) {
            return;
        }
        // Audited here rather than in the callers: this is the one choke point for
        // both the single delete and the bulk action, and the conversation goes
        // with the ticket, so "who removed this?" has to stay answerable.
        $codes = array_column($this->db->table('tickets')->whereIn('id', $ids)->get()->getResultArray(), 'code');
        \App\Libraries\Audit::log('ticket.deleted', implode(', ', $codes));

        foreach (['ticket_messages', 'ticket_tasks', 'ticket_assets', 'ticket_field_values', 'ticket_watchers', 'ticket_time_entries', 'ticket_approvals', 'notifications'] as $table) {
            try {
                $this->db->table($table)->whereIn('ticket_id', $ids)->delete();
            } catch (\Throwable $e) {
                log_message('error', 'Cleanup of {t} failed: {msg}', ['t' => $table, 'msg' => $e->getMessage()]);
            }
        }
        $this->db->table('ticket_links')->whereIn('from_id', $ids)->orWhereIn('to_id', $ids)->delete();
        $this->db->table('tickets')->whereIn('id', $ids)->delete();
    }

    /**
     * Fold one ticket into another: conversation, tasks, time, watchers,
     * approvals and links move across; anything that would now point at itself
     * or duplicate an existing row is dropped. The source row is deleted.
     */
    private function mergeInto(int $srcId, array $tgt): void
    {
        $tgtId = (int) $tgt['id'];
        $this->db->query(
            "UPDATE ticket_messages SET ticket_id = ?, kind = IF(kind='description','reply',kind) WHERE ticket_id = ?",
            [$tgtId, $srcId]
        );
        $this->db->table('ticket_tasks')->where('ticket_id', $srcId)->update(['ticket_id' => $tgtId]);
        $this->db->table('ticket_time_entries')->where('ticket_id', $srcId)->update(['ticket_id' => $tgtId]);
        $this->db->table('ticket_approvals')->where('ticket_id', $srcId)->update(['ticket_id' => $tgtId]);
        $this->db->table('ticket_assets')->where('ticket_id', $srcId)->delete();
        $this->db->table('ticket_field_values')->where('ticket_id', $srcId)->delete();

        // Watchers: carry over, minus the target's requester (already notified)
        // and anyone already watching (unique key).
        $this->db->query(
            'INSERT IGNORE INTO ticket_watchers (ticket_id, user_id, added_by, created_at)
             SELECT ?, user_id, added_by, created_at FROM ticket_watchers WHERE ticket_id = ? AND user_id <> ?',
            [$tgtId, $srcId, (int) $tgt['requester_id']]
        );
        $this->db->table('ticket_watchers')->where('ticket_id', $srcId)->delete();

        // Links: anything between the pair vanishes; the rest are re-pointed,
        // skipping ones that would duplicate an existing link to the target.
        $this->db->query(
            'DELETE FROM ticket_links WHERE (from_id = ? AND to_id = ?) OR (from_id = ? AND to_id = ?)',
            [$srcId, $tgtId, $tgtId, $srcId]
        );
        $this->db->query('UPDATE IGNORE ticket_links SET from_id = ? WHERE from_id = ?', [$tgtId, $srcId]);
        $this->db->query('UPDATE IGNORE ticket_links SET to_id = ? WHERE to_id = ?', [$tgtId, $srcId]);
        $this->db->table('ticket_links')->where('from_id', $srcId)->orWhere('to_id', $srcId)->delete();

        try {
            $this->db->table('notifications')->where('ticket_id', $srcId)->update(['ticket_id' => $tgtId, 'url' => site_url('app/tickets/' . $tgt['code'])]);
        } catch (\Throwable $e) {
            // notifications table missing — nothing to re-point
        }
        $this->db->table('tickets')->where('id', $srcId)->delete();
    }

    /**
     * Merge candidates: the same requester's other tickets first, then tickets
     * about the same thing from anyone. Scored so obvious duplicates float up.
     * Returns the modal fragment (used both for the initial list and live search).
     */
    public function mergeSearch(string $code)
    {
        $src = $this->ticketScoped($code);
        if (! $src) {
            return $this->response->setStatusCode(404, 'Not found');
        }

        $q = trim((string) $this->request->getGet('q'));
        $candidates = $this->scopeWhere($this->db->table('tickets')->where('id !=', $src['id']))
            ->orderBy('updated_at', 'DESC')->limit(400)->get()->getResultArray();

        // Pull first messages once so we can compare descriptions too.
        $ids = array_column($candidates, 'id');
        $ids[] = (int) $src['id'];
        $descs = [];
        foreach ($this->db->table('ticket_messages')->whereIn('ticket_id', $ids)->where('kind', 'description')->get()->getResultArray() as $m) {
            $descs[(int) $m['ticket_id']] = $m['body'];
        }

        $words = static fn (string $s): array => th_keywords($s);

        $srcWords = $words($src['subject'] . ' ' . ($descs[(int) $src['id']] ?? ''));
        $srcTags  = json_decode($src['tags'] ?? '[]', true) ?: [];
        $needle   = mb_strtolower($q);

        $scored = [];
        foreach ($candidates as $t) {
            // Explicit search filters first; without a query we rank everything.
            if ($q !== '') {
                $hay = mb_strtolower($t['code'] . ' ' . $t['subject'] . ' ' . ($descs[(int) $t['id']] ?? '')
                    . ' ' . ($this->users()[(int) $t['requester_id']]['name'] ?? ''));
                if (! str_contains($hay, $needle)) {
                    continue;
                }
            }

            $score   = 0;
            $reasons = [];

            if ((int) $t['requester_id'] === (int) $src['requester_id']) {
                $score += 45;
                $reasons[] = 'same requester';
            }
            // One shared word is weak; the reason is only worth showing at two or more.
            $overlap = array_values(array_intersect($srcWords, $words($t['subject'] . ' ' . ($descs[(int) $t['id']] ?? ''))));
            if ($overlap) {
                $score += min(40, 5 + (count($overlap) - 1) * 12);
                if (count($overlap) >= 2) {
                    $reasons[] = 'shared: ' . implode(', ', array_slice($overlap, 0, 3));
                }
            }
            if ($t['category'] === $src['category']) {
                $score += 8;
            }
            $tagOverlap = array_intersect($srcTags, json_decode($t['tags'] ?? '[]', true) ?: []);
            if ($tagOverlap) {
                $score += 10;
                $reasons[] = 'tag ' . implode(', ', array_slice($tagOverlap, 0, 2));
            }
            if ((int) $t['group_id'] === (int) $src['group_id']) {
                $score += 5;
            }
            // Recent activity is a mild tie-breaker; old tickets rarely merge.
            $ageDays = max(1, (time() - strtotime($t['updated_at'])) / 86400);
            $score += max(0, 10 - $ageDays);
            if (th_is_open($t)) {
                $score += 6;
            }

            if ($q === '' && ($score < 30 || ! $reasons)) {
                continue; // suggestions must be justifiable, not just recent
            }
            $scored[] = ['t' => $t, 'score' => (int) $score, 'why' => $reasons];
        }

        usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);

        return view('agent/_merge_modal', [
            'src' => $src, 'q' => $q,
            'results' => array_slice($scored, 0, 12),
            'users' => $this->users(),
            'listOnly' => $this->request->getGet('list') === '1',
        ]);
    }

    public function merge(string $code)
    {
        $src = $this->ticketScoped($code);
        $tgt = $this->ticketScoped((string) $this->request->getPost('target'));
        if (! $src || ! $tgt || $src['id'] === $tgt['id']) {
            $this->toast('Pick a ticket to merge into', 'warn');

            return redirect()->back();
        }
        $this->db->transStart();
        $this->mergeInto((int) $src['id'], $tgt);
        \App\Libraries\Audit::log('ticket.merged', $src['code'] . ' → ' . $tgt['code']);
        $this->addSystemNote((int) $tgt['id'], $src['code'] . ' merged into this ticket by ' . $this->me['name']);
        $this->db->transComplete();
        $this->toast($src['code'] . ' merged into ' . $tgt['code']);

        return redirect()->to('/app/tickets/' . $tgt['code']);
    }

    /** The open approval gating this ticket, if any. */
    private function pendingApproval(int $ticketId): ?array
    {
        return $this->db->table('ticket_approvals')
            ->where('ticket_id', $ticketId)->where('status', 'Pending')
            ->orderBy('id', 'DESC')->get()->getRowArray();
    }

    public function approveRequest(string $code)
    {
        return $this->decideRequest($code, 'Approved');
    }

    public function rejectRequest(string $code)
    {
        return $this->decideRequest($code, 'Rejected');
    }

    /**
     * Sign off (or turn down) a catalog request. Supervisors and administrators
     * decide, matching how change approvals already work — the catalog's
     * "Manager" / "System owner" labels describe who is accountable, but the
     * user model has no reporting line to resolve them to a person.
     */
    private function decideRequest(string $code, string $decision)
    {
        $t = $this->ticketScoped($code);
        if (! $t) {
            return redirect()->to('/app/tickets');
        }
        if (! in_array($this->me['role'] ?? '', ['Administrator', 'Supervisor'], true)) {
            $this->toast('Only supervisors and administrators can decide approvals', 'warn');

            return redirect()->to('/app/tickets/' . $code);
        }
        $ap = $this->pendingApproval((int) $t['id']);
        if (! $ap) {
            $this->toast('There is nothing awaiting approval on this ticket', 'warn');

            return redirect()->to('/app/tickets/' . $code);
        }

        $now  = date('Y-m-d H:i:s');
        $note = trim((string) $this->request->getPost('note')) ?: null;
        $this->db->transStart();
        $t = $this->lockTicket((int) $t['id']) ?? $t;
        $this->db->table('ticket_approvals')->where('id', $ap['id'])->update([
            'status' => $decision, 'decided_by' => (int) $this->me['id'],
            'note' => $note, 'decided_at' => $now,
        ]);

        if ($decision === 'Approved') {
            // The clock restarts here: a request should not burn its turnaround
            // sitting in a queue waiting for a signature it never had.
            [$fr, $res] = th_sla_targets($t['priority']);
            $this->db->table('tickets')->where('id', $t['id'])->update([
                'status'  => 'Open',
                'fr_due'  => date('Y-m-d H:i:s', th_due_at(time(), $fr, (int) $t['group_id'])),
                'res_due' => date('Y-m-d H:i:s', th_due_at(time(), $res, (int) $t['group_id'])),
                // Targets are recomputed from this moment, so the wait for the
                // approver is already accounted for — close the pause outright
                // rather than letting it keep accruing against a fresh deadline.
                'paused_at' => null,
                'paused_seconds' => 0,
                'updated_at' => $now,
            ]);
            $this->addSystemNote((int) $t['id'], 'Approved by ' . $this->me['name']
                . ($note ? ' — ' . $note : '') . '; SLA clock started');
        } else {
            $this->db->table('tickets')->where('id', $t['id'])->update(th_status_change($t, [
                'status' => 'Closed', 'resolved_at' => $now, 'updated_at' => $now,
            ]));
            $this->addSystemNote((int) $t['id'], 'Rejected by ' . $this->me['name'] . ($note ? ' — ' . $note : ''));
        }
        $this->db->transComplete();

        \App\Libraries\Audit::log('request.' . strtolower($decision), $code . ' — ' . $ap['required_of']);
        $fresh = array_merge($t, ['status' => $decision === 'Approved' ? 'Open' : 'Closed']);
        $this->notify($decision === 'Approved' ? 'Request approved' : 'Request rejected', $fresh, ['message' => $note ? 'Note from the approver: ' . $note : '']);
        // Mark read any "needs approval" bell entries for this ticket.
        try {
            $this->db->table('notifications')->where('ticket_id', $t['id'])->where('kind', 'approval')->where('read_at', null)->update(['read_at' => $now]);
        } catch (\Throwable $e) {
            // optional
        }
        if ($t['agent_id']) {
            $this->notifyUsers([(int) $t['agent_id']], 'approval', $t['code'] . ' ' . strtolower($decision) . ' by ' . $this->me['name'], site_url('app/tickets/' . $t['code']), (int) $t['id'], $t['subject']);
        }
        $this->fireUpdated((int) $t['id']);
        $this->toast($code . ' ' . strtolower($decision) . ' — requester emailed');

        return redirect()->to('/app/tickets/' . $code);
    }

    public function claim(string $code)
    {
        $t = $this->ticketScoped($code);
        if ($t) {
            $this->db->table('tickets')->where('id', $t['id'])->update(['agent_id' => $this->me['id'], 'updated_at' => date('Y-m-d H:i:s')]);
            $this->addSystemNote((int) $t['id'], 'Assigned to ' . $this->me['name']);
            $this->fireUpdated((int) $t['id']);
            $this->toast($code . ' is yours');
        }

        return redirect()->back();
    }

    public function addWatcher(string $code)
    {
        $t = $this->ticketScoped($code);
        $userId = (int) $this->request->getPost('user_id');
        if (! $t || ! $userId) {
            return redirect()->to('/app/tickets/' . $code);
        }
        $u = $this->db->table('users')->where('id', $userId)->where('active', 1)->get()->getRowArray();
        if (! $u) {
            $this->toast('That person is not available', 'warn');

            return redirect()->to('/app/tickets/' . $code);
        }
        if ((int) $t['requester_id'] === $userId) {
            $this->toast($u['name'] . ' is the requester and already gets every update', 'warn');

            return redirect()->to('/app/tickets/' . $code);
        }
        // The unique key makes a double-add harmless; catch so it is not a 500.
        try {
            $this->db->table('ticket_watchers')->insert([
                'ticket_id' => $t['id'], 'user_id' => $userId,
                'added_by' => (int) $this->me['id'], 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $this->addSystemNote((int) $t['id'], $u['name'] . ' added as a watcher by ' . $this->me['name']);
            $this->toast($u['name'] . ' will be copied on updates');
        } catch (\Throwable $e) {
            $this->toast($u['name'] . ' is already watching this ticket', 'warn');
        }

        return redirect()->to('/app/tickets/' . $code);
    }

    public function removeWatcher(string $code, int $userId)
    {
        $t = $this->ticketScoped($code);
        if ($t) {
            $this->db->table('ticket_watchers')->where('ticket_id', $t['id'])->where('user_id', $userId)->delete();
            if ($this->db->affectedRows()) {
                $this->addSystemNote((int) $t['id'], ($this->userById($userId)['name'] ?? 'A watcher') . ' removed as a watcher by ' . $this->me['name']);
            }
            $this->toast('Watcher removed');
        }

        return redirect()->to('/app/tickets/' . $code);
    }

    /**
     * Log effort against a ticket. Accepts "90", "1h30", "1h 30m" or "45m" —
     * agents record time between other tasks and should not have to convert it.
     */
    public function logTime(string $code)
    {
        $t = $this->ticketScoped($code);
        if (! $t) {
            return redirect()->to('/app/tickets');
        }
        $minutes = th_parse_minutes((string) $this->request->getPost('spent'));
        if ($minutes < 1) {
            $this->toast('Enter time like 45m, 1h30 or 90', 'warn');

            return redirect()->to('/app/tickets/' . $code);
        }
        $spentOn = (string) $this->request->getPost('spent_on');
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $spentOn);
        if (! $d || $d->format('Y-m-d') !== $spentOn) {
            $spentOn = date('Y-m-d');
        }

        $minutes = min($minutes, 60 * 24);
        $note    = trim((string) $this->request->getPost('note')) ?: null;
        $this->db->table('ticket_time_entries')->insert([
            'ticket_id' => $t['id'], 'user_id' => (int) $this->me['id'],
            'minutes' => $minutes, 'spent_on' => $spentOn,
            'note' => $note,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->addSystemNote((int) $t['id'], $this->me['name'] . ' logged ' . th_minutes($minutes) . ($note ? ' — ' . $note : ''));
        $this->toast(th_minutes($minutes) . ' logged on ' . $code);

        return redirect()->to('/app/tickets/' . $code);
    }

    public function deleteTime(string $code, int $entryId)
    {
        $t = $this->ticketScoped($code);
        if ($t) {
            // Own entries only, unless you supervise — a worklog others can edit
            // is not a worklog anyone can trust.
            $q = $this->db->table('ticket_time_entries')->where('id', $entryId)->where('ticket_id', $t['id']);
            if (! in_array($this->me['role'] ?? '', ['Administrator', 'Supervisor'], true)) {
                $q->where('user_id', (int) $this->me['id']);
            }
            $q->delete();
            $this->toast($this->db->affectedRows() ? 'Time entry removed' : 'That entry is not yours to remove', $this->db->affectedRows() ? 'ok' : 'warn');
        }

        return redirect()->to('/app/tickets/' . $code);
    }

    /**
     * Links this ticket has, read from both ends. The stored row has a direction
     * (A blocks B); from the other side it reads as "blocked by", which is why
     * the label is resolved here rather than in the view.
     */
    private function ticketLinks(int $id): array
    {
        $rows = $this->db->query(
            'SELECT l.id, l.kind, l.from_id, l.to_id,
                    t.code, t.subject, t.status, t.priority, t.group_id, t.agent_id, t.requester_id
             FROM ticket_links l
             JOIN tickets t ON t.id = IF(l.from_id = ?, l.to_id, l.from_id)
             WHERE l.from_id = ? OR l.to_id = ?
             ORDER BY l.id DESC',
            [$id, $id, $id]
        )->getResultArray();

        $labels = [
            'related'   => ['related to', 'related to'],
            'duplicate' => ['duplicate of', 'duplicated by'],
            'blocks'    => ['blocks', 'blocked by'],
        ];

        foreach ($rows as &$r) {
            $outgoing  = (int) $r['from_id'] === $id;
            $r['label'] = $labels[$r['kind']][$outgoing ? 0 : 1];
        }

        // Only tickets the viewer's group scope allows.
        return array_values(array_filter($rows, fn ($r) => $this->canSeeTicket($r)));
    }

    public function linkTicket(string $code)
    {
        $t = $this->ticketScoped($code);
        if (! $t) {
            return redirect()->to('/app/tickets');
        }
        $other = $this->ticketByCode(trim((string) $this->request->getPost('code')));
        $kind  = $this->request->getPost('kind');
        $kind  = in_array($kind, ['related', 'duplicate', 'blocks'], true) ? $kind : 'related';

        if (! $other || (int) $other['id'] === (int) $t['id']) {
            $this->toast('Pick a different ticket to link to', 'warn');

            return redirect()->to('/app/tickets/' . $code);
        }
        if (! $this->canSeeTicket($other)) {
            $this->toast('That ticket belongs to another team', 'warn');

            return redirect()->to('/app/tickets/' . $code);
        }

        // The unique key is on (from_id, to_id), so check the mirror by hand.
        $exists = $this->db->table('ticket_links')
            ->groupStart()->where('from_id', $t['id'])->where('to_id', $other['id'])->groupEnd()
            ->orGroupStart()->where('from_id', $other['id'])->where('to_id', $t['id'])->groupEnd()
            ->countAllResults();
        if ($exists) {
            $this->toast($other['code'] . ' is already linked', 'warn');

            return redirect()->to('/app/tickets/' . $code);
        }

        $this->db->table('ticket_links')->insert([
            'from_id' => (int) $t['id'], 'to_id' => (int) $other['id'], 'kind' => $kind,
            'created_by' => (int) $this->me['id'], 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->addSystemNote((int) $t['id'], 'Linked as ' . $kind . ' to ' . $other['code'] . ' by ' . $this->me['name']);
        $this->addSystemNote((int) $other['id'], 'Linked from ' . $t['code'] . ' (' . $kind . ') by ' . $this->me['name']);
        $this->toast('Linked to ' . $other['code']);

        return redirect()->to('/app/tickets/' . $code);
    }

    public function unlinkTicket(string $code, int $linkId)
    {
        $t = $this->ticketScoped($code);
        if ($t) {
            $link = $this->db->table('ticket_links')->where('id', $linkId)
                ->groupStart()->where('from_id', $t['id'])->orWhere('to_id', $t['id'])->groupEnd()
                ->get()->getRowArray();
            if ($link) {
                $otherId = (int) $link['from_id'] === (int) $t['id'] ? (int) $link['to_id'] : (int) $link['from_id'];
                $other   = $this->db->table('tickets')->select('code')->where('id', $otherId)->get()->getRowArray();
                $this->db->table('ticket_links')->where('id', $linkId)->delete();
                $this->addSystemNote((int) $t['id'], 'Link to ' . ($other['code'] ?? '?') . ' removed by ' . $this->me['name']);
                if ($other) {
                    $this->addSystemNote($otherId, 'Link to ' . $t['code'] . ' removed by ' . $this->me['name']);
                }
            }
            $this->toast('Link removed');
        }

        return redirect()->to('/app/tickets/' . $code);
    }

    public function addTask(string $code)
    {
        $t = $this->ticketScoped($code);
        $title = trim((string) $this->request->getPost('title'));
        if ($t && $title !== '') {
            $owner = $this->request->getPost('owner_id');
            $ownerId = $owner !== '' && $owner !== null ? (int) $owner : null;
            $this->db->table('ticket_tasks')->insert([
                'ticket_id' => $t['id'], 'title' => mb_substr($title, 0, 255), 'done' => 0,
                'owner_id' => $ownerId,
            ]);
            $this->addSystemNote((int) $t['id'], 'Task added: ' . $title . ($ownerId ? ' (owner ' . ($this->userById($ownerId)['name'] ?? '?') . ')' : ''));
            if ($ownerId) {
                $this->notifyUsers([$ownerId], 'task', 'Task on ' . $t['code'] . ': ' . $title, site_url('app/tickets/' . $t['code']), (int) $t['id'], $t['subject']);
            }
            $this->toast('Task added');
        } elseif ($t) {
            $this->toast('Give the task a name', 'warn');
        }

        return redirect()->to('/app/tickets/' . $code);
    }

    public function toggleTask(string $code, int $taskId)
    {
        $t = $this->ticketScoped($code);
        if ($t) {
            $task = $this->db->table('ticket_tasks')->where('id', $taskId)->where('ticket_id', $t['id'])->get()->getRowArray();
            if ($task) {
                $done = (int) ! $task['done'];
                $this->db->table('ticket_tasks')->where('id', $taskId)->update(['done' => $done]);
                $this->addSystemNote((int) $t['id'], 'Task ' . ($done ? 'completed' : 'reopened') . ': ' . $task['title']);
            }
        }

        return redirect()->to('/app/tickets/' . $code);
    }

    public function deleteTask(string $code, int $taskId)
    {
        $t = $this->ticketScoped($code);
        if ($t) {
            $task = $this->db->table('ticket_tasks')->where('id', $taskId)->where('ticket_id', $t['id'])->get()->getRowArray();
            if ($task) {
                $this->db->table('ticket_tasks')->where('id', $taskId)->delete();
                $this->addSystemNote((int) $t['id'], 'Task removed: ' . $task['title']);
                $this->toast('Task removed');
            }
        }

        return redirect()->to('/app/tickets/' . $code);
    }

    /** Tags are lowercase, single tokens; the same normalisation on add and remove. */
    private static function tagFromPost(?string $raw): string
    {
        return preg_replace('/\s+/', '-', strtolower(trim((string) $raw)));
    }

    public function addTag(string $code)
    {
        $t = $this->ticketScoped($code);
        $tag = self::tagFromPost($this->request->getPost('tag'));
        if ($t && $tag !== '') {
            $tags = json_decode($t['tags'] ?? '[]', true) ?: [];
            if (! in_array($tag, $tags, true)) {
                $tags[] = $tag;
                $this->db->table('tickets')->where('id', $t['id'])->update(['tags' => json_encode($tags), 'updated_at' => date('Y-m-d H:i:s')]);
                $this->addSystemNote((int) $t['id'], 'Tag "' . $tag . '" added by ' . $this->me['name']);
                $this->fireUpdated((int) $t['id']);
            }
            $this->toast('Tag added');
        }

        return redirect()->to('/app/tickets/' . $code);
    }

    public function removeTag(string $code)
    {
        $t = $this->ticketScoped($code);
        $tag = self::tagFromPost($this->request->getPost('tag'));
        if ($t && $tag !== '') {
            $tags = json_decode($t['tags'] ?? '[]', true) ?: [];
            $kept = array_values(array_filter($tags, static fn ($x) => $x !== $tag));
            if (count($kept) !== count($tags)) {
                $this->db->table('tickets')->where('id', $t['id'])->update(['tags' => json_encode($kept), 'updated_at' => date('Y-m-d H:i:s')]);
                $this->addSystemNote((int) $t['id'], 'Tag "' . $tag . '" removed by ' . $this->me['name']);
                $this->fireUpdated((int) $t['id']);
                $this->toast('Tag removed');
            } else {
                $this->toast('That tag was not on the ticket', 'warn');
            }
        }

        return redirect()->to('/app/tickets/' . $code);
    }

    public function linkAsset(string $code)
    {
        $t = $this->ticketScoped($code);
        $assetId = (int) $this->request->getPost('asset_id');
        if ($t && $assetId) {
            $asset  = $this->db->table('assets')->where('id', $assetId)->get()->getRowArray();
            $exists = $this->db->table('ticket_assets')->where('ticket_id', $t['id'])->where('asset_id', $assetId)->countAllResults();
            if ($asset && ! $exists) {
                $this->db->table('ticket_assets')->insert(['ticket_id' => $t['id'], 'asset_id' => $assetId]);
                $this->addSystemNote((int) $t['id'], 'Asset linked: ' . $asset['name'] . ' (' . $asset['tag'] . ') by ' . $this->me['name']);
            }
            $this->toast($asset ? 'Asset linked' : 'That asset no longer exists', $asset ? 'ok' : 'warn');
        }

        return redirect()->to('/app/tickets/' . $code);
    }

    public function bulk()
    {
        $ids    = array_map('intval', (array) $this->request->getPost('ids'));
        $action = (string) $this->request->getPost('action');
        $value  = (string) $this->request->getPost('value');
        if ($ids) {
            // Enforce group scope on the posted ids.
            $rows = $this->scopeWhere($this->db->table('tickets')->whereIn('id', $ids))->get()->getResultArray();
            $ids  = array_map(static fn ($t) => (int) $t['id'], $rows);
        }
        if (! $ids) {
            $this->toast('Nothing selected', 'warn');

            return redirect()->back();
        }
        $now = date('Y-m-d H:i:s');

        switch ($action) {
            case 'assign':
                $agentId = $value !== '' ? (int) $value : null;
                if (! $this->canAssignTo($agentId)) {
                    $this->toast('You cannot assign tickets to that person', 'warn');
                    break;
                }
                $this->db->transStart();
                $assignRows = $this->db->query('SELECT * FROM tickets WHERE id IN ?  FOR UPDATE', [$ids])->getResultArray();
                $this->db->table('tickets')->whereIn('id', $ids)->update(['agent_id' => $agentId, 'updated_at' => $now]);
                $name = $agentId ? ($this->userById($agentId)['name'] ?? '?') : null;
                foreach ($assignRows as $row) {
                    $this->addSystemNote((int) $row['id'], $name ? 'Assigned to ' . $name : 'Unassigned');
                }
                $this->db->transComplete();
                foreach ($assignRows as $row) {
                    if ($agentId && $agentId !== (int) $this->me['id']) {
                        $this->notify('Ticket assigned', array_merge($row, ['agent_id' => $agentId]));
                        $this->notifyUsers([$agentId], 'assigned', $row['code'] . ' assigned to you by ' . $this->me['name'], site_url('app/tickets/' . $row['code']), (int) $row['id'], $row['subject']);
                    }
                }
                $this->fireUpdated($ids);
                $this->toast(count($ids) . ' tickets updated');
                break;

            case 'group':
                $groupId = (int) $value;
                if (! $this->canUseGroup($groupId)) {
                    $this->toast('You cannot move tickets into that group', 'warn');
                    break;
                }
                $g = $this->db->table('groups')->where('id', $groupId)->get()->getRowArray();
                $this->db->transStart();
                $this->db->table('tickets')->whereIn('id', $ids)->update(['group_id' => $groupId, 'updated_at' => $now]);
                foreach ($ids as $id) {
                    $this->addSystemNote($id, 'Moved to ' . ($g['name'] ?? '?'));
                }
                $this->db->transComplete();
                $this->fireUpdated($ids);
                $this->toast(count($ids) . ' tickets moved to ' . ($g['name'] ?? '?'));
                break;

            case 'status':
                if (! isset(TH_STATUS[$value])) {
                    $this->toast('That is not a valid status', 'warn');
                    break;
                }
                $this->db->transStart();
                $statusRows = $this->db->query('SELECT * FROM tickets WHERE id IN ? FOR UPDATE', [$ids])->getResultArray();
                $resolved = [];
                // Per row rather than one bulk statement: the reopen counter, the
                // stop-the-clock accounting and resolved_at all depend on where
                // each ticket was.
                foreach ($statusRows as $row) {
                    $upd = ['status' => $value, 'updated_at' => $now] + $this->resolvedAtFor($row, $value, $now);
                    $this->db->table('tickets')->where('id', $row['id'])->update(th_status_change($row, $upd));
                    $this->addSystemNote((int) $row['id'], 'Status changed to ' . $value);
                    if ($value === 'Resolved') {
                        $resolved[] = array_merge($row, $upd);
                    }
                }
                $this->db->transComplete();
                foreach ($resolved as $row) {
                    $this->notify('Status → Resolved', $row);
                }
                $this->fireUpdated($ids);
                $this->toast(count($ids) . ' tickets updated');
                break;

            case 'priority':
                if (! isset(TH_PRIORITY[$value])) {
                    $this->toast('That is not a valid priority', 'warn');
                    break;
                }
                $this->db->transStart();
                $this->db->table('tickets')->whereIn('id', $ids)->update(['priority' => $value, 'updated_at' => $now]);
                foreach ($ids as $id) {
                    $this->addSystemNote($id, 'Priority set to ' . $value);
                }
                $this->db->transComplete();
                $this->fireUpdated($ids);
                $this->toast(count($ids) . ' tickets updated');
                break;

            case 'merge':
                if (count($ids) < 2) {
                    $this->toast('Select at least two tickets to merge', 'warn');
                    break;
                }
                $targetId = array_shift($ids);
                $target   = $this->db->table('tickets')->where('id', $targetId)->get()->getRowArray();
                if (! $target) {
                    $this->toast('The target ticket no longer exists', 'warn');
                    break;
                }
                // Read the codes while the rows still exist — the audit line is
                // the only place they survive the merge.
                $mergedCodes = array_column($this->db->table('tickets')->whereIn('id', $ids)->get()->getResultArray(), 'code');
                $this->db->transStart();
                foreach ($ids as $srcId) {
                    $this->mergeInto((int) $srcId, $target);
                }
                \App\Libraries\Audit::log('ticket.merged', implode(', ', $mergedCodes) . ' → ' . $target['code']);
                $this->addSystemNote((int) $targetId, 'Merged ' . implode(', ', $mergedCodes) . ' into this ticket by ' . $this->me['name']);
                $this->db->transComplete();
                $this->toast('Merged into ' . $target['code']);

                return redirect()->to('/app/tickets/' . $target['code']);

            case 'delete':
                $this->deleteTicketRows($ids);
                $this->toast(count($ids) . ' tickets deleted', 'bad');
                break;
        }

        return redirect()->back();
    }

    /* ---------- Markdown editor + canned responses ---------- */

    /** POST app/tickets/preview — rendered HTML for the editor's Preview tab. */
    public function preview()
    {
        return $this->markdownPreview();
    }

    /** POST app/tickets/(code)/inline-image — a pasted/dropped image, as JSON {url}. */
    public function inlineImage(string $code)
    {
        $t = $this->ticketByCode($code);
        if (! $t || ! $this->canSeeTicket($t)) {
            return $this->editorJson(['error' => 'Ticket not found'], 404);
        }
        [$name, $err] = $this->storeInlineImage($t);
        if ($err) {
            return $this->editorJson(['error' => $err], 422);
        }

        return $this->editorJson(['url' => '/files/' . $name, 'name' => $name]);
    }

    /** GET app/canned.json — responses this agent may insert, for the picker. */
    public function cannedJson()
    {
        $me   = (int) $this->me['id'];
        $rows = array_map(static fn ($c) => [
            'id' => (int) $c['id'], 'title' => $c['title'], 'body' => $c['body'],
            'scope' => $c['scope'] ?? 'global', 'shortcut' => $c['shortcut'] ?? null,
            'mine' => ($c['scope'] ?? '') === 'personal' && (int) ($c['owner_id'] ?? 0) === $me,
        ], $this->cannedResponses());

        return $this->response->setHeader('Cache-Control', 'no-store')->setJSON(['responses' => $rows]);
    }

    /** POST app/canned — "Save as response": a personal canned response from the reply box. */
    public function cannedCreate()
    {
        $title = trim((string) $this->request->getPost('title'));
        $body  = trim((string) $this->request->getPost('body'));
        if ($title === '' || $body === '') {
            $this->toast('A response needs a title and a body', 'warn');

            return redirect()->back();
        }
        if (! $this->db->fieldExists('scope', 'canned_responses')) {
            $this->toast('Personal responses need the latest migration', 'warn');

            return redirect()->back();
        }
        $shortcut = strtolower(trim((string) $this->request->getPost('shortcut'), " /\t"));
        $shortcut = preg_replace('/[^a-z0-9_-]+/', '-', $shortcut) ?: null;
        $now = date('Y-m-d H:i:s');
        $this->db->table('canned_responses')->insert([
            'title' => mb_substr($title, 0, 80), 'body' => $body, 'scope' => 'personal',
            'owner_id' => (int) $this->me['id'], 'group_id' => null,
            'shortcut' => $shortcut ? mb_substr($shortcut, 0, 40) : null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->toast('Saved to your responses');

        return redirect()->back();
    }

    /** POST app/canned/(id)/delete — own personal responses only. */
    public function cannedDelete(int $id)
    {
        if ($this->db->fieldExists('scope', 'canned_responses')) {
            $this->db->table('canned_responses')->where('id', $id)->where('scope', 'personal')->where('owner_id', (int) $this->me['id'])->delete();
            $this->toast($this->db->affectedRows() ? 'Response removed' : 'That response is not yours to remove', $this->db->affectedRows() ? 'ok' : 'warn');
        }

        return redirect()->back();
    }

    /** POST app/tickets/(code)/save-template — turn this ticket into a reusable template (Supervisor+). */
    public function saveAsTemplate(string $code)
    {
        $t = $this->ticketScoped($code);
        if (! $t) {
            return redirect()->to('/app/tickets');
        }
        if (! in_array($this->me['role'] ?? '', ['Administrator', 'Supervisor'], true)) {
            $this->toast('Only supervisors and administrators can save templates', 'warn');

            return redirect()->to('/app/tickets/' . $code);
        }
        if (! $this->db->tableExists('ticket_templates')) {
            $this->toast('Templates need the latest migration', 'warn');

            return redirect()->to('/app/tickets/' . $code);
        }
        $name = trim((string) $this->request->getPost('name')) ?: $t['subject'];
        $desc = $this->db->table('ticket_messages')->where('ticket_id', $t['id'])->where('kind', 'description')->orderBy('id')->get()->getRowArray();
        $tasks = array_column($this->db->table('ticket_tasks')->where('ticket_id', $t['id'])->orderBy('id')->get()->getResultArray(), 'title');
        $now = date('Y-m-d H:i:s');
        $this->db->table('ticket_templates')->insert([
            'name' => mb_substr($name, 0, 100), 'subject' => $t['subject'],
            'body' => $desc['body'] ?? '', 'type' => $t['type'], 'priority' => $t['priority'], 'category' => $t['category'],
            'group_id' => $t['group_id'] ?: null, 'agent_id' => $t['agent_id'] ?: null,
            'tasks' => json_encode(array_values($tasks)), 'custom' => null,
            'created_by' => (int) $this->me['id'], 'created_at' => $now, 'updated_at' => $now,
        ]);
        \App\Libraries\Audit::log('template.created', $name . ' (from ' . $code . ')');
        $this->toast('Template "' . $name . '" saved — it is now in the New ticket form');

        return redirect()->to('/app/tickets/' . $code);
    }
}
