<?php

namespace App\Controllers\Api;

use App\Controllers\Admin\OrgsController;
use App\Libraries\Audit;

/** Organizations: agents read, Administrators write (same rules as Admin → Organizations). */
class OrganizationsApiController extends ApiController
{
    private function ready(): bool
    {
        return $this->db->tableExists('organizations');
    }

    public function index()
    {
        if (! $this->isAgent()) {
            return $this->fail('Only agents can list organizations', 403);
        }
        if (! $this->ready()) {
            return $this->ok(['data' => [], 'page' => 1, 'per_page' => 25, 'total' => 0]);
        }
        $b = $this->db->table('organizations');
        $q = trim((string) $this->request->getGet('q'));
        if ($q !== '') {
            $b->groupStart()->like('name', $q)->orLike('domain', $q)->groupEnd();
        }

        return $this->paginate($b, [$this, 'shape'], 'name', 'ASC');
    }

    public function show(int $id)
    {
        $o = $this->ready() ? $this->db->table('organizations')->where('id', $id)->get()->getRowArray() : null;
        if (! $o || (! $this->isAgent() && (int) ($this->me['org_id'] ?? 0) !== $id)) {
            return $this->fail('Organization not found', 404);
        }

        return $this->ok($this->shape($o));
    }

    public function create()
    {
        if (! $this->isAdmin()) {
            return $this->fail('Only an Administrator token can create organizations', 403);
        }
        if (! $this->ready()) {
            return $this->fail('Organizations are not available (migration pending)', 422);
        }
        $in = $this->json();
        if ($in === null) {
            return $this->fail('Invalid JSON body', 400);
        }
        $data = $this->validated($in, null);
        if (is_object($data)) {
            return $data;
        }
        $data['created_at'] = $data['updated_at'];
        $this->db->table('organizations')->insert($data);
        $id = (int) $this->db->insertID();
        $n  = ! empty($in['auto_assign']) ? OrgsController::autoAssign($id, $data['domain']) : 0;
        Audit::log('api.org_created', $data['name'] . ($n ? ' (+' . $n . ' members)' : ''));

        return $this->ok($this->shape($this->db->table('organizations')->where('id', $id)->get()->getRowArray()) + ['auto_assigned' => $n], 201);
    }

    public function update(int $id)
    {
        if (! $this->isAdmin()) {
            return $this->fail('Only an Administrator token can update organizations', 403);
        }
        $o = $this->ready() ? $this->db->table('organizations')->where('id', $id)->get()->getRowArray() : null;
        if (! $o) {
            return $this->fail('Organization not found', 404);
        }
        $in = $this->json();
        if ($in === null) {
            return $this->fail('Invalid JSON body', 400);
        }
        $data = $this->validated($in + ['name' => $o['name'], 'domain' => $o['domain'], 'notes' => $o['notes']], $id);
        if (is_object($data)) {
            return $data;
        }
        $this->db->table('organizations')->where('id', $id)->update($data);
        $n = ! empty($in['auto_assign']) ? OrgsController::autoAssign($id, $data['domain']) : 0;
        Audit::log('api.org_updated', $data['name'] . ($n ? ' (+' . $n . ' members)' : ''));

        return $this->ok($this->shape($this->db->table('organizations')->where('id', $id)->get()->getRowArray()) + ['auto_assigned' => $n]);
    }

    /** Validated row, or an error response. */
    private function validated(array $in, ?int $selfId)
    {
        $name   = trim((string) ($in['name'] ?? ''));
        $domain = ltrim(strtolower(trim((string) ($in['domain'] ?? ''))), '@');
        if ($name === '') {
            return $this->fail('name is required', 422);
        }
        if ($domain !== '' && ! preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
            return $this->fail('domain should look like example.com', 422);
        }
        $dup = $this->db->table('organizations')->where('name', $name);
        if ($selfId) {
            $dup->where('id !=', $selfId);
        }
        if ($dup->countAllResults()) {
            return $this->fail('An organization with that name already exists', 422);
        }

        return [
            'name' => mb_substr($name, 0, 120), 'domain' => $domain !== '' ? $domain : null,
            'notes' => isset($in['notes']) ? (trim((string) $in['notes']) ?: null) : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    protected function shape(array $o): array
    {
        return [
            'id' => (int) $o['id'], 'name' => $o['name'], 'domain' => $o['domain'], 'notes' => $o['notes'],
            'members' => (int) $this->db->table('users')->where('org_id', (int) $o['id'])->countAllResults(),
            'created_at' => $o['created_at'], 'updated_at' => $o['updated_at'],
        ];
    }
}
