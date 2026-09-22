<?php

namespace App\Controllers;

class AssetsController extends BaseController
{
    public function index()
    {
        $q    = mb_strtolower(trim((string) $this->request->getGet('q')));
        $type = (string) $this->request->getGet('type');

        $assets = $this->db->table('assets')->orderBy('id')->get()->getResultArray();
        $users  = $this->users();

        $list = array_values(array_filter($assets, static function ($a) use ($q, $type, $users) {
            if ($type && $a['type'] !== $type) {
                return false;
            }
            if ($q) {
                $holder = $a['user_id'] ? ($users[(int) $a['user_id']]['name'] ?? '') : '';
                $hay = mb_strtolower($a['tag'] . $a['name'] . $a['model'] . $a['serial'] . $holder);

                return str_contains($hay, $q);
            }

            return true;
        }));

        $types = array_values(array_unique(array_column($assets, 'type')));
        $expiring = count(array_filter($assets, static fn ($a) => $a['warranty_until'] && strtotime($a['warranty_until']) < time() + 90 * 86400));

        $perPage = 25;
        $total   = count($list);
        $page    = max(1, min((int) ($this->request->getGet('page') ?: 1), max(1, (int) ceil($total / $perPage))));
        $list    = array_slice($list, ($page - 1) * $perPage, $perPage);

        return view('agent/assets', $this->agentShared() + [
            'title' => 'Assets', 'nav' => 'assets',
            'assets' => $assets, 'list' => $list, 'types' => $types,
            'expiring' => $expiring, 'users' => $users,
            'q' => $this->request->getGet('q'), 'type' => $type,
            'page' => $page, 'perPage' => $perPage, 'total' => $total,
            'pdqConfigured' => \App\Libraries\PdqConnect::configured(),
            'pdqLastSync' => \App\Libraries\Settings::get('pdq_last_sync'),
            'assignablePeople' => $this->db->table('users')->where('active', 1)->orderBy('name')->get()->getResultArray(),
        ]);
    }

    public function create()
    {
        $p = $this->request->getPost();
        if (empty($p['name'])) {
            $this->toast('Name the asset', 'warn');

            return redirect()->back();
        }
        do {
            $tag = 'AST-' . random_int(1000, 9999);
        } while ($this->db->table('assets')->where('tag', $tag)->countAllResults());

        $this->db->table('assets')->insert([
            'tag' => $tag, 'name' => $p['name'], 'type' => $p['type'] ?? 'Laptop',
            'model' => $p['model'] ?: '—', 'serial' => $p['serial'] ?: '—',
            'user_id' => $p['user_id'] !== '' ? (int) $p['user_id'] : null,
            'site' => $p['site'] ?? '—', 'status' => $p['user_id'] !== '' ? 'In use' : 'In stock',
            'warranty_until' => date('Y-m-d H:i:s', time() + 365 * 86400), 'os' => '—',
        ]);
        $this->toast('Asset added');

        return redirect()->to('/app/assets');
    }

    /** The canonical asset record. The modal is a preview of this. */
    public function show(int $id)
    {
        $a = $this->db->table('assets')->where('id', $id)->get()->getRowArray();
        if (! $a) {
            $this->toast('Asset not found', 'warn');

            return redirect()->to('/app/assets');
        }

        return view('agent/asset', $this->agentShared() + [
            'title' => $a['tag'], 'nav' => 'assets',
            'a' => $a, 'related' => $this->relatedTickets($id), 'users' => $this->users(),
        ]);
    }

    /** Server-rendered modal fragment for the asset detail. */
    public function modal(int $id)
    {
        $a = $this->db->table('assets')->where('id', $id)->get()->getRowArray();
        if (! $a) {
            return $this->response->setStatusCode(404, 'Not found');
        }

        return view('agent/_asset_modal', [
            'a' => $a, 'related' => $this->relatedTickets($id), 'users' => $this->users(),
        ]);
    }

    /** Tickets linked to this asset, filtered to the agent's group scope. */
    private function relatedTickets(int $id): array
    {
        $ticketIds = array_column($this->db->table('ticket_assets')->where('asset_id', $id)->get()->getResultArray(), 'ticket_id');

        return $ticketIds ? $this->scopeTickets($this->db->table('tickets')->whereIn('id', $ticketIds)->get()->getResultArray()) : [];
    }

    /** Server-rendered edit-form modal fragment. */
    public function editModal(int $id)
    {
        $a = $this->db->table('assets')->where('id', $id)->get()->getRowArray();
        if (! $a) {
            return $this->response->setStatusCode(404, 'Not found');
        }

        // Everyone active, not just requesters — otherwise an agent-held asset
        // has no matching option and silently unassigns on save.
        return view('agent/_asset_edit', [
            'a' => $a,
            'people' => $this->db->table('users')->where('active', 1)->orderBy('name')->get()->getResultArray(),
        ]);
    }

    public function update(int $id)
    {
        $a = $this->db->table('assets')->where('id', $id)->get()->getRowArray();
        $p = $this->request->getPost();
        if (! $a || empty($p['name'])) {
            $this->toast('Asset not found or name missing', 'warn');

            return redirect()->to('/app/assets');
        }
        $warranty = $a['warranty_until'];
        if (! empty($p['warranty_until'])) {
            $ts = strtotime($p['warranty_until']);
            if ($ts) {
                $warranty = date('Y-m-d H:i:s', $ts);
            }
        }
        $this->db->table('assets')->where('id', $id)->update([
            'name' => $p['name'], 'type' => $p['type'] ?? $a['type'],
            'model' => $p['model'] ?: '—', 'serial' => $p['serial'] ?: '—',
            'user_id' => $p['user_id'] !== '' ? (int) $p['user_id'] : null,
            'site' => $p['site'] ?? $a['site'],
            'status' => in_array($p['status'], array_keys(TH_ASSET_STATUS), true) ? $p['status'] : $a['status'],
            'os' => $p['os'] ?: '—',
            'warranty_until' => $warranty,
        ]);
        $this->toast($a['tag'] . ' updated');

        // Edit is reachable from the list, a ticket sidebar and the asset page,
        // so return to whichever it was — same reasoning as assign().
        return redirect()->back();
    }

    public function delete(int $id)
    {
        $a = $this->db->table('assets')->where('id', $id)->get()->getRowArray();
        if ($a) {
            $this->db->table('ticket_assets')->where('asset_id', $id)->delete();
            $this->db->table('assets')->where('id', $id)->delete();
            \App\Libraries\Audit::log('asset.deleted', $a['tag'] . ' — ' . $a['name']);
            $this->toast($a['tag'] . ' deleted', 'bad');
        }

        return redirect()->to('/app/assets');
    }

    /**
     * People this asset can be handed to. Everyone active is eligible — agents
     * hold laptops too — ranked so the likely candidates surface first.
     */
    public function assignSearch(int $id)
    {
        $a = $this->db->table('assets')->where('id', $id)->get()->getRowArray();
        if (! $a) {
            return $this->response->setStatusCode(404, 'Not found');
        }

        $q = mb_strtolower(trim((string) $this->request->getGet('q')));
        $people = $this->db->table('users')->where('active', 1)->orderBy('name')->get()->getResultArray();

        // How much kit each person already holds, for context in the list.
        $held = [];
        foreach ($this->db->query('SELECT user_id, COUNT(*) n FROM assets WHERE user_id IS NOT NULL GROUP BY user_id')->getResultArray() as $r) {
            $held[(int) $r['user_id']] = (int) $r['n'];
        }

        $scored = [];
        foreach ($people as $u) {
            if ($q !== '') {
                $hay = mb_strtolower($u['name'] . ' ' . $u['email'] . ' ' . ($u['dept'] ?? '') . ' ' . ($u['site'] ?? '') . ' ' . ($u['title'] ?? ''));
                if (! str_contains($hay, $q)) {
                    continue;
                }
            }

            $score = 0;
            $why = [];
            if ((int) ($a['user_id'] ?? 0) === (int) $u['id']) {
                $score += 100;
                $why[] = 'current holder';
            }
            if (! empty($a['site']) && $a['site'] !== '—' && $a['site'] === ($u['site'] ?? '')) {
                $score += 30;
                $why[] = 'same site';
            }
            if (($held[(int) $u['id']] ?? 0) === 0) {
                $score += 5;
                $why[] = 'holds nothing yet';
            }

            $scored[] = ['u' => $u, 'score' => $score, 'why' => $why, 'held' => $held[(int) $u['id']] ?? 0];
        }

        usort($scored, static fn ($x, $y) => $y['score'] <=> $x['score'] ?: strcmp($x['u']['name'], $y['u']['name']));

        return view('agent/_asset_assign_modal', [
            'a' => $a, 'q' => (string) $this->request->getGet('q'),
            'results' => array_slice($scored, 0, 25),
            'listOnly' => $this->request->getGet('list') === '1',
        ]);
    }

    public function assign(int $id)
    {
        $a = $this->db->table('assets')->where('id', $id)->get()->getRowArray();
        if (! $a) {
            return redirect()->to('/app/assets');
        }
        $userId = $this->request->getPost('user_id');
        $userId = ($userId === '' || $userId === null) ? null : (int) $userId;

        $user = null;
        if ($userId !== null) {
            $user = $this->db->table('users')->where('id', $userId)->where('active', 1)->get()->getRowArray();
            if (! $user) {
                $this->toast('That person is not available', 'warn');

                return redirect()->back();
            }
        }

        $upd = ['user_id' => $userId];
        // Keep status honest without overriding a deliberate choice like "Needs repair".
        if ($userId !== null && $a['status'] === 'In stock') {
            $upd['status'] = 'In use';
        } elseif ($userId === null && $a['status'] === 'In use') {
            $upd['status'] = 'In stock';
        }
        $this->db->table('assets')->where('id', $id)->update($upd);

        $this->toast($userId === null
            ? $a['tag'] . ' returned to stock'
            : $a['tag'] . ' assigned to ' . $user['name']
              . (isset($upd['status']) ? ' — status set to ' . $upd['status'] : ''));

        return redirect()->back();
    }

    /** Raise a ticket pre-linked to this asset. */
    public function raiseTicket(int $id)
    {
        $a = $this->db->table('assets')->where('id', $id)->get()->getRowArray();
        if (! $a) {
            return redirect()->to('/app/assets');
        }
        $t = $this->createTicket([
            'subject' => 'Issue with ' . $a['name'],
            'body' => 'Raised from asset record ' . $a['tag'] . ' (' . $a['model'] . ', serial ' . $a['serial'] . ').',
            // The holder is the requester; for unassigned kit the agent raising it is.
            'requester_id' => $a['user_id'] ?: (int) $this->me['id'],
            'type' => 'Incident', 'category' => 'Hardware', 'priority' => 'Medium', 'source' => 'Walk-up',
            // group_id omitted on purpose so the routing rules decide.
        ]);
        $this->db->table('ticket_assets')->insert(['ticket_id' => $t['id'], 'asset_id' => $id]);
        $this->toast($t['code'] . ' created');

        return redirect()->to('/app/tickets/' . $t['code']);
    }
}
