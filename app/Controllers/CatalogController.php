<?php

namespace App\Controllers;

class CatalogController extends BaseController
{
    public function index()
    {
        return view('agent/catalog', $this->agentShared() + [
            'title' => 'Service catalog', 'nav' => 'catalog',
            'items' => $this->db->table('catalog_items')->orderBy('id')->get()->getResultArray(),
            'cat' => (string) ($this->request->getGet('cat') ?: 'All'),
        ]);
    }

    /** Agent raises a catalog request on behalf of a requester. */
    public function request(int $id)
    {
        $item = $this->db->table('catalog_items')->where('id', $id)->get()->getRowArray();
        if (! $item) {
            return redirect()->to('/app/catalog');
        }
        $t = $this->submitCatalogRequest($item, (int) $this->request->getPost('requester_id'));
        $requester = $this->userById((int) $t['requester_id']);
        $this->toast($t['code'] . ' raised for ' . explode(' ', $requester['name'] ?? '?')[0]);

        return redirect()->to('/app/tickets/' . $t['code']);
    }

    private function catalogPayload(): ?array
    {
        $p = $this->request->getPost();
        if (empty($p['name'])) {
            $this->toast('Name the catalog item', 'warn');

            return null;
        }
        $fields = [];
        if (trim((string) ($p['fields'] ?? '')) !== '') {
            $fields = json_decode($p['fields'], true);
            if (! is_array($fields)) {
                $this->toast('The form fields JSON is not valid — item saved without custom fields', 'warn');
                $fields = [];
            }
        }

        return [
            'name' => $p['name'], 'category' => $p['category'] ?: 'Hardware',
            'icon' => $p['icon'] ?: 'layers', 'description' => $p['description'] ?: '',
            'sla' => $p['sla'] ?: '3 business days', 'approval' => $p['approval'] ?: 'None',
            'fields' => json_encode($fields),
            'group_id' => ! empty($p['group_id']) ? (int) $p['group_id'] : null,
        ];
    }

    public function create()
    {
        if ($data = $this->catalogPayload()) {
            $this->db->table('catalog_items')->insert($data);
            $this->toast('Catalog item added');
        }

        return redirect()->to('/app/catalog');
    }

    public function update(int $id)
    {
        if ($data = $this->catalogPayload()) {
            $this->db->table('catalog_items')->where('id', $id)->update($data);
            $this->toast('Catalog item updated');
        }

        return redirect()->to('/app/catalog');
    }

    public function delete(int $id)
    {
        // Catalog items and articles are shared service-desk content: one agent
        // deleting them affects every requester. Gate it the way changes and
        // announcements already are.
        if (! in_array($this->me['role'] ?? '', ['Administrator', 'Supervisor'], true)) {
            $this->toast('Only supervisors and administrators can delete this', 'warn');

            return redirect()->to('/app/catalog');
        }
        $item = $this->db->table('catalog_items')->where('id', $id)->get()->getRowArray();
        if (! $item) {
            $this->toast('That catalog item no longer exists', 'warn');

            return redirect()->to('/app/catalog');
        }
        $this->db->table('catalog_items')->where('id', $id)->delete();
        \App\Libraries\Audit::log('catalog.deleted', $item['name']);
        $this->toast($item['name'] . ' deleted', 'bad');

        return redirect()->to('/app/catalog');
    }

    /** Shared by agent + portal controllers. */
    protected function submitCatalogRequest(array $item, int $requesterId): array
    {
        $p = $this->request->getPost();
        $fields = json_decode($item['fields'] ?? '[]', true) ?: [];
        $lines = [];
        foreach ($fields as $f) {
            $lines[] = $f['label'] . ': ' . (($p[$f['k']] ?? '') !== '' ? $p[$f['k']] : '—');
        }
        if (! empty($p['notes'])) {
            $lines[] = '';
            $lines[] = 'Notes: ' . $p['notes'];
        }
        $category = match ($item['category']) {
            'Accounts & access', 'Onboarding' => 'Access',
            default => $item['category'],
        };

        $t = $this->createTicket([
            'subject' => $item['name'],
            'body' => implode("\n", $lines),
            'requester_id' => $requesterId,
            'type' => 'Service request',
            'category' => $category,
            'priority' => 'Medium',
            'source' => 'Portal',
            'tags' => ['catalog-' . $item['id']],
            // Item's fulfilling team wins; otherwise the routing rules decide.
            'group_id' => $item['group_id'] ? (int) $item['group_id'] : null,
            // Honour the turnaround the catalog advertised for this item.
            'sla' => $item['sla'] ?? null,
        ]);
        if ($item['approval'] !== 'None') {
            // Pending here means "waiting on an approver", which stops the clock
            // exactly like waiting on a requester does.
            $this->db->table('tickets')->where('id', $t['id'])->update(th_status_change($t, ['status' => 'Pending']));
            $this->db->table('ticket_approvals')->insert([
                'ticket_id' => $t['id'], 'required_of' => $item['approval'],
                'status' => 'Pending', 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $this->addSystemNote((int) $t['id'], 'Waiting on ' . $item['approval'] . ' approval');
        }

        return $t;
    }
}
