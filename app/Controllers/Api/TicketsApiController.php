<?php

namespace App\Controllers\Api;

/**
 * JSON API for tickets.
 *
 * Deliberately thin: it reuses the same intake, scoping and notification paths
 * the UI uses, so an API-created ticket is routed, SLA'd and notified exactly
 * like one raised at the desk. Anything else would drift.
 *
 * Auth is a bearer token bound to a user (see App\Filters\ApiAuth), so every
 * response is already limited to what that person may see.
 */
class TicketsApiController extends ApiController
{
    public function index()
    {
        $rows = $this->scopeTickets($this->db->table('tickets')->orderBy('id', 'DESC')->get()->getResultArray());

        $status = (string) $this->request->getGet('status');
        if ($status !== '') {
            $rows = array_values(array_filter($rows, static fn ($t) => $t['status'] === $status));
        }
        if ($this->request->getGet('open') === '1') {
            $rows = array_values(array_filter($rows, 'th_is_open'));
        }
        foreach (['priority', 'type', 'category'] as $f) {
            $v = (string) $this->request->getGet($f);
            if ($v !== '') {
                $rows = array_values(array_filter($rows, static fn ($t) => $t[$f] === $v));
            }
        }
        if ($gid = (int) $this->request->getGet('group_id')) {
            $rows = array_values(array_filter($rows, static fn ($t) => (int) $t['group_id'] === $gid));
        }
        if ($aid = (int) $this->request->getGet('agent_id')) {
            $rows = array_values(array_filter($rows, static fn ($t) => (int) $t['agent_id'] === $aid));
        }
        if ($rid = (int) $this->request->getGet('requester_id')) {
            $rows = array_values(array_filter($rows, static fn ($t) => (int) $t['requester_id'] === $rid));
        }
        $q = mb_strtolower(trim((string) $this->request->getGet('q')));
        if ($q !== '') {
            $rows = array_values(array_filter($rows, static fn ($t) => str_contains(mb_strtolower($t['subject'] . ' ' . $t['code']), $q)));
        }

        return $this->paginateArray($rows, [$this, 'shape']);
    }

    public function show(string $code)
    {
        $t = $this->ticketScoped($code);
        if (! $t) {
            return $this->fail('Ticket not found', 404);
        }

        return $this->ok($this->shape($t) + ['messages' => $this->messageList((int) $t['id'])]);
    }

    /** GET api/tickets/{code}/messages — the conversation only. */
    public function messages(string $code)
    {
        $t = $this->ticketScoped($code);
        if (! $t) {
            return $this->fail('Ticket not found', 404);
        }
        $all = $this->messageList((int) $t['id']);
        [$page, $perPage] = $this->paging();

        return $this->ok([
            'data' => array_slice($all, ($page - 1) * $perPage, $perPage),
            'page' => $page, 'per_page' => $perPage, 'total' => count($all),
        ]);
    }

    public function create()
    {
        $in = $this->json();
        if ($in === null) {
            return $this->fail('Invalid JSON body', 400);
        }
        if (empty($in['subject']) || empty($in['body'])) {
            return $this->fail('subject and body are required', 422);
        }

        $requesterId = (int) ($in['requester_id'] ?? 0);
        if ($requesterId && ! $this->db->table('users')->where('id', $requesterId)->where('active', 1)->countAllResults()) {
            return $this->fail('requester_id does not match an active user', 422);
        }
        if ($requesterId && ! $this->isAgent() && $requesterId !== (int) $this->me['id']) {
            return $this->fail('You can only raise tickets as yourself', 403);
        }

        $groupId = isset($in['group_id']) && $in['group_id'] !== '' && $in['group_id'] !== null ? (int) $in['group_id'] : null;
        if ($groupId !== null && ! $this->db->table('groups')->where('id', $groupId)->countAllResults()) {
            return $this->fail('group_id does not match a group', 422);
        }
        // Same rule as the New ticket form: an agent may only file into a
        // group they can use; leave it empty to let routing decide.
        if ($groupId !== null && ! $this->canUseGroup($groupId)) {
            return $this->fail('You cannot file a ticket into that group', 403);
        }

        $t = $this->createTicket([
            'subject'      => mb_substr((string) $in['subject'], 0, 250),
            'body'         => (string) $in['body'],
            // Defaults to the token's own user, which makes the common
            // "raise a ticket as me" call a two-field request.
            'requester_id' => $requesterId ?: (int) $this->me['id'],
            'priority'     => $this->pick($in['priority'] ?? null, array_keys(TH_PRIORITY), 'Medium'),
            'type'         => $this->pick($in['type'] ?? null, ['Incident', 'Service request'], 'Incident'),
            'category'     => $this->pick($in['category'] ?? null, TH_CATEGORIES, 'Software'),
            'source'       => 'API',
            'group_id'     => $groupId,
        ]);

        \App\Libraries\Audit::log('api.ticket_created', $t['code']);

        // Re-read so the response is the canonical row — createTicket() hands back
        // the insert payload, which has no resolved_at and no routing side effects.
        $fresh = $this->ticketByCode($t['code']) ?? $t;

        return $this->ok($this->shape($fresh), 201);
    }

    /**
     * PATCH api/tickets/{code} — status, priority, type, category, subject,
     * agent_id, group_id, tags. Same authorisation as the ticket page:
     * canAssignTo() / canUseGroup() decide who may hand work where.
     */
    public function update(string $code)
    {
        $t = $this->ticketScoped($code);
        if (! $t) {
            return $this->fail('Ticket not found', 404);
        }
        if (! $this->isAgent()) {
            return $this->fail('Only agents can change tickets', 403);
        }
        $in = $this->json();
        if ($in === null) {
            return $this->fail('Invalid JSON body', 400);
        }
        $allowedKeys = ['status', 'priority', 'type', 'category', 'subject', 'agent_id', 'group_id', 'tags'];
        $changes = array_intersect_key($in, array_flip($allowedKeys));
        if (! $changes) {
            return $this->fail('Nothing to change — send one of: ' . implode(', ', $allowedKeys), 422);
        }

        $now   = date('Y-m-d H:i:s');
        $upd   = ['updated_at' => $now];
        $notes = [];
        $after = [];

        foreach ($changes as $field => $value) {
            switch ($field) {
                case 'status':
                    if (! isset(TH_STATUS[$value])) {
                        return $this->fail('status must be one of ' . implode(', ', array_keys(TH_STATUS)), 422);
                    }
                    if ($value !== $t['status'] && $this->approvalBlocksStatus((int) $t['id'], $value)) {
                        return $this->fail('This request is still waiting on an approval; approve or reject it first', 409);
                    }
                    if ($value !== $t['status']) {
                        $upd['status'] = $value;
                        if ($value === 'Resolved') {
                            $upd['resolved_at'] = $now;
                        } elseif (in_array($value, TH_OPEN_STATES, true)) {
                            $upd['resolved_at'] = null;
                        } elseif ($value === 'Closed' && empty($t['resolved_at'])) {
                            $upd['resolved_at'] = $now;
                        }
                        $notes[] = 'Status changed to ' . $value;
                        if ($value === 'Resolved') {
                            $after[] = fn () => $this->notify('Status → Resolved', array_merge($t, $upd));
                        }
                    }
                    break;

                case 'priority':
                case 'type':
                case 'category':
                    $allowed = match ($field) {
                        'priority' => array_keys(TH_PRIORITY),
                        'type'     => ['Incident', 'Service request'],
                        default    => TH_CATEGORIES,
                    };
                    if (! in_array($value, $allowed, true)) {
                        return $this->fail($field . ' must be one of ' . implode(', ', $allowed), 422);
                    }
                    if ($value !== $t[$field]) {
                        $upd[$field] = $value;
                        $notes[] = ucfirst($field) . ' set to ' . $value;
                    }
                    break;

                case 'subject':
                    $value = trim((string) $value);
                    if ($value === '') {
                        return $this->fail('subject cannot be empty', 422);
                    }
                    $upd['subject'] = mb_substr($value, 0, 250);
                    $notes[] = 'Subject edited';
                    break;

                case 'agent_id':
                    $agentId = $value === null || $value === '' ? null : (int) $value;
                    if ($agentId && ! $this->db->table('users')->where('id', $agentId)->where('active', 1)->whereIn('role', ['Administrator', 'Supervisor', 'Agent'])->countAllResults()) {
                        return $this->fail('agent_id does not match an active agent', 422);
                    }
                    if (! $this->canAssignTo($agentId)) {
                        return $this->fail('You cannot assign this ticket to that person', 403);
                    }
                    if ($agentId !== ((int) $t['agent_id'] ?: null)) {
                        $upd['agent_id'] = $agentId;
                        $notes[] = $agentId ? 'Assigned to ' . ($this->userById($agentId)['name'] ?? '?') : 'Unassigned';
                        if ($agentId && $agentId !== (int) $this->me['id']) {
                            $after[] = function () use ($t, &$upd, $agentId) {
                                $fresh = array_merge($t, $upd);
                                $this->notify('Ticket assigned', $fresh);
                                $this->notifyUsers([$agentId], 'assigned', $t['code'] . ' assigned to you by ' . $this->me['name'], site_url('app/tickets/' . $t['code']), (int) $t['id'], $t['subject']);
                            };
                        }
                    }
                    break;

                case 'group_id':
                    $groupId = (int) $value;
                    if (! $this->canUseGroup($groupId, $t)) {
                        return $this->fail($this->db->table('groups')->where('id', $groupId)->countAllResults()
                            ? 'You cannot move this ticket into that group' : 'group_id does not match a group', $this->db->table('groups')->where('id', $groupId)->countAllResults() ? 403 : 422);
                    }
                    if ($groupId !== (int) $t['group_id']) {
                        $upd['group_id'] = $groupId;
                        $g = $this->db->table('groups')->where('id', $groupId)->get()->getRowArray();
                        $notes[] = 'Moved to ' . ($g['name'] ?? '?');
                    }
                    break;

                case 'tags':
                    if (is_string($value)) {
                        $value = preg_split('/[,\s]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
                    }
                    if (! is_array($value)) {
                        return $this->fail('tags must be an array of strings', 422);
                    }
                    $tags = [];
                    foreach ($value as $tag) {
                        $tag = strtolower(preg_replace('/\s+/', '-', trim((string) $tag)));
                        if ($tag !== '' && mb_strlen($tag) <= 30 && ! in_array($tag, $tags, true)) {
                            $tags[] = $tag;
                        }
                    }
                    $upd['tags'] = json_encode(array_slice($tags, 0, 20));
                    $notes[] = 'Tags set to ' . ($tags ? implode(', ', $tags) : 'none');
                    break;
            }
        }

        if (count($upd) > 1) {
            $this->db->table('tickets')->where('id', $t['id'])->update(th_status_change($t, $upd));
            foreach ($notes as $n) {
                $this->addSystemNote((int) $t['id'], $n);
            }
            foreach ($after as $fn) {
                $fn();
            }
            $this->fireUpdated((int) $t['id']);
            \App\Libraries\Audit::log('api.ticket_updated', $t['code'] . ' — ' . implode('; ', $notes));
        }

        return $this->ok($this->shape($this->ticketByCode($code) ?? $t));
    }

    public function reply(string $code)
    {
        $t = $this->ticketScoped($code);
        if (! $t) {
            return $this->fail('Ticket not found', 404);
        }
        $in = $this->json();
        if ($in === null) {
            return $this->fail('Invalid JSON body', 400);
        }
        $body = trim((string) ($in['body'] ?? ''));
        if ($body === '') {
            return $this->fail('body is required', 422);
        }
        $kind = ($in['kind'] ?? 'reply') === 'note' ? 'note' : 'reply';
        if ($kind === 'note' && ! $this->isAgent()) {
            return $this->fail('Only agents can add private notes', 403);
        }

        $now = date('Y-m-d H:i:s');
        $this->db->table('ticket_messages')->insert([
            'ticket_id' => $t['id'], 'kind' => $kind, 'user_id' => (int) $this->me['id'],
            'body' => $body, 'attachments' => '[]', 'created_at' => $now,
        ]);

        $upd = ['updated_at' => $now];
        if ($kind === 'reply' && $t['status'] === 'New') {
            $upd['status'] = 'Open';
        }
        $this->db->table('tickets')->where('id', $t['id'])->update(th_status_change($t, $upd));
        if ($kind === 'reply') {
            \App\Libraries\TicketIntake::notifyReply(array_merge($t, $upd), (int) $this->me['id'], (string) $this->me['name']);
            $this->notify('Public reply sent', array_merge($t, $upd), ['message' => $body]);
        }
        $this->fireUpdated((int) $t['id']);

        return $this->ok(['ok' => true, 'code' => $t['code'], 'kind' => $kind], 201);
    }

    /* ---------- helpers ---------- */

    /** One ticket, as the API represents it. */
    protected function shape(array $t): array
    {
        $users = $this->users();
        $sla   = th_sla($t);

        return [
            'code'      => $t['code'],
            'subject'   => $t['subject'],
            'status'    => $t['status'],
            'priority'  => $t['priority'],
            'type'      => $t['type'],
            'category'  => $t['category'],
            'source'    => $t['source'],
            'requester' => $users[(int) $t['requester_id']]['name'] ?? null,
            'requester_id' => (int) $t['requester_id'],
            'assignee'  => $t['agent_id'] ? ($users[(int) $t['agent_id']]['name'] ?? null) : null,
            'agent_id'  => $t['agent_id'] ? (int) $t['agent_id'] : null,
            'group_id'  => (int) $t['group_id'],
            'tags'      => json_decode($t['tags'] ?? '[]', true) ?: [],
            'escalated' => (bool) $t['escalated'],
            'sla'       => ['state' => $sla['state'], 'percent_used' => round($sla['pct'], 1), 'due_at' => $t['res_due']],
            'created_at' => $t['created_at'],
            'updated_at' => $t['updated_at'],
            'resolved_at' => $t['resolved_at'],
            'url'        => site_url('app/tickets/' . $t['code']),
        ];
    }

    /** Messages for a ticket; private notes are agent-only, as in the UI. */
    private function messageList(int $ticketId): array
    {
        $users = $this->users();
        $rows  = $this->db->table('ticket_messages')->where('ticket_id', $ticketId)->orderBy('created_at')->orderBy('id')->get()->getResultArray();

        return array_values(array_map(static fn ($m) => [
            'id'         => (int) $m['id'],
            'kind'       => $m['kind'],
            'author'     => $m['user_id'] ? ($users[(int) $m['user_id']]['name'] ?? null) : null,
            'user_id'    => $m['user_id'] ? (int) $m['user_id'] : null,
            'body'       => $m['body'],
            // Pasted inline images are parked on the first message for storage
            // only; they are reachable through the message body that embeds
            // them, never listed here (that listing used to leak images pasted
            // into private notes to requester tokens).
            'attachments' => array_values(array_map(
                static fn ($a) => ['name' => $a['n'] ?? '', 'bytes' => (int) ($a['s'] ?? 0), 'url' => isset($a['f']) ? site_url('files/' . $a['f']) : null],
                array_filter(json_decode($m['attachments'] ?? '[]', true) ?: [], static fn ($a) => empty($a['inline']))
            )),
            'created_at' => $m['created_at'],
        ], array_filter($rows, fn ($m) => $m['kind'] !== 'note' || $this->isAgent())));
    }
}
