<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;

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
class TicketsApiController extends BaseController
{
    private const PER_PAGE_MAX = 100;

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

        $perPage = min(self::PER_PAGE_MAX, max(1, (int) ($this->request->getGet('per_page') ?: 25)));
        $page    = max(1, (int) ($this->request->getGet('page') ?: 1));
        $total   = count($rows);

        return $this->response->setJSON([
            'data' => array_map([$this, 'shape'], array_slice($rows, ($page - 1) * $perPage, $perPage)),
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage],
        ]);
    }

    public function show(string $code)
    {
        $t = $this->ticketScoped($code);
        if (! $t) {
            return $this->fail('Ticket not found', 404);
        }
        $messages = $this->db->table('ticket_messages')
            ->where('ticket_id', $t['id'])->orderBy('created_at')->orderBy('id')->get()->getResultArray();

        $users = $this->users();

        return $this->response->setJSON($this->shape($t) + [
            'messages' => array_map(static fn ($m) => [
                'kind'       => $m['kind'],
                'author'     => $m['user_id'] ? ($users[(int) $m['user_id']]['name'] ?? null) : null,
                'body'       => $m['body'],
                'created_at' => $m['created_at'],
                // Private notes are agent-only; the API mirrors that.
            ], array_values(array_filter(
                $messages,
                fn ($m) => $m['kind'] !== 'note' || $this->isAgent()
            ))),
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

        $groupId = isset($in['group_id']) && $in['group_id'] !== '' && $in['group_id'] !== null ? (int) $in['group_id'] : null;
        if ($groupId !== null && ! $this->db->table('groups')->where('id', $groupId)->countAllResults()) {
            return $this->fail('group_id does not match a group', 422);
        }

        $t = $this->createTicket([
            'subject'      => (string) $in['subject'],
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

        return $this->response->setStatusCode(201)->setJSON($this->shape($fresh));
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
        }

        if ($kind === 'reply') {
            $this->notify('Public reply sent', array_merge($t, $upd), ['message' => $body]);
        }

        return $this->response->setStatusCode(201)->setJSON(['ok' => true, 'code' => $t['code'], 'kind' => $kind]);
    }

    /* ---------- helpers ---------- */

    /** One ticket, as the API represents it. */
    private function shape(array $t): array
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
            'assignee'  => $t['agent_id'] ? ($users[(int) $t['agent_id']]['name'] ?? null) : null,
            'group_id'  => (int) $t['group_id'],
            'escalated' => (bool) $t['escalated'],
            'sla'       => ['state' => $sla['state'], 'percent_used' => round($sla['pct'], 1), 'due_at' => $t['res_due']],
            'created_at' => $t['created_at'],
            'updated_at' => $t['updated_at'],
            'resolved_at' => $t['resolved_at'],
            'url'        => site_url('app/tickets/' . $t['code']),
        ];
    }

    /**
     * Decoded request body, or null when the caller sent JSON that does not parse.
     * Form-encoded callers are tolerated too; the shape is identical either way.
     */
    private function json(): ?array
    {
        try {
            $body = $this->request->getJSON(true);
        } catch (\Throwable $e) {
            return null;
        }
        if ($body === null && trim((string) $this->request->getBody()) !== ''
            && str_contains(strtolower($this->request->getHeaderLine('Content-Type')), 'json')) {
            return null; // e.g. a bare "null" or a body the framework declined to parse
        }

        return is_array($body) ? $body : (array) $this->request->getPost();
    }

    private function pick(?string $value, array $allowed, string $fallback): string
    {
        return $value !== null && in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function isAgent(): bool
    {
        return in_array($this->me['role'] ?? '', ['Administrator', 'Supervisor', 'Agent'], true);
    }

    private function fail(string $message, int $code)
    {
        return $this->response->setStatusCode($code)->setJSON(['error' => $message]);
    }
}
