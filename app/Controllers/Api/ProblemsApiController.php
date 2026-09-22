<?php

namespace App\Controllers\Api;

use App\Controllers\ProblemsController;
use App\Libraries\Audit;

/** Problem records. Agents only, mirroring ProblemsController. */
class ProblemsApiController extends ApiController
{
    public function index()
    {
        if (! $this->isAgent()) {
            return $this->fail('Only agents can list problems', 403);
        }
        $b = $this->db->table('problems');
        if (($status = (string) $this->request->getGet('status')) !== '') {
            if (! in_array($status, ProblemsController::STATUSES, true)) {
                return $this->fail('status must be one of ' . implode(', ', ProblemsController::STATUSES), 422);
            }
            $b->where('status', $status);
        }
        if (($q = trim((string) $this->request->getGet('q'))) !== '') {
            $b->groupStart()->like('title', $q)->orLike('code', $q)->groupEnd();
        }

        return $this->paginate($b, [$this, 'shape'], 'opened_at', 'DESC');
    }

    public function show(int $id)
    {
        if (! $this->isAgent()) {
            return $this->fail('Only agents can view problems', 403);
        }
        $p = $this->db->table('problems')->where('id', $id)->get()->getRowArray();
        if (! $p) {
            return $this->fail('Problem not found', 404);
        }
        $linked = $this->scopeTickets($this->db->table('tickets')->where('problem_id', $id)->orderBy('created_at', 'DESC')->get()->getResultArray());

        return $this->ok($this->shape($p) + [
            'cause' => $p['cause'], 'workaround' => $p['workaround'],
            'kb_article_id' => ! empty($p['kb_article_id']) ? (int) $p['kb_article_id'] : null,
            'linked_tickets' => array_map(static fn ($t) => ['code' => $t['code'], 'subject' => $t['subject'], 'status' => $t['status']], $linked),
        ]);
    }

    public function create()
    {
        if (! $this->isAgent()) {
            return $this->fail('Only agents can raise problems', 403);
        }
        $in = $this->json();
        if ($in === null) {
            return $this->fail('Invalid JSON body', 400);
        }
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '') {
            return $this->fail('title is required', 422);
        }
        $row = $this->db->query("SELECT MAX(CAST(SUBSTRING_INDEX(code,'-',-1) AS UNSIGNED)) AS n FROM problems")->getRowArray();
        $n = max(44, (int) ($row['n'] ?? 0)) + 1;
        $code = 'PRB-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
        $this->db->table('problems')->insert([
            'code' => $code, 'title' => mb_substr($title, 0, 255), 'status' => 'Under investigation',
            'priority' => $this->pick($in['priority'] ?? null, ['Urgent', 'High', 'Medium', 'Low'], 'Medium'),
            'owner_id' => (int) $this->me['id'], 'linked' => 0, 'opened_at' => date('Y-m-d H:i:s'),
            'cause' => trim((string) ($in['cause'] ?? '')) ?: '—',
            'workaround' => trim((string) ($in['workaround'] ?? '')) ?: 'None yet',
        ]);
        $id = (int) $this->db->insertID();
        Audit::log('api.problem_created', $code . ' — ' . $title);

        return $this->ok($this->shape($this->db->table('problems')->where('id', $id)->get()->getRowArray()), 201);
    }

    protected function shape(array $p): array
    {
        return [
            'id' => (int) $p['id'], 'code' => $p['code'], 'title' => $p['title'], 'status' => $p['status'], 'priority' => $p['priority'],
            'owner' => $this->userRef((int) $p['owner_id']), 'opened_at' => $p['opened_at'],
            'linked_count' => (int) $this->db->table('tickets')->where('problem_id', (int) $p['id'])->countAllResults(),
            'url' => site_url('app/problems/' . $p['id']),
        ];
    }
}
