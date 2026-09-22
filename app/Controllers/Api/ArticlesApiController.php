<?php

namespace App\Controllers\Api;

use App\Libraries\Audit;

/** Knowledge base. Non-agent tokens only ever see published articles; agents write. */
class ArticlesApiController extends ApiController
{
    public function index()
    {
        $b = $this->db->table('articles');
        $status = (string) $this->request->getGet('status');
        if (! $this->isAgent()) {
            $b->where('status', 'Published');
        } elseif (in_array($status, ['Draft', 'Published'], true)) {
            $b->where('status', $status);
        }
        $q = trim((string) $this->request->getGet('q'));
        if ($q !== '') {
            $b->groupStart()->like('title', $q)->orLike('tags', $q)->orLike('body', $q)->groupEnd();
        }
        if (($cat = trim((string) $this->request->getGet('category'))) !== '') {
            $b->where('category', $cat);
        }

        return $this->paginate($b, fn ($a) => $this->shape($a, false), 'updated_at', 'DESC');
    }

    public function show(int $id)
    {
        $a = $this->db->table('articles')->where('id', $id)->get()->getRowArray();
        if (! $a || (! $this->isAgent() && $a['status'] !== 'Published')) {
            return $this->fail('Article not found', 404);
        }

        return $this->ok($this->shape($a, true));
    }

    public function create()
    {
        if (! $this->isAgent()) {
            return $this->fail('Only agents can write articles', 403);
        }
        $in = $this->json();
        if ($in === null) {
            return $this->fail('Invalid JSON body', 400);
        }
        $title = trim((string) ($in['title'] ?? ''));
        $body  = trim((string) ($in['body'] ?? ''));
        if ($title === '' || $body === '') {
            return $this->fail('title and body are required', 422);
        }
        $now = date('Y-m-d H:i:s');
        $this->db->table('articles')->insert([
            'title' => mb_substr($title, 0, 255), 'category' => mb_substr(trim((string) ($in['category'] ?? '')), 0, 60) ?: 'Accounts & access',
            'status' => ($in['status'] ?? '') === 'Published' ? 'Published' : 'Draft',
            'author_id' => (int) $this->me['id'], 'body' => $this->html($body),
            'tags' => json_encode($this->tags($in['tags'] ?? null)),
            'views' => 0, 'up_votes' => 0, 'down_votes' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) $this->db->insertID();
        Audit::log('api.article_created', $title);

        return $this->ok($this->shape($this->db->table('articles')->where('id', $id)->get()->getRowArray(), true), 201);
    }

    public function update(int $id)
    {
        if (! $this->isAgent()) {
            return $this->fail('Only agents can edit articles', 403);
        }
        $a = $this->db->table('articles')->where('id', $id)->get()->getRowArray();
        if (! $a) {
            return $this->fail('Article not found', 404);
        }
        $in = $this->json();
        if ($in === null) {
            return $this->fail('Invalid JSON body', 400);
        }
        $upd = [];
        if (array_key_exists('title', $in)) {
            $t = trim((string) $in['title']);
            if ($t === '') {
                return $this->fail('title cannot be empty', 422);
            }
            $upd['title'] = mb_substr($t, 0, 255);
        }
        if (array_key_exists('body', $in)) {
            $b = trim((string) $in['body']);
            if ($b === '') {
                return $this->fail('body cannot be empty', 422);
            }
            $upd['body'] = $this->html($b);
        }
        if (array_key_exists('category', $in) && trim((string) $in['category']) !== '') {
            $upd['category'] = mb_substr(trim((string) $in['category']), 0, 60);
        }
        if (array_key_exists('status', $in)) {
            if (! in_array($in['status'], ['Draft', 'Published'], true)) {
                return $this->fail('status must be Draft or Published', 422);
            }
            $upd['status'] = $in['status'];
        }
        if (array_key_exists('tags', $in)) {
            $upd['tags'] = json_encode($this->tags($in['tags']));
        }
        if (! $upd) {
            return $this->fail('Nothing to change', 422);
        }
        $upd['updated_at'] = date('Y-m-d H:i:s');
        $this->db->table('articles')->where('id', $id)->update($upd);
        Audit::log('api.article_updated', $a['title']);

        return $this->ok($this->shape($this->db->table('articles')->where('id', $id)->get()->getRowArray(), true));
    }

    /** Same treatment as the UI: HTML is sanitised, plain text becomes paragraphs. */
    private function html(string $body): string
    {
        return preg_match('/<(p|h2|ul|ol|div)\b/i', $body)
            ? th_sanitize_html($body)
            : '<p>' . str_replace("\n", '</p><p>', esc($body)) . '</p>';
    }

    private function tags($raw): array
    {
        $list = is_array($raw) ? $raw : preg_split('/[,\n]+/', (string) $raw);
        $tags = [];
        foreach ($list ?: [] as $t) {
            $t = mb_strtolower(trim((string) $t));
            if ($t !== '' && mb_strlen($t) <= 30 && ! in_array($t, $tags, true)) {
                $tags[] = $t;
            }
        }

        return array_slice($tags, 0, 12);
    }

    protected function shape(array $a, bool $withBody): array
    {
        $out = [
            'id' => (int) $a['id'], 'title' => $a['title'], 'category' => $a['category'], 'status' => $a['status'],
            'author' => $this->userRef((int) $a['author_id']),
            'tags' => json_decode($a['tags'] ?? '[]', true) ?: [],
            'views' => (int) $a['views'], 'up_votes' => (int) $a['up_votes'], 'down_votes' => (int) $a['down_votes'],
            'created_at' => $a['created_at'], 'updated_at' => $a['updated_at'],
            'url' => site_url(($this->isAgent() ? 'app' : 'portal') . '/kb/' . $a['id']),
        ];
        if ($withBody) {
            $out['body'] = $a['body'];
        }

        return $out;
    }
}
