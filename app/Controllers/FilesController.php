<?php

namespace App\Controllers;

class FilesController extends BaseController
{
    public function serve(string $stored)
    {
        if (! preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,5}$/', $stored)) {
            return $this->response->setStatusCode(404, 'Not found');
        }
        $path = WRITEPATH . 'uploads/tickets/' . $stored;
        if (! is_file($path)) {
            return $this->response->setStatusCode(404, 'Not found');
        }

        // The file must belong to a ticket this user is allowed to see.
        $owner = null;
        $entry = null;
        foreach ($this->db->table('ticket_messages')->like('attachments', $stored)->get()->getResultArray() as $m) {
            foreach (json_decode($m['attachments'] ?? '[]', true) ?: [] as $a) {
                if (($a['f'] ?? '') === $stored) {
                    $owner = $m;
                    $entry = $a;
                    break 2;
                }
            }
        }
        if (! $owner) {
            return $this->response->setStatusCode(404, 'Not found');
        }
        $ticket = $this->db->table('tickets')->where('id', $owner['ticket_id'])->get()->getRowArray();
        if (! $ticket) {
            return $this->response->setStatusCode(404, 'Not found');
        }
        $isAgent = $this->isAgentRole() && $this->canSeeTicket($ticket);
        $allowed = $isAgent || (int) $ticket['requester_id'] === (int) $this->me['id'];
        if ($allowed && ! $isAgent) {
            // Requesters only get what was actually shown to them: nothing
            // attached to a private note, and a pasted inline image only once a
            // public reply on this ticket actually embeds it (inline images are
            // parked on the first message whether they end up in a reply or a note).
            if (! empty($entry['inline'])) {
                $allowed = $this->db->table('ticket_messages')
                    ->where('ticket_id', $ticket['id'])->whereIn('kind', ['description', 'reply'])
                    ->like('body', '/files/' . $stored)->countAllResults() > 0;
            } elseif (in_array($owner['kind'] ?? '', ['note', 'system'], true)) {
                $allowed = false;
            }
        }
        if (! $allowed) {
            return $this->response->setStatusCode(403, 'Forbidden');
        }

        // The download name may come from the link (?n=), but always keeps the
        // stored extension — a link must not be able to rename notes.txt to
        // Invoice.exe on a trusted domain.
        $ext  = pathinfo($stored, PATHINFO_EXTENSION);
        $want = (string) ($this->request->getGet('n') ?: ($entry['n'] ?? $stored));
        $base = pathinfo($want, PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^\w .\-()]+/u', '_', $base), ' .') ?: 'attachment';
        $name = mb_substr($base, 0, 120) . '.' . $ext;

        return $this->response->download($path, null)->setFileName($name);
    }
}
