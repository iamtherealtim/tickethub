<?php

namespace App\Controllers\Api;

/** GET api/me — who this token acts as. */
class MeApiController extends ApiController
{
    public function show()
    {
        $token = $this->db->table('api_tokens')->select('id, name, created_at, expires_at, last_used_at')
            ->where('id', (int) $this->session->get('api_token'))->get()->getRowArray();
        $group = $this->me['group_id'] ? $this->db->table('groups')->select('id, name')->where('id', (int) $this->me['group_id'])->get()->getRowArray() : null;

        return $this->ok([
            'id'       => (int) $this->me['id'],
            'name'     => $this->me['name'],
            'email'    => $this->me['email'],
            'role'     => $this->me['role'],
            'group'    => $group ? ['id' => (int) $group['id'], 'name' => $group['name']] : null,
            'org_id'   => isset($this->me['org_id']) && $this->me['org_id'] ? (int) $this->me['org_id'] : null,
            'is_agent' => $this->isAgent(),
            'is_admin' => $this->isAdmin(),
            'token'    => $token ? ['id' => (int) $token['id'], 'name' => $token['name'], 'created_at' => $token['created_at'], 'expires_at' => $token['expires_at']] : null,
        ]);
    }
}
