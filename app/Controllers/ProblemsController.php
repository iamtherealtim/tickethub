<?php

namespace App\Controllers;

class ProblemsController extends BaseController
{
    public const STATUSES = ['Under investigation', 'Root cause identified', 'Known error', 'Resolved'];

    /** Deleting a problem unlinks every incident under it, so it is supervisory. */
    private function canManage(): bool
    {
        return in_array($this->me['role'] ?? '', ['Administrator', 'Supervisor'], true);
    }

    public function index()
    {
        $problems = $this->db->table('problems')->orderBy('opened_at', 'DESC')->get()->getResultArray();

        // Counted over the same scoped set the problem page lists, so the number
        // on the card never promises incidents the agent then cannot see.
        $linkedCounts = [];
        $linkedTickets = $this->scopeTickets($this->db->table('tickets')->where('problem_id IS NOT NULL', null, false)->get()->getResultArray());
        foreach ($linkedTickets as $t) {
            $linkedCounts[(int) $t['problem_id']] = ($linkedCounts[(int) $t['problem_id']] ?? 0) + 1;
        }

        return view('agent/problems', $this->agentShared() + [
            'title' => 'Problems', 'nav' => 'problems',
            'problems' => $problems, 'linkedCounts' => $linkedCounts,
            'users' => $this->users(),
        ]);
    }

    public function show(int $id)
    {
        $p = $this->db->table('problems')->where('id', $id)->get()->getRowArray();
        if (! $p) {
            $this->toast('Problem not found', 'warn');

            return redirect()->to('/app/problems');
        }
        // Both lists expose ticket subjects, so they follow the group scope.
        $linked = $this->scopeTickets($this->db->table('tickets')->where('problem_id', $id)->orderBy('created_at', 'DESC')->get()->getResultArray());

        $article = ! empty($p['kb_article_id'])
            ? $this->db->table('articles')->where('id', (int) $p['kb_article_id'])->get()->getRowArray()
            : null;

        return view('agent/problem', $this->agentShared() + [
            'title' => $p['code'], 'nav' => 'problems',
            'p' => $p, 'linked' => $linked,
            'users' => $this->users(),
            'canManage' => $this->canManage(),
            'article' => $article,
            'articles' => $this->db->table('articles')->select('id, title, category')->where('status', 'Published')->orderBy('title')->get()->getResultArray(),
        ]);
    }

    public function update(int $id)
    {
        $p = $this->db->table('problems')->where('id', $id)->get()->getRowArray();
        $post = $this->request->getPost();
        if (! $p || empty($post['title'])) {
            $this->toast('The problem needs a title', 'warn');

            return redirect()->back();
        }
        // A known error must point at a published article; anything else clears the link.
        $kb = null;
        if (! empty($post['kb_article_id'])) {
            $kb = $this->db->table('articles')->where('id', (int) $post['kb_article_id'])->where('status', 'Published')->countAllResults() ? (int) $post['kb_article_id'] : null;
            if ($kb === null) {
                $this->toast('That article is not published, so it was not linked', 'warn');
            }
        }
        $this->db->table('problems')->where('id', $id)->update([
            'title' => $post['title'],
            'status' => in_array($post['status'] ?? '', self::STATUSES, true) ? $post['status'] : $p['status'],
            'priority' => in_array($post['priority'] ?? '', ['Urgent', 'High', 'Medium', 'Low'], true) ? $post['priority'] : $p['priority'],
            'owner_id' => (int) ($post['owner_id'] ?? $p['owner_id']),
            'cause' => ($post['cause'] ?? '') ?: '—',
            'workaround' => ($post['workaround'] ?? '') ?: 'None yet',
            'kb_article_id' => $kb,
        ]);
        $this->toast($p['code'] . ' updated');

        return redirect()->to('/app/problems/' . $id);
    }

    public function delete(int $id)
    {
        if (! $this->canManage()) {
            $this->toast('Only supervisors and administrators can delete problems', 'warn');

            return redirect()->to('/app/problems/' . $id);
        }
        $p = $this->db->table('problems')->where('id', $id)->get()->getRowArray();
        if ($p) {
            $this->db->table('tickets')->where('problem_id', $id)->update(['problem_id' => null]);
            $this->db->table('problems')->where('id', $id)->delete();
            \App\Libraries\Audit::log('problem.deleted', $p['code'] . ' — ' . $p['title']);
            $this->toast($p['code'] . ' deleted', 'bad');
        }

        return redirect()->to('/app/problems');
    }

    /**
     * Candidate incidents for a problem: scored on wording shared with the
     * problem (title, cause, workaround) and with incidents already linked to
     * it, so the pattern the problem describes pulls in its own cluster.
     */
    public function linkSearch(int $id)
    {
        $p = $this->db->table('problems')->where('id', $id)->get()->getRowArray();
        if (! $p) {
            return $this->response->setStatusCode(404, 'Not found');
        }

        $q = trim((string) $this->request->getGet('q'));
        $candidates = $this->scopeTickets(
            $this->db->table('tickets')->where('problem_id', null)->orderBy('updated_at', 'DESC')->get()->getResultArray()
        );
        $linked = $this->scopeTickets($this->db->table('tickets')->where('problem_id', $id)->get()->getResultArray());

        $ids = array_merge(array_column($candidates, 'id'), array_column($linked, 'id'));
        $descs = [];
        if ($ids) {
            foreach ($this->db->table('ticket_messages')->whereIn('ticket_id', $ids)->where('kind', 'description')->get()->getResultArray() as $m) {
                $descs[(int) $m['ticket_id']] = $m['body'];
            }
        }

        $words = static fn (string $s): array => th_keywords($s);

        $problemWords = $words($p['title'] . ' ' . $p['cause'] . ' ' . $p['workaround']);
        // Wording common to the incidents already linked is the strongest signal.
        $clusterWords = [];
        foreach ($linked as $l) {
            $clusterWords = array_merge($clusterWords, $words($l['subject'] . ' ' . ($descs[(int) $l['id']] ?? '')));
        }
        $clusterWords = array_values(array_unique($clusterWords));
        $linkedCats = array_values(array_unique(array_column($linked, 'category')));

        $needle = mb_strtolower($q);
        $scored = [];
        foreach ($candidates as $t) {
            if ($q !== '') {
                $hay = mb_strtolower($t['code'] . ' ' . $t['subject'] . ' ' . ($descs[(int) $t['id']] ?? '')
                    . ' ' . ($this->users()[(int) $t['requester_id']]['name'] ?? ''));
                if (! str_contains($hay, $needle)) {
                    continue;
                }
            }

            $tw = $words($t['subject'] . ' ' . ($descs[(int) $t['id']] ?? ''));
            $score = 0;
            $reasons = [];

            // A single shared word is weak evidence; two or more is a real signal.
            $pOverlap = array_values(array_intersect($problemWords, $tw));
            if ($pOverlap) {
                $score += min(45, 6 + (count($pOverlap) - 1) * 13);
                if (count($pOverlap) >= 2) {
                    $reasons[] = 'matches problem: ' . implode(', ', array_slice($pOverlap, 0, 3));
                }
            }
            $cOverlap = array_values(array_intersect($clusterWords, $tw));
            if ($cOverlap && $linked) {
                $score += min(30, (count($cOverlap) - 1) * 8);
                if (count($cOverlap) >= 2) {
                    $reasons[] = 'like linked incidents';
                }
            }
            if ($linkedCats && in_array($t['category'], $linkedCats, true)) {
                $score += 12;
                $reasons[] = 'same category as cluster';
            }
            if ($t['type'] === 'Incident') {
                $score += 6;
            }
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

        return view('agent/_problem_link_modal', [
            'p' => $p, 'q' => $q,
            'results' => array_slice($scored, 0, 12),
            'users' => $this->users(),
            'listOnly' => $this->request->getGet('list') === '1',
        ]);
    }

    public function link(int $id)
    {
        $p = $this->db->table('problems')->where('id', $id)->get()->getRowArray();
        if (! $p) {
            return redirect()->to('/app/problems');
        }

        // Accepts one code or many, so a whole cluster can be linked in one go.
        $codes = (array) ($this->request->getPost('codes') ?: array_filter([$this->request->getPost('code')]));
        $done  = 0;
        $denied = 0;
        foreach ($codes as $code) {
            $t = $this->ticketByCode((string) $code);
            if (! $t) {
                continue;
            }
            if (! $this->canSeeTicket($t)) {
                $denied++;

                continue;
            }
            $this->db->table('tickets')->where('id', $t['id'])->update(['problem_id' => $id, 'updated_at' => date('Y-m-d H:i:s')]);
            $this->addSystemNote((int) $t['id'], 'Linked to problem ' . $p['code']);
            $done++;
        }

        if ($done) {
            $this->toast($done . ' incident' . ($done === 1 ? '' : 's') . ' linked to ' . $p['code']
                . ($denied ? ' — ' . $denied . ' skipped (another team)' : ''));
        } else {
            $this->toast($denied ? 'Those tickets belong to another team' : 'Nothing selected', 'warn');
        }

        return redirect()->to('/app/problems/' . $id);
    }

    public function unlink(int $id, int $ticketId)
    {
        $t = $this->db->table('tickets')->where('id', $ticketId)->where('problem_id', $id)->get()->getRowArray();
        if ($t && ! $this->canSeeTicket($t)) {
            $this->toast('That ticket belongs to another team', 'warn');

            return redirect()->to('/app/problems/' . $id);
        }
        if ($t) {
            $this->db->table('tickets')->where('id', $ticketId)->update(['problem_id' => null, 'updated_at' => date('Y-m-d H:i:s')]);
            $this->addSystemNote($ticketId, 'Unlinked from problem');
            $this->toast($t['code'] . ' unlinked');
        }

        return redirect()->to('/app/problems/' . $id);
    }

    public function create()
    {
        $p = $this->request->getPost();
        if (empty($p['title'])) {
            $this->toast('Describe the pattern', 'warn');

            return redirect()->back();
        }
        $row = $this->db->query("SELECT MAX(CAST(SUBSTRING_INDEX(code,'-',-1) AS UNSIGNED)) AS n FROM problems")->getRowArray();
        $n = max(44, (int) ($row['n'] ?? 0)) + 1;
        $this->db->table('problems')->insert([
            'code' => 'PRB-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'title' => $p['title'], 'status' => 'Under investigation',
            'priority' => in_array($p['priority'] ?? '', ['Urgent', 'High', 'Medium', 'Low'], true) ? $p['priority'] : 'Medium',
            'owner_id' => $this->me['id'],
            'linked' => 0, 'opened_at' => date('Y-m-d H:i:s'),
            'cause' => '—', 'workaround' => ($p['workaround'] ?? '') ?: 'None yet',
        ]);
        $this->toast('Problem raised');

        return redirect()->to('/app/problems');
    }
}
