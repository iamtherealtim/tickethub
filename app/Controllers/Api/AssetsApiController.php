<?php

namespace App\Controllers\Api;

use App\Libraries\Audit;

/** Assets. Agents see and edit everything; a requester token sees only assets assigned to it. */
class AssetsApiController extends ApiController
{
    private const TYPES = ['Laptop', 'Mobile', 'Printer', 'Server', 'Switch', 'Dock', 'License', 'Desktop', 'Monitor', 'Other'];

    public function index()
    {
        $b = $this->db->table('assets');
        if (! $this->isAgent()) {
            $b->where('user_id', (int) $this->me['id']);
        }
        $q = trim((string) $this->request->getGet('q'));
        if ($q !== '') {
            $b->groupStart()->like('name', $q)->orLike('tag', $q)->orLike('serial', $q)->orLike('model', $q)->groupEnd();
        }
        foreach (['type', 'status', 'site'] as $f) {
            if (($v = trim((string) $this->request->getGet($f))) !== '') {
                $b->where($f, $v);
            }
        }
        if ($uid = (int) $this->request->getGet('user_id')) {
            $b->where('user_id', $uid);
        }

        return $this->paginate($b, [$this, 'shape'], 'id', 'DESC');
    }

    public function show(int $id)
    {
        $a = $this->db->table('assets')->where('id', $id)->get()->getRowArray();
        if (! $a || (! $this->isAgent() && (int) $a['user_id'] !== (int) $this->me['id'])) {
            return $this->fail('Asset not found', 404);
        }

        return $this->ok($this->shape($a));
    }

    public function create()
    {
        if (! $this->isAgent()) {
            return $this->fail('Only agents can create assets', 403);
        }
        $in = $this->json();
        if ($in === null) {
            return $this->fail('Invalid JSON body', 400);
        }
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            return $this->fail('name is required', 422);
        }
        $row = $this->fields($in, []);
        if (is_object($row)) {
            return $row;
        }
        do {
            $tag = 'AST-' . random_int(1000, 9999);
        } while ($this->db->table('assets')->where('tag', $tag)->countAllResults());
        $row += [
            'tag' => $tag, 'type' => 'Laptop', 'model' => '—', 'serial' => '—', 'site' => '—', 'os' => '—',
            'status' => ! empty($row['user_id']) ? 'In use' : 'In stock',
            'warranty_until' => date('Y-m-d H:i:s', time() + 365 * 86400),
        ];
        $this->db->table('assets')->insert($row);
        $id = (int) $this->db->insertID();
        Audit::log('api.asset_created', $tag . ' ' . $name);

        return $this->ok($this->shape($this->db->table('assets')->where('id', $id)->get()->getRowArray()), 201);
    }

    public function update(int $id)
    {
        if (! $this->isAgent()) {
            return $this->fail('Only agents can edit assets', 403);
        }
        $a = $this->db->table('assets')->where('id', $id)->get()->getRowArray();
        if (! $a) {
            return $this->fail('Asset not found', 404);
        }
        $in = $this->json();
        if ($in === null) {
            return $this->fail('Invalid JSON body', 400);
        }
        $upd = $this->fields($in, $a);
        if (is_object($upd)) {
            return $upd;
        }
        if (! $upd) {
            return $this->fail('Nothing to change', 422);
        }
        $this->db->table('assets')->where('id', $id)->update($upd);
        Audit::log('api.asset_updated', $a['tag']);

        return $this->ok($this->shape($this->db->table('assets')->where('id', $id)->get()->getRowArray()));
    }

    /** Validated writable fields present in $in; error response on a bad value. */
    private function fields(array $in, array $current)
    {
        $out = [];
        foreach (['name' => 100, 'model' => 100, 'serial' => 80, 'site' => 60, 'os' => 60, 'status' => 30] as $k => $max) {
            if (array_key_exists($k, $in)) {
                $v = mb_substr(trim((string) $in[$k]), 0, $max);
                if ($k === 'name' && $v === '') {
                    return $this->fail('name cannot be empty', 422);
                }
                $out[$k] = $v !== '' ? $v : '—';
            }
        }
        if (array_key_exists('status', $in) && ! isset(TH_ASSET_STATUS[$out['status']])) {
            return $this->fail('status must be one of ' . implode(', ', array_keys(TH_ASSET_STATUS)), 422);
        }
        if (array_key_exists('type', $in)) {
            $t = trim((string) $in['type']);
            if (! in_array($t, self::TYPES, true)) {
                return $this->fail('type must be one of ' . implode(', ', self::TYPES), 422);
            }
            $out['type'] = $t;
        }
        if (array_key_exists('user_id', $in)) {
            $uid = $in['user_id'] === null || $in['user_id'] === '' ? null : (int) $in['user_id'];
            if ($uid && ! $this->db->table('users')->where('id', $uid)->countAllResults()) {
                return $this->fail('user_id does not match a user', 422);
            }
            $out['user_id'] = $uid;
            if (! array_key_exists('status', $in) && ($current['status'] ?? '') !== 'Retired') {
                $out['status'] = $uid ? 'In use' : 'In stock';
            }
        }
        if (array_key_exists('warranty_until', $in)) {
            $ts = $in['warranty_until'] ? strtotime((string) $in['warranty_until']) : null;
            if ($in['warranty_until'] && ! $ts) {
                return $this->fail('warranty_until is not a date', 422);
            }
            $out['warranty_until'] = $ts ? date('Y-m-d H:i:s', $ts) : null;
        }

        return $out;
    }

    protected function shape(array $a): array
    {
        return [
            'id' => (int) $a['id'], 'tag' => $a['tag'], 'name' => $a['name'], 'type' => $a['type'],
            'model' => $a['model'], 'serial' => $a['serial'], 'site' => $a['site'], 'status' => $a['status'], 'os' => $a['os'],
            'assigned_to' => $this->userRef($a['user_id'] ? (int) $a['user_id'] : null),
            'warranty_until' => $a['warranty_until'],
            'pdq_device_id' => $a['pdq_device_id'] ?? null,
            'url' => site_url('app/assets/' . $a['id']),
        ];
    }
}
