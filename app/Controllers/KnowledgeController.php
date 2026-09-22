<?php

namespace App\Controllers;

class KnowledgeController extends BaseController
{
    public function index()
    {
        return view('agent/kb', $this->agentShared() + [
            'title' => 'Knowledge', 'nav' => 'kb',
            'articles' => $this->db->table('articles')->orderBy('updated_at', 'DESC')->get()->getResultArray(),
            'cat' => (string) ($this->request->getGet('cat') ?: 'All'),
            'users' => $this->users(),
        ]);
    }

    public function article(int $id)
    {
        $a = $this->db->table('articles')->where('id', $id)->get()->getRowArray();
        if (! $a) {
            $this->toast('Article not found — it may have been unpublished', 'warn');

            return redirect()->to('/app/kb');
        }
        $this->db->table('articles')->where('id', $id)->update(['views' => (int) $a['views'] + 1]);
        $related = $this->db->table('articles')->where('category', $a['category'])->where('id !=', $id)->limit(3)->get()->getResultArray();

        return view('agent/article', $this->agentShared() + [
            'title' => $a['title'], 'nav' => 'kb',
            'a' => $a, 'related' => $related, 'users' => $this->users(),
        ]);
    }

    public function vote(int $id)
    {
        $this->toast($this->recordVote($id, (string) $this->request->getPost('vote')));

        return redirect()->back();
    }

    public function update(int $id)
    {
        $a = $this->db->table('articles')->where('id', $id)->get()->getRowArray();
        $p = $this->request->getPost();
        if (! $a || empty($p['title']) || empty($p['body'])) {
            $this->toast('Title and body are needed', 'warn');

            return redirect()->back();
        }
        $this->db->table('articles')->where('id', $id)->update([
            'title' => $p['title'], 'category' => $p['category'] ?: $a['category'],
            'status' => $p['status'] === 'Published' ? 'Published' : 'Draft',
            'body' => th_sanitize_html($p['body']),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->toast('Article saved');

        return redirect()->to('/app/kb/' . $id);
    }

    public function delete(int $id)
    {
        // Catalog items and articles are shared service-desk content: one agent
        // deleting them affects every requester. Gate it the way changes and
        // announcements already are.
        if (! in_array($this->me['role'] ?? '', ['Administrator', 'Supervisor'], true)) {
            $this->toast('Only supervisors and administrators can delete this', 'warn');

            return redirect()->to('/app/kb');
        }
        $a = $this->db->table('articles')->where('id', $id)->get()->getRowArray();
        if ($a) {
            $this->db->table('articles')->where('id', $id)->delete();
            \App\Libraries\Audit::log('article.deleted', $a['title']);
            $this->toast('Article deleted', 'bad');
        }

        return redirect()->to('/app/kb');
    }

    /** Articles that might answer a request before it becomes a ticket. */
    public function suggest()
    {
        return $this->kbSuggestions((string) $this->request->getGet('q'), 'app/kb/');
    }

    public function create()
    {
        $p = $this->request->getPost();
        if (empty($p['title']) || empty($p['body'])) {
            $this->toast('Give it a title and a body', 'warn');

            return redirect()->back();
        }
        $now = date('Y-m-d H:i:s');
        // Plain text typed into the create form is wrapped into paragraphs; anything
        // that already looks like HTML is kept (and sanitised) so both forms agree.
        $body = trim($p['body']);
        $paras = preg_match('/<(p|h2|ul|ol|div)\b/i', $body)
            ? th_sanitize_html($body)
            : '<p>' . str_replace("\n", '</p><p>', esc($body)) . '</p>';
        $this->db->table('articles')->insert([
            'title' => $p['title'], 'category' => $p['category'] ?? 'Accounts & access',
            'status' => $p['status'] === 'Published' ? 'Published' : 'Draft',
            'author_id' => $this->me['id'], 'body' => $paras, 'tags' => '[]',
            'views' => 0, 'up_votes' => 0, 'down_votes' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->toast('Article saved');

        return redirect()->to('/app/kb');
    }
}
