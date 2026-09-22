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
        foreach ($this->db->table('ticket_messages')->like('attachments', $stored)->get()->getResultArray() as $m) {
            foreach (json_decode($m['attachments'] ?? '[]', true) ?: [] as $a) {
                if (($a['f'] ?? '') === $stored) {
                    $owner = $m;
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
        $allowed = (int) $ticket['requester_id'] === (int) $this->me['id']
            || ($this->isAgentRole() && $this->canSeeTicket($ticket));
        if (! $allowed) {
            return $this->response->setStatusCode(403, 'Forbidden');
        }

        $name = preg_replace('/[^\w .\-()]+/u', '_', (string) ($this->request->getGet('n') ?: $stored));

        return $this->response->download($path, null)->setFileName($name);
    }
}
