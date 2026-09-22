<?php

namespace App\Controllers;

class ChangesController extends BaseController
{
    /** State transitions an operator may drive by hand. */
    private const TRANSITIONS = [
        'Awaiting approval' => ['Cancelled'],
        'Scheduled'         => ['In progress', 'Cancelled'],
        'In progress'       => ['Completed', 'Cancelled'],
        'Rejected'          => ['Awaiting approval', 'Cancelled'],
        'Completed'         => [],
        'Cancelled'         => [],
    ];

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
            'canManage' => $this->canManage(),
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
        // Ticket subjects are group-scoped everywhere else, so they are here too.
        $linked = $this->scopeTickets(
            $this->db->table('tickets')->where('change_id', $id)->orderBy('created_at', 'DESC')->get()->getResultArray()
        );

        return view('agent/change', $this->agentShared() + [
            'title' => $c['code'], 'nav' => 'changes',
            'c' => $c, 'users' => $this->users(), 'linked' => $linked,
            'canManage' => $this->canManage(),
            'transitions' => self::TRANSITIONS[$c['state']] ?? [],
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
            'title' => $p['title'],
            'risk' => in_array($p['risk'] ?? '', ['Low', 'Medium', 'High'], true) ? $p['risk'] : 'Low',
            'state' => 'Awaiting approval',
            'type' => in_array($p['type'] ?? '', ['Standard', 'Normal', 'Emergency'], true) ? $p['type'] : 'Normal',
            'owner_id' => $this->me['id'],
            'window_at' => date('Y-m-d H:i:s', time() + 72 * 3600), 'duration' => ($p['duration'] ?? '') ?: '2h',
            'impact' => ($p['impact'] ?? '') ?: 'To be assessed',
            'plan' => $p['plan'] ?? '', 'backout' => $p['backout'] ?? '',
            // The creator is recorded as the person awaiting sign-off, so the
            // approvals card is never empty; a supervisor's decision replaces it.
            'approvals' => json_encode([['by' => (int) $this->me['id'], 'status' => 'Pending', 'requested_at' => date('Y-m-d H:i:s')]]),
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

    /** Scheduled → In progress → Completed, plus resubmit and cancel. */
    public function state(int $id)
    {
        if (! $this->canManage()) {
            return $this->denyManage();
        }
        $c = $this->db->table('changes')->where('id', $id)->get()->getRowArray();
        $to = (string) $this->request->getPost('to');
        if ($c && in_array($to, self::TRANSITIONS[$c['state']] ?? [], true)) {
            $upd = ['state' => $to];
            if ($to === 'Awaiting approval') {
                // Resubmitting starts a fresh approval round; the old decision is history.
                $upd['approvals'] = json_encode([['by' => (int) $this->me['id'], 'status' => 'Pending', 'requested_at' => date('Y-m-d H:i:s')]]);
            }
            $this->db->table('changes')->where('id', $id)->update($upd);
            $this->toast($c['code'] . ' → ' . $to, $to === 'Cancelled' ? 'warn' : 'ok');
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
        // Every field falls back to the stored row: the two edit forms (list and
        // record page) do not have to carry identical inputs to be safe.
        $this->db->table('changes')->where('id', $id)->update([
            'title' => $p['title'],
            'risk' => in_array($p['risk'] ?? '', ['Low', 'Medium', 'High'], true) ? $p['risk'] : $c['risk'],
            'type' => in_array($p['type'] ?? '', ['Standard', 'Normal', 'Emergency'], true) ? $p['type'] : $c['type'],
            'window_at' => $window, 'duration' => ($p['duration'] ?? '') ?: $c['duration'],
            'impact' => ($p['impact'] ?? '') ?: $c['impact'],
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
            $this->db->table('tickets')->where('change_id', $id)->update(['change_id' => null]);
            $this->db->table('changes')->where('id', $id)->delete();
            \App\Libraries\Audit::log('change.deleted', $c['code'] . ' — ' . $c['title']);
            $this->toast($c['code'] . ' deleted', 'bad');
        }

        return redirect()->to('/app/changes');
    }

    /** Tie a ticket to this change by code. */
    public function link(int $id)
    {
        $c = $this->db->table('changes')->where('id', $id)->get()->getRowArray();
        if (! $c) {
            return redirect()->to('/app/changes');
        }
        $code = strtoupper(trim((string) $this->request->getPost('code')));
        $t = $code !== '' ? $this->ticketByCode($code) : null;
        if (! $t) {
            $this->toast('No ticket called ' . ($code ?: '—'), 'warn');
        } elseif (! $this->canSeeTicket($t)) {
            $this->toast('That ticket belongs to another team', 'warn');
        } elseif ((int) $t['change_id'] === $id) {
            $this->toast($t['code'] . ' is already linked', 'warn');
        } else {
            $this->db->table('tickets')->where('id', $t['id'])->update(['change_id' => $id, 'updated_at' => date('Y-m-d H:i:s')]);
            $this->addSystemNote((int) $t['id'], 'Linked to change ' . $c['code']);
            $this->toast($t['code'] . ' linked to ' . $c['code']);
        }

        return redirect()->to('/app/changes/' . $id);
    }

    public function unlink(int $id, int $ticketId)
    {
        $t = $this->db->table('tickets')->where('id', $ticketId)->where('change_id', $id)->get()->getRowArray();
        if ($t && ! $this->canSeeTicket($t)) {
            $this->toast('That ticket belongs to another team', 'warn');
        } elseif ($t) {
            $this->db->table('tickets')->where('id', $ticketId)->update(['change_id' => null, 'updated_at' => date('Y-m-d H:i:s')]);
            $this->addSystemNote($ticketId, 'Unlinked from change');
            $this->toast($t['code'] . ' unlinked');
        }

        return redirect()->to('/app/changes/' . $id);
    }

    private function decide(int $id, string $approvalStatus, string $state)
    {
        if (! $this->canManage()) {
            return $this->denyManage();
        }
        $c = $this->db->table('changes')->where('id', $id)->get()->getRowArray();
        if (! $c) {
            return redirect()->back();
        }
        if ($c['state'] !== 'Awaiting approval') {
            $this->toast($c['code'] . ' is not awaiting approval', 'warn');

            return redirect()->back();
        }
        $me = (int) $this->me['id'];
        if ((int) $c['owner_id'] === $me) {
            $this->toast('You cannot approve or reject your own change — ask another supervisor', 'bad');

            return redirect()->back();
        }

        $approvals = json_decode($c['approvals'] ?? '[]', true) ?: [];
        $now = date('Y-m-d H:i:s');
        $decided = false;
        foreach ($approvals as &$a) {
            // Only the acting user's own pending entry flips; nobody signs for anyone else.
            if ((int) ($a['by'] ?? 0) === $me && ($a['status'] ?? '') === 'Pending') {
                $a['status'] = $approvalStatus;
                $a['decided_at'] = $now;
                $decided = true;
            }
        }
        unset($a);
        if (! $decided) {
            // Drop only the submitter's own placeholder; anyone else still listed
            // as Pending was asked and stays on the record. The decision maker is
            // appended as an ad-hoc approver so the card shows who actually signed.
            $owner = (int) $c['owner_id'];
            $approvals = array_values(array_filter(
                $approvals,
                static fn ($a) => ! (($a['status'] ?? '') === 'Pending' && (int) ($a['by'] ?? 0) === $owner)
            ));
            $approvals[] = ['by' => $me, 'status' => $approvalStatus, 'decided_at' => $now];
        }
        $this->db->table('changes')->where('id', $id)->update(['approvals' => json_encode($approvals), 'state' => $state]);
        \App\Libraries\Audit::log('change.' . strtolower($approvalStatus), $c['code'] . ' — ' . $c['title']);
        $this->toast($c['code'] . ($state === 'Scheduled' ? ' approved and scheduled' : ' rejected'), $state === 'Rejected' ? 'bad' : 'ok');

        // Reachable from the list and from the record page — go back to whichever.
        return redirect()->back();
    }
}
