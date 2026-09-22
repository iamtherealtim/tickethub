<?php

namespace App\Controllers;

class ChangesController extends BaseController
{
    /** Approving, advancing, editing and deleting changes is a supervisory action. */
    private function canManage(): bool
    {
        return in_array($this->me['role'] ?? '', ['Administrator', 'Supervisor'], true);
    }

    private function denyManage()
    {
        $this->toast('Only supervisors and administrators can manage changes', 'warn');

        return redirect()->to('/app/changes');
    }

    public function index()
    {
        return view('agent/changes', $this->agentShared() + [
            'title' => 'Changes', 'nav' => 'changes',
            'changes' => $this->db->table('changes')->orderBy('window_at', 'DESC')->get()->getResultArray(),
            'users' => $this->users(),
        ]);
    }

    /** The canonical change record — plan, backout and approvals in full. */
    public function show(int $id)
    {
        $c = $this->db->table('changes')->where('id', $id)->get()->getRowArray();
        if (! $c) {
            $this->toast('Change not found', 'warn');

            return redirect()->to('/app/changes');
        }

        return view('agent/change', $this->agentShared() + [
            'title' => $c['code'], 'nav' => 'changes',
            'c' => $c, 'users' => $this->users(),
            'canManage' => $this->canManage(),
        ]);
    }

    public function create()
    {
        $p = $this->request->getPost();
        if (empty($p['title'])) {
            $this->toast('Give the change a title', 'warn');

            return redirect()->back();
        }
        $row = $this->db->query("SELECT MAX(CAST(SUBSTRING_INDEX(code,'-',-1) AS UNSIGNED)) AS n FROM changes")->getRowArray();
        $n = max(311, (int) ($row['n'] ?? 0)) + 1;
        $this->db->table('changes')->insert([
            'code' => 'CHG-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'title' => $p['title'], 'risk' => $p['risk'] ?? 'Low', 'state' => 'Awaiting approval',
            'type' => $p['type'] ?? 'Normal', 'owner_id' => $this->me['id'],
            'window_at' => date('Y-m-d H:i:s', time() + 72 * 3600), 'duration' => $p['duration'] ?: '2h',
            'impact' => $p['impact'] ?: 'To be assessed',
            'plan' => $p['plan'] ?? '', 'backout' => $p['backout'] ?? '',
            'approvals' => json_encode([['by' => 1, 'status' => 'Pending']]),
        ]);
        $this->toast('Change submitted for approval');

        return redirect()->to('/app/changes');
    }

    public function approve(int $id)
    {
        return $this->decide($id, 'Approved', 'Scheduled');
    }

    public function reject(int $id)
    {
        return $this->decide($id, 'Rejected', 'Rejected');
    }

    /** Scheduled → In progress → Completed. */
    public function state(int $id)
    {
        if (! $this->canManage()) {
            return $this->denyManage();
        }
        $c = $this->db->table('changes')->where('id', $id)->get()->getRowArray();
        $to = (string) $this->request->getPost('to');
        $allowed = ['Scheduled' => ['In progress'], 'In progress' => ['Completed']];
        if ($c && in_array($to, $allowed[$c['state']] ?? [], true)) {
            $this->db->table('changes')->where('id', $id)->update(['state' => $to]);
            $this->toast($c['code'] . ' → ' . $to);
        } else {
            $this->toast('That state change is not allowed', 'warn');
        }

        // Reachable from the list and from the record page — go back to whichever.
        return redirect()->back();
    }

    public function update(int $id)
    {
        if (! $this->canManage()) {
            return $this->denyManage();
        }
        $c = $this->db->table('changes')->where('id', $id)->get()->getRowArray();
        $p = $this->request->getPost();
        if (! $c || empty($p['title'])) {
            $this->toast('The change needs a title', 'warn');

            // Reachable from the list and from the record page — go back to whichever.
        return redirect()->back();
        }
        $window = $c['window_at'];
        if (! empty($p['window_at'])) {
            $ts = strtotime($p['window_at']);
            if ($ts) {
                $window = date('Y-m-d H:i:s', $ts);
            }
        }
        $this->db->table('changes')->where('id', $id)->update([
            'title' => $p['title'],
            'risk' => in_array($p['risk'], ['Low', 'Medium', 'High'], true) ? $p['risk'] : $c['risk'],
            'type' => $p['type'] ?: $c['type'],
            'window_at' => $window, 'duration' => $p['duration'] ?: $c['duration'],
            'impact' => $p['impact'] ?: $c['impact'],
            'plan' => $p['plan'] ?? $c['plan'], 'backout' => $p['backout'] ?? $c['backout'],
        ]);
        $this->toast($c['code'] . ' updated');

        // Reachable from the list and from the record page — go back to whichever.
        return redirect()->back();
    }

    public function delete(int $id)
    {
        if (! $this->canManage()) {
            return $this->denyManage();
        }
        $c = $this->db->table('changes')->where('id', $id)->get()->getRowArray();
        if ($c) {
            $this->db->table('changes')->where('id', $id)->delete();
            \App\Libraries\Audit::log('change.deleted', $c['code'] . ' — ' . $c['title']);
            $this->toast($c['code'] . ' deleted', 'bad');
        }

        return redirect()->to('/app/changes');
    }

    private function decide(int $id, string $approvalStatus, string $state)
    {
        if (! $this->canManage()) {
            return $this->denyManage();
        }
        $c = $this->db->table('changes')->where('id', $id)->get()->getRowArray();
        if ($c) {
            $approvals = json_decode($c['approvals'] ?? '[]', true) ?: [];
            foreach ($approvals as &$a) {
                if ($a['status'] === 'Pending') {
                    $a['status'] = $approvalStatus;
                }
            }
            $this->db->table('changes')->where('id', $id)->update(['approvals' => json_encode($approvals), 'state' => $state]);
            $this->toast($c['code'] . ($state === 'Scheduled' ? ' approved and scheduled' : ' rejected'), $state === 'Rejected' ? 'bad' : 'ok');
        }

        // Reachable from the list and from the record page — go back to whichever.
        return redirect()->back();
    }
}
