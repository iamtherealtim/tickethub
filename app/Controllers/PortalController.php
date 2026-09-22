<?php

namespace App\Controllers;

class PortalController extends CatalogController
{
    private function portalShared(): array
    {
        $open = $this->db->table('tickets')
            ->where('requester_id', $this->me['id'])
            ->whereIn('status', TH_OPEN_STATES)
            ->countAllResults();

        return ['me' => $this->me, 'portalOpenCount' => $open, 'users' => $this->users()];
    }

    private function myTicketByCode(string $code): ?array
    {
        $t = $this->ticketByCode($code);
        if (! $t) {
            return null;
        }
        // Your own ticket is always visible.
        if ((int) $t['requester_id'] === (int) $this->me['id']) {
            return $t;
        }
        // Agents viewing someone else's ticket are held to the same group scope
        // as the workspace — the portal must not be a way around it.
        if ($this->isAgentRole() && $this->canSeeTicket($t)) {
            return $t;
        }

        return null;
    }

    public function home()
    {
        $mine = $this->db->table('tickets')->where('requester_id', $this->me['id'])
            ->whereIn('status', TH_OPEN_STATES)->orderBy('updated_at', 'DESC')->get()->getResultArray();
        $popular = $this->db->table('articles')->where('status', 'Published')->orderBy('views', 'DESC')->limit(4)->get()->getResultArray();
        $announcements = $this->db->table('announcements')->orderBy('created_at', 'DESC')->limit(2)->get()->getResultArray();

        return view('portal/home', $this->portalShared() + [
            'title' => 'Home', 'pnav' => '',
            'mine' => $mine, 'popular' => $popular, 'announcements' => $announcements,
        ]);
    }

    public function catalog()
    {
        return view('portal/catalog', $this->portalShared() + [
            'title' => 'Service catalog', 'pnav' => 'catalog',
            'items' => $this->db->table('catalog_items')->orderBy('id')->get()->getResultArray(),
            'cat' => (string) ($this->request->getGet('cat') ?: 'All'),
        ]);
    }

    public function requestItem(int $id)
    {
        $item = $this->db->table('catalog_items')->where('id', $id)->get()->getRowArray();
        if (! $item) {
            return redirect()->to('/portal/catalog');
        }
        $t = $this->submitCatalogRequest($item, (int) $this->me['id']);
        $this->toast('Request sent — ' . $t['code']);

        return redirect()->to('/portal/tickets/' . $t['code']);
    }

    public function kb()
    {
        return view('portal/kb', $this->portalShared() + [
            'title' => 'Knowledge base', 'pnav' => 'kb',
            'articles' => $this->db->table('articles')->where('status', 'Published')->orderBy('views', 'DESC')->get()->getResultArray(),
            'cat' => (string) ($this->request->getGet('cat') ?: 'All'),
        ]);
    }

    public function article(int $id)
    {
        $a = $this->db->table('articles')->where('id', $id)->where('status', 'Published')->get()->getRowArray();
        if (! $a) {
            $this->toast('Article not found', 'warn');

            return redirect()->to('/portal/kb');
        }
        $this->db->table('articles')->where('id', $id)->update(['views' => (int) $a['views'] + 1]);

        return view('portal/article', $this->portalShared() + [
            'title' => $a['title'], 'pnav' => 'kb', 'a' => $a,
        ]);
    }

    public function vote(int $id)
    {
        // Portal users may only vote on published articles — otherwise the
        // differing toast confirms which draft ids exist.
        if (! $this->isAgentRole()
            && ! $this->db->table('articles')->where('id', $id)->where('status', 'Published')->countAllResults()) {
            $this->toast('That article no longer exists', 'warn');

            return redirect()->back();
        }
        $this->toast($this->recordVote($id, (string) $this->request->getPost('vote')));

        return redirect()->back();
    }

    public function newTicket()
    {
        return view('portal/new', $this->portalShared() + [
            'title' => 'Report a problem', 'pnav' => '',
            'customFields' => $this->db->table('ticket_fields')->where('portal', 1)->orderBy('id')->get()->getResultArray(),
        ]);
    }

    public function submitTicket()
    {
        $p = $this->request->getPost();
        if (empty($p['subject']) || empty($p['body'])) {
            $this->toast('A subject and some detail are needed', 'warn');

            return redirect()->back();
        }
        // Enforce required portal custom fields.
        foreach ($this->db->table('ticket_fields')->where('portal', 1)->where('required', 1)->get()->getResultArray() as $f) {
            if ($f['type'] !== 'Checkbox' && trim((string) $this->request->getPost('cf_' . $f['id'])) === '') {
                $this->toast('"' . $f['label'] . '" is required', 'warn');

                return redirect()->back()->withInput();
            }
        }
        $t = $this->createTicket([
            'subject' => $p['subject'], 'body' => $p['body'],
            'requester_id' => (int) $this->me['id'],
            'priority' => in_array($p['priority'], ['Urgent', 'High', 'Medium', 'Low'], true) ? $p['priority'] : 'Medium',
            'category' => in_array($p['category'], TH_CATEGORIES, true) ? $p['category'] : 'Software',
            'type' => 'Incident', 'source' => 'Portal',
            'attachments' => $this->storeUploads(),
        ]);
        $this->saveCustomFields((int) $t['id'], 'portal');
        $this->toast($t['code'] . ' raised — we are on it');

        return redirect()->to('/portal/tickets/' . $t['code']);
    }

    public function myTickets()
    {
        $all = $this->db->table('tickets')->where('requester_id', $this->me['id'])->orderBy('updated_at', 'DESC')->get()->getResultArray();
        $open = array_values(array_filter($all, 'th_is_open'));
        $done = array_values(array_filter($all, static fn ($t) => ! th_is_open($t)));

        return view('portal/mytickets', $this->portalShared() + [
            'title' => 'My tickets', 'pnav' => 'mytickets',
            'open' => $open, 'done' => $done,
            'tab' => $this->request->getGet('tab') === 'closed' ? 'closed' : 'open',
        ]);
    }

    public function ticket(string $code)
    {
        $t = $this->myTicketByCode($code);
        if (! $t) {
            $this->toast('Ticket not found — it may have been merged into another request', 'warn');

            return redirect()->to('/portal/tickets');
        }
        $messages = $this->db->table('ticket_messages')->where('ticket_id', $t['id'])
            ->whereIn('kind', ['description', 'reply', 'system'])->orderBy('created_at')->orderBy('id')->get()->getResultArray();
        // Portal hides private notes and internal system chatter beyond status changes.
        $messages = array_values(array_filter($messages, static fn ($m) => $m['kind'] !== 'system' || str_starts_with($m['body'], 'Status') || str_starts_with($m['body'], 'Waiting')));

        return view('portal/ticket', $this->portalShared() + [
            'title' => $t['code'], 'pnav' => 'mytickets', 't' => $t, 'messages' => $messages,
            'fieldValues' => $this->db->query(
                'SELECT f.label, v.value FROM ticket_field_values v JOIN ticket_fields f ON f.id = v.field_id WHERE v.ticket_id = ? AND f.portal = 1 ORDER BY f.id',
                [(int) $t['id']]
            )->getResultArray(),
        ]);
    }

    public function reply(string $code)
    {
        $t = $this->myTicketByCode($code);
        $body = trim((string) $this->request->getPost('body'));
        if (! $t) {
            return redirect()->to('/portal/tickets');
        }
        $atts = $this->storeUploads();
        if ($body === '' && ! $atts) {
            $this->toast('Write something first', 'warn');

            return redirect()->back();
        }
        if ($body === '') {
            $body = '(attachment)';
        }
        $now = date('Y-m-d H:i:s');
        $this->db->table('ticket_messages')->insert([
            'ticket_id' => $t['id'], 'kind' => 'reply', 'user_id' => $this->me['id'],
            'body' => $body, 'attachments' => json_encode($atts), 'created_at' => $now,
        ]);
        $upd = ['updated_at' => $now];
        if ($t['status'] === 'Pending') {
            $upd['status'] = 'Open';
        }
        if (! th_is_open($t)) {
            $upd['status'] = 'Open';
            $upd['resolved_at'] = null;
        }
        $this->db->table('tickets')->where('id', $t['id'])->update(th_status_change($t, $upd));
        $this->fireUpdated((int) $t['id']);
        $this->toast('Sent — the service desk has been notified');

        return redirect()->to('/portal/tickets/' . $code);
    }

    public function rate(string $code)
    {
        $t = $this->myTicketByCode($code);
        $score = (int) $this->request->getPost('score');
        $comment = trim((string) $this->request->getPost('comment'));

        // A second pass carries only the comment: the stars are recorded first,
        // then a low score is asked why. Never overwrite a score with nothing.
        if ($t && $comment !== '' && $t['csat_score'] !== null) {
            $this->db->table('tickets')->where('id', $t['id'])
                ->update(['csat_comment' => mb_substr($comment, 0, 2000)]);
            $this->toast('Thank you — that helps us fix it');

            return redirect()->to('/portal/tickets/' . $code);
        }

        if ($t && $score >= 1 && $score <= 5 && ! th_is_open($t)) {
            $this->db->table('tickets')->where('id', $t['id'])->update(['csat_score' => $score, 'csat_comment' => null]);
            $this->toast($score <= 3 ? 'Thanks — what went wrong?' : 'Thanks for the feedback');
        }

        return redirect()->to('/portal/tickets/' . $code);
    }

    /** Article suggestions while a requester types — same scorer, portal links. */
    public function kbSuggest()
    {
        return $this->kbSuggestions((string) $this->request->getGet('q'), 'portal/kb/');
    }

    /** HTML fragment for the portal home live search. */
    public function search()
    {
        $q = mb_strtolower(trim((string) $this->request->getGet('q')));
        if ($q === '') {
            return $this->response->setBody('');
        }
        $arts = array_values(array_filter(
            $this->db->table('articles')->where('status', 'Published')->get()->getResultArray(),
            static fn ($a) => str_contains(mb_strtolower($a['title'] . $a['category'] . ($a['tags'] ?? '')), $q)
        ));
        $items = array_values(array_filter(
            $this->db->table('catalog_items')->get()->getResultArray(),
            static fn ($c) => str_contains(mb_strtolower($c['name'] . $c['category'] . $c['description']), $q)
        ));

        return view('portal/_search_results', ['arts' => array_slice($arts, 0, 4), 'items' => array_slice($items, 0, 3), 'q' => $q]);
    }
}
