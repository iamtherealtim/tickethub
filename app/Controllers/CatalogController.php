<?php

namespace App\Controllers;

class CatalogController extends BaseController
{
    private const FIELD_TYPES = ['text', 'select', 'textarea', 'date', 'number'];

    public function index()
    {
        return view('agent/catalog', $this->agentShared() + [
            'title' => 'Service catalog', 'nav' => 'catalog',
            'items' => $this->db->table('catalog_items')->orderBy('id')->get()->getResultArray(),
            'cat' => (string) ($this->request->getGet('cat') ?: 'All'),
            'canManage' => $this->canManageCatalog(),
        ]);
    }

    /** Catalog items are shared service-desk content, so shaping them is supervisory. */
    private function canManageCatalog(): bool
    {
        return in_array($this->me['role'] ?? '', ['Administrator', 'Supervisor'], true);
    }

    private function denyManage()
    {
        $this->toast('Only supervisors and administrators can change the catalog', 'warn');

        return redirect()->to('/app/catalog');
    }

    /** Agent raises a catalog request on behalf of a requester. */
    public function request(int $id)
    {
        $item = $this->db->table('catalog_items')->where('id', $id)->get()->getRowArray();
        if (! $item) {
            return redirect()->to('/app/catalog');
        }
        $requesterId = (int) $this->request->getPost('requester_id');
        $requester = $requesterId ? $this->db->table('users')->where('id', $requesterId)->where('active', 1)->get()->getRowArray() : null;
        if (! $requester) {
            $this->toast('Choose an active person to request this for', 'warn');

            return redirect()->to('/app/catalog');
        }
        $t = $this->submitCatalogRequest($item, $requesterId);
        if (! $t) {
            return redirect()->to('/app/catalog');
        }
        $this->toast($t['code'] . ' raised for ' . explode(' ', $requester['name'] ?? '?')[0]);

        return redirect()->to('/app/tickets/' . $t['code']);
    }

    /**
     * Parse and validate the form-fields JSON an admin typed. Returns the
     * normalised field list, or a list of problems (strings) when it is unusable.
     *
     * @return array{0: array, 1: string[]}
     */
    public static function parseFields(string $json): array
    {
        $json = trim($json);
        if ($json === '' || $json === '[]') {
            return [[], []];
        }
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return [[], ['not valid JSON (' . json_last_error_msg() . ')']];
        }
        if (! array_is_list($decoded)) {
            return [[], ['must be a JSON array of field objects']];
        }
        $out = [];
        $problems = [];
        $seen = [];
        foreach ($decoded as $i => $f) {
            $n = 'field ' . ($i + 1);
            if (! is_array($f) || array_is_list($f)) {
                $problems[] = $n . ': must be an object';

                continue;
            }
            $k = isset($f['k']) && is_scalar($f['k']) ? trim((string) $f['k']) : '';
            if ($k === '' || ! preg_match('/^[a-z][a-z0-9_-]{0,39}$/', $k)) {
                $problems[] = $n . ': "k" must be a slug (a-z, 0-9, _ or -, starting with a letter)';
            } elseif (isset($seen[$k]) || in_array($k, ['notes', 'requester_id', config('Security')->tokenName], true)) {
                $problems[] = $n . ': "k" ' . $k . ' is already used';
            }
            $seen[$k] = true;
            $label = isset($f['label']) && is_scalar($f['label']) ? trim((string) $f['label']) : '';
            if ($label === '') {
                $problems[] = $n . ': "label" is required';
            }
            $type = isset($f['type']) && is_scalar($f['type']) ? strtolower(trim((string) $f['type'])) : 'text';
            if (! in_array($type, self::FIELD_TYPES, true)) {
                $problems[] = $n . ': "type" must be one of ' . implode('|', self::FIELD_TYPES);
            }
            $options = $f['options'] ?? $f['opts'] ?? [];
            if (! is_array($options) || ! array_is_list($options)) {
                $problems[] = $n . ': "options" must be a list';
                $options = [];
            }
            $options = array_values(array_filter(array_map(static fn ($o) => is_scalar($o) ? trim((string) $o) : '', $options), static fn ($o) => $o !== ''));
            if ($type === 'select' && ! $options) {
                $problems[] = $n . ': a select needs at least one entry in "options"';
            }
            if (isset($f['required']) && ! is_bool($f['required']) && ! in_array($f['required'], [0, 1, '0', '1', 'true', 'false'], true)) {
                $problems[] = $n . ': "required" must be true or false';
            }
            $row = ['k' => $k, 'label' => mb_substr($label, 0, 80), 'type' => $type, 'required' => filter_var($f['required'] ?? false, FILTER_VALIDATE_BOOLEAN)];
            if ($type === 'select') {
                $row['options'] = $options;
            }
            $out[] = $row;
        }

        return [$problems ? [] : $out, $problems];
    }

    private function catalogPayload(): ?array
    {
        $p = $this->request->getPost();
        if (empty($p['name'])) {
            $this->toast('Name the catalog item', 'warn');

            return null;
        }
        [$fields, $problems] = self::parseFields((string) ($p['fields'] ?? ''));
        if ($problems) {
            $this->toast('Form fields not saved — ' . implode('; ', array_slice($problems, 0, 4)) . (count($problems) > 4 ? '; …' : ''), 'bad');

            return null;
        }

        return [
            'name' => mb_substr(trim($p['name']), 0, 120), 'category' => ($p['category'] ?? '') ?: 'Hardware',
            'icon' => ($p['icon'] ?? '') ?: 'layers', 'description' => $p['description'] ?? '',
            'sla' => ($p['sla'] ?? '') ?: '3 business days', 'approval' => ($p['approval'] ?? '') ?: 'None',
            'fields' => json_encode($fields, JSON_UNESCAPED_UNICODE),
            'group_id' => ! empty($p['group_id']) ? (int) $p['group_id'] : null,
        ];
    }

    public function create()
    {
        if (! $this->canManageCatalog()) {
            return $this->denyManage();
        }
        if ($data = $this->catalogPayload()) {
            $this->db->table('catalog_items')->insert($data);
            $this->toast('Catalog item added');
        }

        return redirect()->to('/app/catalog');
    }

    public function update(int $id)
    {
        if (! $this->canManageCatalog()) {
            return $this->denyManage();
        }
        if (! $this->db->table('catalog_items')->where('id', $id)->countAllResults()) {
            $this->toast('That catalog item no longer exists', 'warn');

            return redirect()->to('/app/catalog');
        }
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
        if (! $this->canManageCatalog()) {
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

    /**
     * Shared by agent + portal controllers. Returns null (with a toast set)
     * when a required field is missing, so nothing half-filled is raised.
     */
    protected function submitCatalogRequest(array $item, int $requesterId): ?array
    {
        $p = $this->request->getPost();
        // Stored JSON is re-validated on the way out too: an item saved before
        // validation existed must not be able to break the request form.
        [$fields] = self::parseFields((string) ($item['fields'] ?? '[]'));
        $lines = [];
        foreach ($fields as $f) {
            $value = trim((string) ($p[$f['k']] ?? ''));
            if ($value === '' && $f['required']) {
                $this->toast('"' . $f['label'] . '" is required', 'warn');

                return null;
            }
            if ($f['type'] === 'select' && $value !== '' && ! in_array($value, $f['options'], true)) {
                $this->toast('"' . $f['label'] . '" must be one of the listed options', 'warn');

                return null;
            }
            $lines[] = $f['label'] . ': ' . ($value !== '' ? mb_substr($value, 0, 2000) : '—');
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
