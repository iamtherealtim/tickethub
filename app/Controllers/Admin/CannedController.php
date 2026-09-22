<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Audit;

/**
 * Admin → Canned responses: global and per-group responses. Personal ones are
 * owned by agents (TicketsController::cannedCreate/cannedDelete); here they
 * can only be removed.
 */
class CannedController extends BaseController
{
    private function payload(): ?array
    {
        $p = $this->request->getPost();
        $title = trim((string) ($p['title'] ?? ''));
        $body  = trim((string) ($p['body'] ?? ''));
        if ($title === '' || $body === '') {
            $this->toast('A response needs a title and a body', 'warn');

            return null;
        }
        $row = ['title' => mb_substr($title, 0, 80), 'body' => $body];
        if ($this->db->fieldExists('scope', 'canned_responses')) {
            $scope   = ($p['scope'] ?? 'global') === 'group' ? 'group' : 'global';
            $groupId = (int) ($p['group_id'] ?? 0);
            if ($scope === 'group' && ! $this->db->table('groups')->where('id', $groupId)->countAllResults()) {
                $this->toast('Pick the group that response belongs to', 'warn');

                return null;
            }
            $shortcut = strtolower(trim((string) ($p['shortcut'] ?? ''), " /\t"));
            $shortcut = preg_replace('/[^a-z0-9_-]+/', '-', $shortcut);
            $row += [
                'scope' => $scope, 'group_id' => $scope === 'group' ? $groupId : null, 'owner_id' => null,
                'shortcut' => $shortcut !== '' ? mb_substr($shortcut, 0, 40) : null,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
        }

        return $row;
    }

    public function create()
    {
        $row = $this->payload();
        if ($row) {
            if (isset($row['updated_at'])) {
                $row['created_at'] = $row['updated_at'];
            }
            $this->db->table('canned_responses')->insert($row);
            Audit::log('canned.created', $row['title']);
            $this->toast('Response "' . $row['title'] . '" added');
        }

        return redirect()->to('/app/admin/canned');
    }

    public function update(int $id)
    {
        $existing = $this->db->table('canned_responses')->where('id', $id)->get()->getRowArray();
        if (! $existing || ($existing['scope'] ?? 'global') === 'personal') {
            $this->toast('That response cannot be edited here', 'warn');

            return redirect()->to('/app/admin/canned');
        }
        $row = $this->payload();
        if ($row) {
            $this->db->table('canned_responses')->where('id', $id)->update($row);
            Audit::log('canned.updated', $row['title']);
            $this->toast('Response saved');
        }

        return redirect()->to('/app/admin/canned');
    }

    public function delete(int $id)
    {
        $existing = $this->db->table('canned_responses')->where('id', $id)->get()->getRowArray();
        if ($existing) {
            $this->db->table('canned_responses')->where('id', $id)->delete();
            Audit::log('canned.deleted', $existing['title']);
            $this->toast('Response removed', 'warn');
        }

        return redirect()->to('/app/admin/canned');
    }
}
