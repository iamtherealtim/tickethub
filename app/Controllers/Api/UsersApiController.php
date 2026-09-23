<?php

namespace App\Controllers\Api;

use App\Libraries\Audit;

/**
 * Users. Agents can read everyone; a requester token sees only itself.
 * Creating a requester needs an Administrator token. Administrators may
 * change any field; agents may update a requester's contact details only.
 */
class UsersApiController extends ApiController
{
    private const ROLES = ['Administrator', 'Supervisor', 'Agent', 'Requester'];

    public function index()
    {
        $b = $this->db->table('users');
        if (! $this->isAgent()) {
            $b->where('id', (int) $this->me['id']);
        }
        $q = trim((string) $this->request->getGet('q'));
        if ($q !== '') {
            $b->groupStart()->like('name', $q)->orLike('email', $q)->groupEnd();
        }
        $role = (string) $this->request->getGet('role');
        if ($role !== '') {
            if (! in_array($role, self::ROLES, true)) {
                return $this->fail('role must be one of ' . implode(', ', self::ROLES), 422);
            }
            $b->where('role', $role);
        }
        if (($org = $this->request->getGet('org')) !== null && $org !== '' && $this->db->fieldExists('org_id', 'users')) {
            $b->where('org_id', (int) $org);
        }
        if (($active = $this->request->getGet('active')) !== null && $active !== '') {
            $b->where('active', $active === '1' || $active === 'true' ? 1 : 0);
        }

        return $this->paginate($b, [$this, 'shape'], 'name', 'ASC');
    }

    public function show(int $id)
    {
        $u = $this->db->table('users')->where('id', $id)->get()->getRowArray();
        if (! $u || (! $this->isAgent() && (int) $u['id'] !== (int) $this->me['id'])) {
            return $this->fail('User not found', 404);
        }

        return $this->ok($this->shape($u));
    }

    public function create()
    {
        if (! $this->isAdmin()) {
            return $this->fail('Only an Administrator token can create users', 403);
        }
        $in = $this->json();
        if ($in === null) {
            return $this->fail('Invalid JSON body', 400);
        }
        $name  = trim((string) ($in['name'] ?? ''));
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        if ($name === '' || $email === '') {
            return $this->fail('name and email are required', 422);
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('email is not a valid address', 422);
        }
        if ($this->db->table('users')->where('email', $email)->countAllResults()) {
            return $this->fail('That email is already registered', 422);
        }
        $now = date('Y-m-d H:i:s');
        $row = [
            'name' => mb_substr($name, 0, 100), 'email' => mb_substr($email, 0, 150),
            // Unusable until reset: the account exists to own tickets, not to sign in.
            'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
            'role' => 'Requester',
            'title' => mb_substr(trim((string) ($in['title'] ?? '')), 0, 100) ?: 'Employee',
            'dept' => mb_substr(trim((string) ($in['dept'] ?? '')), 0, 60) ?: null,
            'site' => mb_substr(trim((string) ($in['site'] ?? '')), 0, 60) ?: null,
            'phone' => mb_substr(trim((string) ($in['phone'] ?? '')), 0, 40) ?: null,
            'color' => ['brand', 'ink', 'violet', 'signal'][random_int(0, 3)],
            'active' => 1, 'must_change_password' => 1, 'created_at' => $now, 'updated_at' => $now,
        ];
        if ($err = $this->applyOrg($row, $in)) {
            return $err;
        }
        $this->db->table('users')->insert($row);
        $id = (int) $this->db->insertID();
        Audit::log('api.user_created', $email);

        return $this->ok($this->shape($this->db->table('users')->where('id', $id)->get()->getRowArray()), 201);
    }

    public function update(int $id)
    {
        $u = $this->db->table('users')->where('id', $id)->get()->getRowArray();
        if (! $u) {
            return $this->fail('User not found', 404);
        }
        if (! $this->isAgent()) {
            return $this->fail('Only agents can update users', 403);
        }
        if (! $this->isAdmin() && $u['role'] !== 'Requester') {
            return $this->fail('Only an Administrator token can change agents', 403);
        }
        $in = $this->json();
        if ($in === null) {
            return $this->fail('Invalid JSON body', 400);
        }

        $upd = [];
        foreach (['name' => 100, 'title' => 100, 'dept' => 60, 'site' => 60, 'phone' => 40] as $k => $max) {
            if (array_key_exists($k, $in)) {
                $v = mb_substr(trim((string) $in[$k]), 0, $max);
                if ($k === 'name' && $v === '') {
                    return $this->fail('name cannot be empty', 422);
                }
                $upd[$k] = $v !== '' ? $v : null;
            }
        }
        // Organization, like role and group, is an admin decision (the web UI
        // only lets Administrators edit people), so check before applying it.
        $adminOnly = array_intersect(array_keys($in), ['email', 'role', 'active', 'group_id', 'org_id']);
        if ($adminOnly && ! $this->isAdmin()) {
            return $this->fail('Only an Administrator token can change ' . implode(', ', $adminOnly), 403);
        }
        if ($err = $this->applyOrg($upd, $in)) {
            return $err;
        }

        $adminOnly = array_intersect(array_keys($in), ['email', 'role', 'active', 'group_id']);
        if ($adminOnly && ! $this->isAdmin()) {
            return $this->fail('Only an Administrator token can change ' . implode(', ', $adminOnly), 403);
        }
        if ($this->isAdmin()) {
            if (array_key_exists('email', $in)) {
                $email = strtolower(trim((string) $in['email']));
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return $this->fail('email is not a valid address', 422);
                }
                if ($this->db->table('users')->where('email', $email)->where('id !=', $id)->countAllResults()) {
                    return $this->fail('That email is already registered', 422);
                }
                $upd['email'] = $email;
            }
            if (array_key_exists('role', $in)) {
                if (! in_array($in['role'], self::ROLES, true)) {
                    return $this->fail('role must be one of ' . implode(', ', self::ROLES), 422);
                }
                if ((int) $u['id'] === (int) $this->me['id'] && $in['role'] !== 'Administrator') {
                    return $this->fail('You cannot demote your own account', 422);
                }
                $upd['role'] = $in['role'];
            }
            if (array_key_exists('active', $in)) {
                $active = filter_var($in['active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($active === null) {
                    return $this->fail('active must be true or false', 422);
                }
                if (! $active && (int) $u['id'] === (int) $this->me['id']) {
                    return $this->fail('You cannot deactivate your own account', 422);
                }
                $upd['active'] = $active ? 1 : 0;
            }
            if (array_key_exists('group_id', $in)) {
                $gid = $in['group_id'] === null || $in['group_id'] === '' ? null : (int) $in['group_id'];
                if ($gid && ! $this->db->table('groups')->where('id', $gid)->countAllResults()) {
                    return $this->fail('group_id does not match a group', 422);
                }
                $upd['group_id'] = $gid;
            }
        }
        if (! $upd) {
            return $this->fail('Nothing to change', 422);
        }
        $upd['updated_at'] = date('Y-m-d H:i:s');
        $this->db->table('users')->where('id', $id)->update($upd);
        Audit::log('api.user_updated', $u['email'] . ' (' . implode(', ', array_keys($upd)) . ')');

        return $this->ok($this->shape($this->db->table('users')->where('id', $id)->get()->getRowArray()));
    }

    /** Validate org_id from the body into $row; returns an error response or null. */
    private function applyOrg(array &$row, array $in)
    {
        if (! array_key_exists('org_id', $in)) {
            return null;
        }
        if (! $this->db->fieldExists('org_id', 'users')) {
            return $this->fail('Organizations are not available (migration pending)', 422);
        }
        $orgId = $in['org_id'] === null || $in['org_id'] === '' ? null : (int) $in['org_id'];
        if ($orgId && ! $this->db->table('organizations')->where('id', $orgId)->countAllResults()) {
            return $this->fail('org_id does not match an organization', 422);
        }
        $row['org_id'] = $orgId;

        return null;
    }

    protected function shape(array $u): array
    {
        return [
            'id'       => (int) $u['id'],
            'name'     => $u['name'],
            'email'    => $u['email'],
            'role'     => $u['role'],
            'title'    => $u['title'],
            'dept'     => $u['dept'],
            'site'     => $u['site'],
            'phone'    => $u['phone'],
            'group_id' => $u['group_id'] ? (int) $u['group_id'] : null,
            'org_id'   => isset($u['org_id']) && $u['org_id'] ? (int) $u['org_id'] : null,
            'active'   => (bool) $u['active'],
            'created_at' => $u['created_at'],
            'updated_at' => $u['updated_at'],
        ];
    }
}
