<?php

namespace App\Controllers;

class TicketsController extends BaseController
{
    private const SAVED_VIEWS = ['all', 'mine', 'group', 'unassigned', 'overdue', 'escalated', 'closed', 'every'];

    private function applySavedView(array $tickets, string $view): array
    {
        $me      = (int) $this->me['id'];
        $myGroup = (int) ($this->me['group_id'] ?? 0);

        return array_values(array_filter($tickets, static function ($t) use ($view, $me, $myGroup) {
            return match ($view) {
                'mine'       => th_is_open($t) && (int) $t['agent_id'] === $me,
                'group'      => th_is_open($t) && (int) $t['group_id'] === $myGroup,
                'unassigned' => th_is_open($t) && ! $t['agent_id'],
                'overdue'    => th_is_open($t) && th_sla($t)['remaining'] <= 0,
                'escalated'  => th_is_open($t) && (int) $t['escalated'] === 1,
                'closed'     => ! th_is_open($t),
                'every'      => true,
                default      => th_is_open($t),
            };
        }));
    }

    /** Ticket lookup that enforces the group-visibility scope. */

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
        $keep = ['view', 'q', 'status', 'priority', 'type', 'group', 'agent', 'sort'];
        $qs   = [];
        foreach ($keep as $k) {
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

    private function filtered(): array
    {
        $r    = $this->request;
        $all  = $this->scopeTickets($this->db->table('tickets')->get()->getResultArray());
        $view = in_array($r->getGet('view'), self::SAVED_VIEWS, true) ? $r->getGet('view') : 'all';

        $counts = [];
        foreach (self::SAVED_VIEWS as $v) {
            $counts[$v] = count($this->applySavedView($all, $v));
        }

        $list  = $this->applySavedView($all, $view);
        $users = $this->users();

        if ($q = trim((string) $r->getGet('q'))) {
            $needle = mb_strtolower($q);
            $ids    = array_column($list, 'id');
            $descs  = [];
            if ($ids) {
                foreach ($this->db->table('ticket_messages')->whereIn('ticket_id', $ids)->where('kind', 'description')->get()->getResultArray() as $m) {
                    $descs[(int) $m['ticket_id']] = $m['body'];
                }
            }
            $list = array_values(array_filter($list, static function ($t) use ($needle, $users, $descs) {
                $hay = $t['code'] . ' ' . $t['subject'] . ' ' . ($descs[(int) $t['id']] ?? '') . ' '
                    . ($users[(int) $t['requester_id']]['name'] ?? '') . ' ' . implode(' ', json_decode($t['tags'] ?? '[]', true) ?: []);

                return str_contains(mb_strtolower($hay), $needle);
            }));
        }

        foreach (['status', 'priority', 'type'] as $k) {
            if ($v = $r->getGet($k)) {
                $list = array_values(array_filter($list, static fn ($t) => $t[$k] === $v));
            }
        }
        if ($g = $r->getGet('group')) {
            $list = array_values(array_filter($list, static fn ($t) => (int) $t['group_id'] === (int) $g));
        }
        if ($a = $r->getGet('agent')) {
            $list = $a === 'none'
                ? array_values(array_filter($list, static fn ($t) => ! $t['agent_id']))
                : array_values(array_filter($list, static fn ($t) => (int) $t['agent_id'] === (int) $a));
        }

        $sort = $r->getGet('sort') ?: 'sla';
        usort($list, static function ($a, $b) use ($sort) {
            return match ($sort) {
                'created'  => strtotime($b['created_at']) <=> strtotime($a['created_at']),
                'updated'  => strtotime($b['updated_at']) <=> strtotime($a['updated_at']),
                'priority' => (TH_PRIORITY[$a['priority']]['rank'] <=> TH_PRIORITY[$b['priority']]['rank']) ?: (strtotime($a['res_due']) <=> strtotime($b['res_due'])),
                default    => strtotime($a['res_due']) <=> strtotime($b['res_due']),
            };
        });

        return [$list, $view, $counts];
    }

    public function index()
    {
        [$list, $view, $counts] = $this->filtered();

        // paginate the filtered list
        $perPage = 25;
        $total   = count($list);
        $page    = max(1, min((int) ($this->request->getGet('page') ?: 1), max(1, (int) ceil($total / $perPage))));
        $list    = array_slice($list, ($page - 1) * $perPage, $perPage);

        // last message per ticket for the row meta
        $lastMsgs = [];
        $ids = array_column($list, 'id');
        if ($ids) {
            foreach ($this->db->table('ticket_messages')->whereIn('ticket_id', $ids)->orderBy('created_at')->get()->getResultArray() as $m) {
                $lastMsgs[(int) $m['ticket_id']] = $m;
            }
        }

        return view('agent/tickets', $this->agentShared() + [
            'title' => 'Tickets', 'nav' => 'tickets',
            'list' => $list, 'view' => $view, 'counts' => $counts,
            'savedViews' => $this->savedViews(),
            'page' => $page, 'perPage' => $perPage, 'total' => $total,
            'users' => $this->users(), 'lastMsgs' => $lastMsgs,
            'filters' => [
                'q' => (string) $this->request->getGet('q'),
                'status' => (string) $this->request->getGet('status'),
                'priority' => (string) $this->request->getGet('priority'),
                'type' => (string) $this->request->getGet('type'),
                'group' => (string) $this->request->getGet('group'),
                'agent' => (string) $this->request->getGet('agent'),
                'sort' => (string) ($this->request->getGet('sort') ?: 'sla'),
            ],
        ]);
    }

    public function export()
    {
        [$list] = $this->filtered();
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
        $t = $this->createTicket([
            'subject' => $p['subject'], 'body' => $p['body'],
            'requester_id' => (int) $p['requester_id'],
            // Coalesced: any caller that omits a key (API, a trimmed form) should
            // get the default, not a 500.
            'agent_id' => ($p['agent_id'] ?? '') !== '' ? (int) $p['agent_id'] : null,
            'group_id' => ($p['group_id'] ?? '') !== '' ? (int) $p['group_id'] : null, // '' = route automatically
            'priority' => $p['priority'] ?? 'Medium', 'type' => $p['type'] ?? 'Incident',
            'category' => $p['category'] ?? 'Software', 'source' => $p['source'] ?? 'Portal',
            'attachments' => $this->storeUploads(),
        ]);
        $this->saveCustomFields((int) $t['id'], 'agents');
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

        return view('agent/ticket', $this->agentShared() + [
            'title' => $t['code'], 'nav' => 'tickets',
            'approval' => $approval, 'approvalHistory' => $approvalHistory, 'watchers' => $watchers,
            'timeEntries' => $timeEntries, 'links' => $links,
            'timeTotal' => array_sum(array_column($timeEntries, 'minutes')),
            'canDecide' => in_array($this->me['role'] ?? '', ['Administrator', 'Supervisor'], true),
            't' => $t, 'messages' => $messages, 'tasks' => $tasks,
            'linkedAssets' => $linkedAssets, 'allAssets' => $allAssets,
            'users' => $this->users(), 'requesterCount' => $requesterCount,
            'slaName' => $slaName,
            'problems' => $this->db->table('problems')->orderBy('code')->get()->getResultArray(),
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
        $this->db->table('ticket_messages')->insert([
            'ticket_id' => $t['id'], 'kind' => $kind, 'user_id' => $this->me['id'],
            'body' => $body, 'attachments' => json_encode($atts), 'created_at' => $now,
        ]);
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

        $fresh = array_merge($t, $upd);
        if ($kind === 'reply') {
            $this->notify('Public reply sent', $fresh, ['message' => $body]);
        }
        if (($upd['status'] ?? '') === 'Resolved') {
            $this->notify('Status → Resolved', $fresh);
        }
        $this->fireUpdated((int) $t['id']);
        $this->toast($kind === 'reply' ? 'Reply sent to requester' : 'Private note saved');

        return redirect()->to('/app/tickets/' . $code);
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
        $upd   = ['updated_at' => $now];

        switch ($field) {
            case 'agent':
                $upd['agent_id'] = $value !== '' ? (int) $value : null;
                $this->addSystemNote((int) $t['id'], $value !== '' ? 'Assigned to ' . ($this->userById((int) $value)['name'] ?? '?') : 'Unassigned');
                if ($upd['agent_id'] && $upd['agent_id'] !== (int) $this->me['id']) {
                    $this->notify('Ticket assigned', array_merge($t, $upd));
                }
                break;

            case 'group':
                $upd['group_id'] = (int) $value;
                $g = $this->db->table('groups')->where('id', (int) $value)->get()->getRowArray();
                $this->addSystemNote((int) $t['id'], 'Moved to ' . ($g['name'] ?? '?'));
                break;

            case 'status':
                $upd['status'] = $value;
                $upd['resolved_at'] = $value === 'Resolved' ? $now : null;
                $upd = th_status_change($t, $upd);
                $this->addSystemNote((int) $t['id'], 'Status changed to ' . $value);
                if ($value === 'Resolved') {
                    $this->notify('Status → Resolved', array_merge($t, $upd));
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
                    $this->toast('That is not a valid ' . $field, 'warn');

                    return redirect()->to('/app/tickets/' . $code);
                }
                $upd[$field] = $value;
                $this->addSystemNote((int) $t['id'], ucfirst($field) . ' set to ' . $value);
                break;

            case 'subject':
                if (trim($value) === '') {
                    $this->toast('The subject cannot be empty', 'warn');

                    return redirect()->to('/app/tickets/' . $code);
                }
                $upd['subject'] = mb_substr(trim($value), 0, 250);
                $this->addSystemNote((int) $t['id'], 'Subject edited');
                break;

            case 'problem':
                $upd['problem_id'] = $value !== '' ? (int) $value : null;
                if ($value !== '') {
                    $p = $this->db->table('problems')->where('id', (int) $value)->get()->getRowArray();
                    $this->addSystemNote((int) $t['id'], 'Linked to problem ' . ($p['code'] ?? '?'));
                } else {
                    $this->addSystemNote((int) $t['id'], 'Unlinked from problem');
                }
                break;

            default:
                return redirect()->to('/app/tickets/' . $code);
        }

        $this->db->table('tickets')->where('id', $t['id'])->update($upd);
        $this->fireUpdated((int) $t['id']);
        $this->toast('Ticket updated');

        return redirect()->to('/app/tickets/' . $code);
    }

    public function resolve(string $code)
    {
        $t = $this->ticketScoped($code);
        if ($t) {
            $this->db->table('tickets')->where('id', $t['id'])->update(th_status_change($t, [
                'status' => 'Resolved', 'resolved_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]));
            $this->addSystemNote((int) $t['id'], 'Status changed to Resolved');
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
            $this->db->table('tickets')->where('id', $t['id'])->update(th_status_change($t, ['status' => 'Open', 'resolved_at' => null, 'updated_at' => date('Y-m-d H:i:s')]));
            $this->addSystemNote((int) $t['id'], 'Ticket reopened');
            $this->toast($code . ' reopened');
        }

        return redirect()->back();
    }

    public function escalate(string $code)
    {
        $t = $this->ticketScoped($code);
        if ($t) {
            $priority = $t['priority'] === 'Urgent' ? 'Urgent' : 'High';
            $this->db->table('tickets')->where('id', $t['id'])->update(['escalated' => 1, 'priority' => $priority, 'updated_at' => date('Y-m-d H:i:s')]);
            $this->addSystemNote((int) $t['id'], 'Escalated to ' . $priority . ' priority');
            $this->toast('Escalated — supervisor notified', 'warn');
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

        $this->db->table('ticket_messages')->whereIn('ticket_id', $ids)->delete();
        $this->db->table('ticket_tasks')->whereIn('ticket_id', $ids)->delete();
        $this->db->table('ticket_assets')->whereIn('ticket_id', $ids)->delete();
        $this->db->table('ticket_field_values')->whereIn('ticket_id', $ids)->delete();
        $this->db->table('tickets')->whereIn('id', $ids)->delete();
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
        $candidates = $this->scopeTickets(
            $this->db->table('tickets')->where('id !=', $src['id'])->orderBy('updated_at', 'DESC')->get()->getResultArray()
        );

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
        // Move conversation (descriptions become replies), then remove source.
        $this->db->query(
            "UPDATE ticket_messages SET ticket_id = ?, kind = IF(kind='description','reply',kind) WHERE ticket_id = ?",
            [(int) $tgt['id'], (int) $src['id']]
        );
        $this->db->table('ticket_tasks')->where('ticket_id', $src['id'])->update(['ticket_id' => $tgt['id']]);
        $this->db->table('ticket_assets')->where('ticket_id', $src['id'])->delete();
        $this->db->table('ticket_field_values')->where('ticket_id', $src['id'])->delete();
        $this->db->table('tickets')->where('id', $src['id'])->delete();
        \App\Libraries\Audit::log('ticket.merged', $src['code'] . ' → ' . $tgt['code']);
        $this->addSystemNote((int) $tgt['id'], $src['code'] . ' merged into this ticket');
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

        \App\Libraries\Audit::log('request.' . strtolower($decision), $code . ' — ' . $ap['required_of']);
        $this->notify($decision === 'Approved' ? 'Request approved' : 'Request rejected', array_merge($t, ['status' => $decision === 'Approved' ? 'Open' : 'Closed']));
        $this->toast($code . ' ' . strtolower($decision));

        return redirect()->to('/app/tickets/' . $code);
    }

    public function claim(string $code)
    {
        $t = $this->ticketScoped($code);
        if ($t) {
            $this->db->table('tickets')->where('id', $t['id'])->update(['agent_id' => $this->me['id'], 'updated_at' => date('Y-m-d H:i:s')]);
            $this->addSystemNote((int) $t['id'], 'Assigned to ' . $this->me['name']);
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

        $this->db->table('ticket_time_entries')->insert([
            'ticket_id' => $t['id'], 'user_id' => (int) $this->me['id'],
            'minutes' => min($minutes, 60 * 24), 'spent_on' => $spentOn,
            'note' => trim((string) $this->request->getPost('note')) ?: null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
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
                    t.code, t.subject, t.status, t.priority
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
        $this->addSystemNote((int) $t['id'], 'Linked as ' . $kind . ' to ' . $other['code']);
        $this->toast('Linked to ' . $other['code']);

        return redirect()->to('/app/tickets/' . $code);
    }

    public function unlinkTicket(string $code, int $linkId)
    {
        $t = $this->ticketScoped($code);
        if ($t) {
            $this->db->table('ticket_links')->where('id', $linkId)
                ->groupStart()->where('from_id', $t['id'])->orWhere('to_id', $t['id'])->groupEnd()
                ->delete();
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
            $this->db->table('ticket_tasks')->insert([
                'ticket_id' => $t['id'], 'title' => $title, 'done' => 0,
                'owner_id' => $owner !== '' && $owner !== null ? (int) $owner : null,
            ]);
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
                $this->db->table('ticket_tasks')->where('id', $taskId)->update(['done' => (int) ! $task['done']]);
            }
        }

        return redirect()->to('/app/tickets/' . $code);
    }

    public function addTag(string $code)
    {
        $t = $this->ticketScoped($code);
        $tag = strtolower(trim((string) $this->request->getPost('tag')));
        $tag = preg_replace('/\s+/', '-', $tag);
        if ($t && $tag !== '') {
            $tags = json_decode($t['tags'] ?? '[]', true) ?: [];
            if (! in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
            $this->db->table('tickets')->where('id', $t['id'])->update(['tags' => json_encode($tags), 'updated_at' => date('Y-m-d H:i:s')]);
            $this->toast('Tag added');
        }

        return redirect()->to('/app/tickets/' . $code);
    }

    public function linkAsset(string $code)
    {
        $t = $this->ticketScoped($code);
        $assetId = (int) $this->request->getPost('asset_id');
        if ($t && $assetId) {
            $exists = $this->db->table('ticket_assets')->where('ticket_id', $t['id'])->where('asset_id', $assetId)->countAllResults();
            if (! $exists) {
                $this->db->table('ticket_assets')->insert(['ticket_id' => $t['id'], 'asset_id' => $assetId]);
            }
            $this->toast('Asset linked');
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
            $rows = $this->db->table('tickets')->whereIn('id', $ids)->get()->getResultArray();
            $ids  = array_map(static fn ($t) => (int) $t['id'], $this->scopeTickets($rows));
        }
        if (! $ids) {
            $this->toast('Nothing selected', 'warn');

            return redirect()->back();
        }
        $now = date('Y-m-d H:i:s');

        switch ($action) {
            case 'assign':
                $assignRows = $this->db->table('tickets')->whereIn('id', $ids)->get()->getResultArray();
                $this->db->table('tickets')->whereIn('id', $ids)->update(['agent_id' => (int) $value, 'updated_at' => $now]);
                $name = $this->userById((int) $value)['name'] ?? '?';
                foreach ($assignRows as $row) {
                    $this->addSystemNote((int) $row['id'], 'Assigned to ' . $name);
                    if ((int) $value !== (int) $this->me['id']) {
                        $this->notify('Ticket assigned', array_merge($row, ['agent_id' => (int) $value]));
                    }
                }
                $this->fireUpdated($ids);
                $this->toast(count($ids) . ' tickets updated');
                break;

            case 'status':
                if (! isset(TH_STATUS[$value])) {
                    $this->toast('That is not a valid status', 'warn');
                    break;
                }
                $statusRows = $this->db->table('tickets')->whereIn('id', $ids)->get()->getResultArray();
                $upd = ['status' => $value, 'updated_at' => $now];
                if ($value === 'Resolved') {
                    $upd['resolved_at'] = $now;
                }
                // Per row rather than one bulk statement: the reopen counter and the
                // stop-the-clock accounting both depend on where each ticket was.
                foreach ($statusRows as $row) {
                    $this->db->table('tickets')->where('id', $row['id'])
                        ->update(th_status_change($row, $upd));
                }
                foreach ($statusRows as $row) {
                    $this->addSystemNote((int) $row['id'], 'Status changed to ' . $value);
                    if ($value === 'Resolved') {
                        $this->notify('Status → Resolved', array_merge($row, $upd));
                    }
                }
                $this->fireUpdated($ids);
                $this->toast(count($ids) . ' tickets updated');
                break;

            case 'priority':
                if (! isset(TH_PRIORITY[$value])) {
                    $this->toast('That is not a valid priority', 'warn');
                    break;
                }
                $this->db->table('tickets')->whereIn('id', $ids)->update(['priority' => $value, 'updated_at' => $now]);
                foreach ($ids as $id) {
                    $this->addSystemNote($id, 'Priority set to ' . $value);
                }
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
                // Read the codes while the rows still exist — the audit line is
                // the only place they survive the merge.
                $mergedCodes = array_column($this->db->table('tickets')->whereIn('id', $ids)->get()->getResultArray(), 'code');
                foreach ($ids as $srcId) {
                    $this->db->query(
                        "UPDATE ticket_messages SET ticket_id = ?, kind = IF(kind='description','reply',kind) WHERE ticket_id = ?",
                        [$targetId, $srcId]
                    );
                    $this->db->table('ticket_tasks')->where('ticket_id', $srcId)->update(['ticket_id' => $targetId]);
                    $this->db->table('ticket_assets')->where('ticket_id', $srcId)->delete();
                    $this->db->table('ticket_field_values')->where('ticket_id', $srcId)->delete();
                    $this->db->table('tickets')->where('id', $srcId)->delete();
                }
                \App\Libraries\Audit::log('ticket.merged', implode(', ', $mergedCodes) . ' → ' . ($target['code'] ?? '?'));
                $this->addSystemNote($targetId, 'Merged ' . count($ids) . ' ticket(s) into this one');
                $this->toast('Merged into ' . ($target['code'] ?? '?'));

                return redirect()->to('/app/tickets/' . $target['code']);

            case 'delete':
                $this->deleteTicketRows($ids);
                $this->toast(count($ids) . ' tickets deleted', 'bad');
                break;
        }

        return redirect()->back();
    }
}
