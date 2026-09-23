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
        // Expired notices drop off on their own; a missing expiry means "until removed".
        $announcements = $this->db->table('announcements')
            ->groupStart()->where('expires_at', null)->orWhere('expires_at >', date('Y-m-d H:i:s'))->groupEnd()
            ->orderBy('created_at', 'DESC')->limit(2)->get()->getResultArray();

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
        if (! $t) {
            return redirect()->to('/portal/catalog');
        }
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
            // Optional selects: a request without them must not error, just default.
            'priority' => in_array($p['priority'] ?? '', ['Urgent', 'High', 'Medium', 'Low'], true) ? $p['priority'] : 'Medium',
            'category' => in_array($p['category'] ?? '', TH_CATEGORIES, true) ? $p['category'] : 'Software',
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
        $row = [
            'ticket_id' => $t['id'], 'kind' => 'reply', 'user_id' => $this->me['id'],
            'body' => $body, 'attachments' => json_encode($atts), 'created_at' => $now,
        ];
        if (\App\Libraries\TicketIntake::hasMessageFormat()) {
            $row['format'] = 'markdown';
        }
        $this->db->table('ticket_messages')->insert($row);
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

    /* ---------- Markdown editor (portal reply box) ---------- */

    public function preview()
    {
        return $this->markdownPreview();
    }

    public function inlineImage(string $code)
    {
        $t = $this->myTicketByCode($code);
        if (! $t) {
            return $this->editorJson(['error' => 'Ticket not found'], 404);
        }
        [$name, $err] = $this->storeInlineImage($t);
        if ($err) {
            return $this->editorJson(['error' => $err], 422);
        }

        return $this->editorJson(['url' => '/files/' . $name, 'name' => $name]);
    }

    /* ---------- CSAT from the resolution email (no sign-in) ---------- */

    /** The ticket behind a rating token, or null when the token is unknown. */
    private function ticketByCsatToken(string $token): ?array
    {
        if (! preg_match('/^[a-f0-9]{48}$/', $token) || ! $this->db->fieldExists('csat_token', 'tickets')) {
            return null;
        }

        return $this->db->table('tickets')->where('csat_token', $token)->get()->getRowArray();
    }

    /** Render the standalone rating page. */
    private function ratePage(array $vars)
    {
        if (($vars['state'] ?? '') === 'invalid') {
            $this->response->setStatusCode(404);
        }

        return view('portal/rate', $vars + ['title' => 'Rate this ticket']);
    }

    /**
     * GET portal/rate/(token)/(score) from the rating email: show the score picker with the tapped score
     * highlighted, but record nothing. Mail security scanners (Safe Links,
     * Mimecast, …) open every link in a message, so a GET that saved a score
     * would let the scanner "vote" before the person ever saw the email.
     */
    public function rateByToken(string $token, int $score)
    {
        $t = $this->ticketByCsatToken($token);
        if (! $t) {
            return $this->ratePage(['state' => 'invalid', 't' => null, 'token' => $token]);
        }
        if (th_is_open($t)) {
            return $this->ratePage(['state' => 'open', 't' => $t, 'token' => $token]);
        }
        if ($t['csat_score'] !== null) {
            return $this->ratePage(['state' => 'rated', 't' => $t, 'token' => $token, 'canComment' => $this->canComment($t)]);
        }

        return $this->ratePage(['state' => 'pick', 't' => $t, 'token' => $token, 'preselect' => ($score >= 1 && $score <= 5) ? $score : 0]);
    }

    /** POST from the picker: the one place a score is actually recorded. */
    public function rateSubmit(string $token)
    {
        $score = (int) $this->request->getPost('score');
        $t     = $this->ticketByCsatToken($token);
        if (! $t) {
            return $this->ratePage(['state' => 'invalid', 't' => null, 'token' => $token]);
        }
        if (th_is_open($t)) {
            return $this->ratePage(['state' => 'open', 't' => $t, 'token' => $token]);
        }
        if ($t['csat_score'] !== null) {
            return $this->ratePage(['state' => 'rated', 't' => $t, 'token' => $token, 'canComment' => $this->canComment($t)]);
        }
        if ($score < 1 || $score > 5) {
            return $this->ratePage(['state' => 'pick', 't' => $t, 'token' => $token]);
        }
        $this->db->table('tickets')->where('id', $t['id'])->where('csat_score', null)->update([
            'csat_score' => $score, 'csat_comment' => null, 'csat_rated_at' => date('Y-m-d H:i:s'),
        ]);
        if ($this->db->affectedRows() < 1) {
            // Raced with a second tap: show what stuck.
            $t = $this->ticketByCsatToken($token) ?? $t;

            return $this->ratePage(['state' => 'rated', 't' => $t, 'token' => $token, 'canComment' => $this->canComment($t)]);
        }
        $t['csat_score']    = $score;
        $t['csat_rated_at'] = date('Y-m-d H:i:s');
        try {
            $this->db->table('ticket_messages')->insert([
                'ticket_id' => $t['id'], 'kind' => 'system', 'user_id' => null,
                'body' => 'Rated ' . $score . '/5 by email', 'attachments' => '[]', 'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // cosmetic
        }

        return $this->ratePage(['state' => 'thanks', 't' => $t, 'token' => $token, 'canComment' => true]);
    }

    /** A comment may be added once, within seven days of the rating. */
    private function canComment(array $t): bool
    {
        if ($t['csat_score'] === null || ! empty($t['csat_comment'])) {
            return false;
        }
        $at = ! empty($t['csat_rated_at']) ? strtotime($t['csat_rated_at']) : null;

        return $at === null || (time() - $at) <= 7 * 86400;
    }

    /** POST portal/rate/(token) — the optional comment after a rating. */
    public function rateComment(string $token)
    {
        $t = $this->ticketByCsatToken($token);
        if (! $t || $t['csat_score'] === null) {
            return $this->ratePage(['state' => 'invalid', 't' => null, 'token' => $token]);
        }
        $comment = trim((string) $this->request->getPost('comment'));
        if ($comment === '' || ! $this->canComment($t)) {
            return $this->ratePage(['state' => 'rated', 't' => $t, 'token' => $token, 'canComment' => $this->canComment($t)]);
        }
        $this->db->table('tickets')->where('id', $t['id'])->where('csat_comment', null)->update(['csat_comment' => mb_substr($comment, 0, 2000)]);
        $t['csat_comment'] = $comment;

        return $this->ratePage(['state' => 'commented', 't' => $t, 'token' => $token, 'canComment' => false]);
    }
}
