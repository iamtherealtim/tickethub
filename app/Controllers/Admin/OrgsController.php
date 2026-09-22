<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Audit;

/** Admin → Organizations: CRUD plus "claim every user whose email matches the domain". */
class OrgsController extends BaseController
{
    /** Validated payload from the form, or null after a toast. */
    private function payload(): ?array
    {
        $p      = $this->request->getPost();
        $name   = trim((string) ($p['name'] ?? ''));
        $domain = strtolower(trim((string) ($p['domain'] ?? '')));
        $domain = ltrim($domain, '@');
        if ($name === '') {
            $this->toast('Name the organization', 'warn');

            return null;
        }
        if ($domain !== '' && ! preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
            $this->toast('Domain should look like example.com (no @, no path)', 'warn');

            return null;
        }

        return [
            'name'   => mb_substr($name, 0, 120),
            'domain' => $domain !== '' ? $domain : null,
            'notes'  => trim((string) ($p['notes'] ?? '')) ?: null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /** Give the organization every unassigned user whose email domain matches. Returns how many. */
    public static function autoAssign(int $orgId, ?string $domain): int
    {
        if (! $domain) {
            return 0;
        }
        $db = db_connect();
        $db->table('users')->where('org_id', null)->like('email', '@' . $domain, 'before')->update(['org_id' => $orgId]);

        return (int) $db->affectedRows();
    }

    public function create()
    {
        if (! $data = $this->payload()) {
            return redirect()->to('/app/admin/orgs');
        }
        if ($this->db->table('organizations')->where('name', $data['name'])->countAllResults()) {
            $this->toast('An organization with that name already exists', 'warn');

            return redirect()->to('/app/admin/orgs');
        }
        $data['created_at'] = $data['updated_at'];
        $this->db->table('organizations')->insert($data);
        $id = (int) $this->db->insertID();
        $n  = $this->request->getPost('auto_assign') ? self::autoAssign($id, $data['domain']) : 0;
        Audit::log('admin.org_added', $data['name'] . ($n ? ' (+' . $n . ' members by domain)' : ''));
        $this->toast('Organization created' . ($n ? ' — ' . $n . ' people matched @' . $data['domain'] : ''));

        return redirect()->to('/app/admin/orgs');
    }

    public function update(int $id)
    {
        $org = $this->db->table('organizations')->where('id', $id)->get()->getRowArray();
        if (! $org) {
            $this->toast('Organization not found', 'warn');

            return redirect()->to('/app/admin/orgs');
        }
        if (! $data = $this->payload()) {
            return redirect()->to('/app/admin/orgs');
        }
        if ($this->db->table('organizations')->where('name', $data['name'])->where('id !=', $id)->countAllResults()) {
            $this->toast('Another organization already has that name', 'warn');

            return redirect()->to('/app/admin/orgs');
        }
        $this->db->table('organizations')->where('id', $id)->update($data);
        $n = $this->request->getPost('auto_assign') ? self::autoAssign($id, $data['domain']) : 0;
        Audit::log('admin.org_updated', $data['name'] . ($n ? ' (+' . $n . ' members by domain)' : ''));
        $this->toast($data['name'] . ' updated' . ($n ? ' — ' . $n . ' people matched @' . $data['domain'] : ''));

        return redirect()->to('/app/admin/orgs');
    }

    public function delete(int $id)
    {
        $org = $this->db->table('organizations')->where('id', $id)->get()->getRowArray();
        if ($org) {
            // Members are detached, never deleted.
            $this->db->table('users')->where('org_id', $id)->update(['org_id' => null]);
            $this->db->table('organizations')->where('id', $id)->delete();
            Audit::log('admin.org_deleted', $org['name']);
            $this->toast($org['name'] . ' deleted', 'bad');
        }

        return redirect()->to('/app/admin/orgs');
    }
}
