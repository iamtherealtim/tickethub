<?php

namespace App\Controllers\Api;

use App\Libraries\Audit;

/**
 * Change records. Agents read and raise; approving/rejecting mirrors
 * ChangesController::decide(): Administrator or Supervisor, only while the
 * change is Awaiting approval, and never on your own change.
 */
class ChangesApiController extends ApiController
{
    private const STATES = ['Awaiting approval', 'Scheduled', 'In progress', 'Completed', 'Rejected', 'Cancelled'];

    public function index()
    {
        if (! $this->isAgent()) {
            return $this->fail('Only agents can list changes', 403);
        }
        $b = $this->db->table('changes');
        if (($state = (string) $this->request->getGet('state')) !== '') {
            if (! in_array($state, self::STATES, true)) {
                return $this->fail('state must be one of ' . implode(', ', self::STATES), 422);
            }
            $b->where('state', $state);
        }
        if (($risk = (string) $this->request->getGet('risk')) !== '') {
            $b->where('risk', $risk);
        }
        if (($q = trim((string) $this->request->getGet('q'))) !== '') {
            $b->groupStart()->like('title', $q)->orLike('code', $q)->groupEnd();
        }

        return $this->paginate($b, [$this, 'shape'], 'window_at', 'DESC');
    }

    public function show(int $id)
    {
        if (! $this->isAgent()) {
            return $this->fail('Only agents can view changes', 403);
        }
        $c = $this->db->table('changes')->where('id', $id)->get()->getRowArray();
        if (! $c) {
            return $this->fail('Change not found', 404);
        }
        $linked = $this->scopeTickets($this->db->table('tickets')->where('change_id', $id)->orderBy('created_at', 'DESC')->get()->getResultArray());

        return $this->ok($this->shape($c) + [
            'plan' => $c['plan'], 'backout' => $c['backout'],
            'linked_tickets' => array_map(static fn ($t) => ['code' => $t['code'], 'subject' => $t['subject'], 'status' => $t['status']], $linked),
        ]);
    }

    public function create()
    {
        if (! $this->isAgent()) {
            return $this->fail('Only agents can raise changes', 403);
        }
        $in = $this->json();
        if ($in === null) {
            return $this->fail('Invalid JSON body', 400);
        }
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '') {
            return $this->fail('title is required', 422);
        }
        $window = null;
        if (! empty($in['window_at'])) {
            $ts = strtotime((string) $in['window_at']);
            if (! $ts) {
                return $this->fail('window_at is not a date', 422);
            }
            $window = date('Y-m-d H:i:s', $ts);
        }
        $row = $this->db->query("SELECT MAX(CAST(SUBSTRING_INDEX(code,'-',-1) AS UNSIGNED)) AS n FROM changes")->getRowArray();
        $n = max(311, (int) ($row['n'] ?? 0)) + 1;
        $this->db->table('changes')->insert([
            'code' => 'CHG-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'title' => mb_substr($title, 0, 255),
            'risk' => $this->pick($in['risk'] ?? null, ['Low', 'Medium', 'High'], 'Low'),
            'state' => 'Awaiting approval',
            'type' => $this->pick($in['type'] ?? null, ['Standard', 'Normal', 'Emergency'], 'Normal'),
            'owner_id' => (int) $this->me['id'],
            'window_at' => $window ?? date('Y-m-d H:i:s', time() + 72 * 3600),
            'duration' => mb_substr(trim((string) ($in['duration'] ?? '')), 0, 12) ?: '2h',
            'impact' => mb_substr(trim((string) ($in['impact'] ?? '')), 0, 255) ?: 'To be assessed',
            'plan' => (string) ($in['plan'] ?? ''), 'backout' => (string) ($in['backout'] ?? ''),
            'approvals' => json_encode([['by' => (int) $this->me['id'], 'status' => 'Pending', 'requested_at' => date('Y-m-d H:i:s')]]),
        ]);
        $id = (int) $this->db->insertID();
        Audit::log('api.change_created', 'CHG-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT) . ' — ' . $title);

        return $this->ok($this->shape($this->db->table('changes')->where('id', $id)->get()->getRowArray()), 201);
    }

    public function approve(int $id)
    {
        return $this->decide($id, 'Approved', 'Scheduled');
    }

    public function reject(int $id)
    {
        return $this->decide($id, 'Rejected', 'Rejected');
    }

    private function decide(int $id, string $approvalStatus, string $state)
    {
        if (! $this->canManage()) {
            return $this->fail('Only supervisors and administrators can approve or reject changes', 403);
        }
        $c = $this->db->table('changes')->where('id', $id)->get()->getRowArray();
        if (! $c) {
            return $this->fail('Change not found', 404);
        }
        if ($c['state'] !== 'Awaiting approval') {
            return $this->fail($c['code'] . ' is not awaiting approval', 422);
        }
        $me = (int) $this->me['id'];
        if ((int) $c['owner_id'] === $me) {
            return $this->fail('You cannot approve or reject your own change', 403);
        }

        $approvals = json_decode($c['approvals'] ?? '[]', true) ?: [];
        $now = date('Y-m-d H:i:s');
        $decided = false;
        foreach ($approvals as &$a) {
            if ((int) ($a['by'] ?? 0) === $me && ($a['status'] ?? '') === 'Pending') {
                $a['status'] = $approvalStatus;
                $a['decided_at'] = $now;
                $decided = true;
            }
        }
        unset($a);
        if (! $decided) {
            $owner = (int) $c['owner_id'];
            $approvals = array_values(array_filter(
                $approvals,
                static fn ($a) => ! (($a['status'] ?? '') === 'Pending' && (int) ($a['by'] ?? 0) === $owner)
            ));
            $approvals[] = ['by' => $me, 'status' => $approvalStatus, 'decided_at' => $now];
        }
        $this->db->table('changes')->where('id', $id)->update(['approvals' => json_encode($approvals), 'state' => $state]);
        Audit::log('change.' . strtolower($approvalStatus), $c['code'] . ' — ' . $c['title'] . ' (API)');

        return $this->ok($this->shape($this->db->table('changes')->where('id', $id)->get()->getRowArray()));
    }

    protected function shape(array $c): array
    {
        $approvals = json_decode($c['approvals'] ?? '[]', true) ?: [];

        return [
            'id' => (int) $c['id'], 'code' => $c['code'], 'title' => $c['title'], 'risk' => $c['risk'], 'state' => $c['state'],
            'type' => $c['type'], 'owner' => $this->userRef((int) $c['owner_id']),
            'window_at' => $c['window_at'], 'duration' => $c['duration'], 'impact' => $c['impact'],
            'approvals' => array_map(fn ($a) => [
                'by' => $this->userRef((int) ($a['by'] ?? 0)), 'status' => $a['status'] ?? null,
                'requested_at' => $a['requested_at'] ?? null, 'decided_at' => $a['decided_at'] ?? null,
            ], $approvals),
            'url' => site_url('app/changes/' . $c['id']),
        ];
    }
}
