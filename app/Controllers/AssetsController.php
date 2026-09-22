<?php

namespace App\Controllers;

class AssetsController extends BaseController
{
    /** Deleting kit and bulk-loading the register are supervisory actions. */
    private function canManage(): bool
    {
        return in_array($this->me['role'] ?? '', ['Administrator', 'Supervisor'], true);
    }

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
            'pdqLastError' => \App\Libraries\Settings::get('pdq_last_error'),
            'assignablePeople' => $this->db->table('users')->where('active', 1)->orderBy('name')->get()->getResultArray(),
            'canManage' => $this->canManage(),
            'importOpen' => $this->request->getUri()->getSegment(3) === 'import',
        ]);
    }

    /** GET assets/import — the list with the import dialog open. */
    public function importForm()
    {
        if (! $this->canManage()) {
            return $this->denyManage();
        }

        return $this->index();
    }

    private function denyManage()
    {
        $this->toast('Only supervisors and administrators can do that', 'warn');

        return redirect()->to('/app/assets');
    }

    /**
     * POST assets/import — CSV with headers name,type,model,serial,status,site,holder_email.
     * Rows are matched on a real serial first, then on name; matches are updated,
     * the rest created. Nothing is deleted.
     */
    public function import()
    {
        if (! $this->canManage()) {
            return $this->denyManage();
        }
        $file = $this->request->getFile('csv');
        if (! $file || ! $file->isValid()) {
            $this->toast('Choose a CSV file to import', 'warn');

            return redirect()->to('/app/assets/import');
        }
        if ($file->getSize() > 5 * 1024 * 1024) {
            $this->toast('That file is larger than 5 MB — split it up', 'warn');

            return redirect()->to('/app/assets/import');
        }
        $fh = fopen($file->getTempName(), 'r');
        if (! $fh) {
            $this->toast('Could not read the file', 'bad');

            return redirect()->to('/app/assets/import');
        }
        $r = self::importCsv($fh);
        fclose($fh);
        if ($r['error'] !== null) {
            $this->toast($r['error'], 'warn');

            return redirect()->to('/app/assets/import');
        }
        ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'problems' => $problems] = $r;

        \App\Libraries\Audit::log('asset.imported', $created . ' created, ' . $updated . ' updated, ' . $skipped . ' skipped');
        $msg = 'Imported — ' . $created . ' created, ' . $updated . ' updated, ' . $skipped . ' skipped';
        if ($problems) {
            $msg .= ' · ' . implode('; ', array_slice($problems, 0, 3)) . (count($problems) > 3 ? '; +' . (count($problems) - 3) . ' more' : '');
        }
        $this->toast($msg, $skipped || $problems ? 'warn' : 'ok');

        return redirect()->to('/app/assets');
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
            'user_id' => ! empty($p['user_id']) ? (int) $p['user_id'] : null,
            'site' => $p['site'] ?? '—', 'status' => ! empty($p['user_id']) ? 'In use' : 'In stock',
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
            'canManage' => $this->canManage(),
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
            'canManage' => $this->canManage(),
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
            'model' => ($p['model'] ?? '') ?: '—', 'serial' => ($p['serial'] ?? '') ?: '—',
            'user_id' => ! empty($p['user_id']) ? (int) $p['user_id'] : null,
            'site' => $p['site'] ?? $a['site'],
            'status' => in_array($p['status'] ?? '', array_keys(TH_ASSET_STATUS), true) ? $p['status'] : $a['status'],
            'os' => ($p['os'] ?? '') ?: '—',
            'warranty_until' => $warranty,
        ]);
        $this->toast($a['tag'] . ' updated');

        // Edit is reachable from the list, a ticket sidebar and the asset page,
        // so return to whichever it was — same reasoning as assign().
        return redirect()->back();
    }

    public function delete(int $id)
    {
        if (! $this->canManage()) {
            $this->toast('Only supervisors and administrators can delete assets', 'warn');

            return redirect()->back();
        }
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

    /**
     * Upsert assets from an open CSV stream (header row first). Returns
     * counts plus a list of per-line problems, or ['error' => message] when
     * the file itself is unusable. Static so it can be exercised without an upload.
     *
     * @param resource $fh
     * @return array{created:int, updated:int, skipped:int, problems:string[], error:?string}
     */
    public static function importCsv($fh): array
    {
        $db = db_connect();
        $out = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'problems' => [], 'error' => null];
        $header = fgetcsv($fh);
        if (! is_array($header)) {
            $out['error'] = 'The file is empty';

            return $out;
        }
        // Column lookup by name, so column order does not matter; BOM-safe.
        $cols = [];
        foreach ($header as $i => $h) {
            $cols[strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string) $h)))] = $i;
        }
        if (! isset($cols['name'])) {
            $out['error'] = 'The CSV needs a header row with at least a "name" column (name,type,model,serial,status,site,holder_email)';

            return $out;
        }
        $cell = static function (array $row, string $k) use ($cols): string {
            return isset($cols[$k], $row[$cols[$k]]) ? trim((string) $row[$cols[$k]]) : '';
        };

        $usersByEmail = [];
        foreach ($db->table('users')->select('id, email')->where('active', 1)->get()->getResultArray() as $u) {
            $usersByEmail[strtolower(trim((string) $u['email']))] = (int) $u['id'];
        }
        $types = ['Laptop', 'Mobile', 'Printer', 'Server', 'Switch', 'Dock', 'License'];

        $line = 1;
        while (($row = fgetcsv($fh)) !== false) {
            $line++;
            if ($row === [null] || $row === ['']) {
                continue; // blank line
            }
            if ($line > 5001) {
                $out['problems'][] = 'stopped at 5000 rows';
                break;
            }
            $name   = mb_substr($cell($row, 'name'), 0, 100);
            $serial = mb_substr($cell($row, 'serial'), 0, 80);
            $model  = mb_substr($cell($row, 'model'), 0, 100);
            $site   = mb_substr($cell($row, 'site'), 0, 60);
            $type   = $cell($row, 'type');
            $status = $cell($row, 'status');
            $email  = strtolower($cell($row, 'holder_email'));
            if ($name === '') {
                $out['skipped']++;
                $out['problems'][] = 'line ' . $line . ': no name';

                continue;
            }
            $serialOk = \App\Libraries\PdqConnect::usableSerial($serial);
            $userId = null;
            if ($email !== '') {
                $userId = $usersByEmail[$email] ?? null;
                if ($userId === null) {
                    $out['problems'][] = 'line ' . $line . ': no active user ' . $email . ' (left unassigned)';
                }
            }

            // Match on a real serial first, then on name; nothing is ever deleted.
            $existing = null;
            if ($serialOk) {
                $existing = $db->table('assets')->where('serial', $serial)->get()->getRowArray();
            }
            if (! $existing) {
                $existing = $db->table('assets')->where('name', $name)->get()->getRowArray();
            }

            $fields = ['name' => $name];
            if ($serialOk) {
                $fields['serial'] = $serial;
            }
            if ($model !== '') {
                $fields['model'] = $model;
            }
            if ($site !== '') {
                $fields['site'] = $site;
            }
            $typeMatch = array_values(array_filter($types, static fn ($t) => strcasecmp($t, $type) === 0));
            if ($typeMatch) {
                $fields['type'] = $typeMatch[0];
            }
            $statusMatch = array_values(array_filter(array_keys(TH_ASSET_STATUS), static fn ($s) => strcasecmp($s, $status) === 0));
            if ($statusMatch) {
                $fields['status'] = $statusMatch[0];
            }
            if ($userId !== null) {
                $fields['user_id'] = $userId;
            }

            if ($existing) {
                $db->table('assets')->where('id', $existing['id'])->update($fields);
                $out['updated']++;
            } else {
                do {
                    $tag = 'AST-' . random_int(1000, 9999);
                } while ($db->table('assets')->where('tag', $tag)->countAllResults());
                $db->table('assets')->insert($fields + [
                    'tag' => $tag, 'type' => 'Laptop', 'model' => '—', 'serial' => '—', 'site' => '—', 'os' => '—',
                    'user_id' => null,
                    'status' => $userId !== null ? 'In use' : 'In stock',
                ]);
                $out['created']++;
            }
        }

        return $out;
    }
}
