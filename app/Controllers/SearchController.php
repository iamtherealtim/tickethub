<?php

namespace App\Controllers;

class SearchController extends BaseController
{
    /** JSON results for the command palette. */
    public function palette()
    {
        $q = mb_strtolower(trim((string) $this->request->getGet('q')));
        $hits = [];

        $pages = [
            ['dashboard', 'Dashboard', 'grid'], ['tickets', 'Tickets', 'inbox'], ['problems', 'Problems', 'warn'],
            ['changes', 'Changes', 'branch'], ['assets', 'Assets', 'server'], ['catalog', 'Service catalog', 'layers'],
            ['kb', 'Knowledge', 'book'], ['reports', 'Reports', 'chart'], ['admin', 'Admin', 'cog'],
        ];
        foreach ($pages as [$path, $label, $ic]) {
            if (! $q || str_contains(mb_strtolower($label), $q)) {
                $hits[] = ['label' => $label, 'meta' => 'Page', 'url' => site_url('app/' . $path), 'icon' => th_icon($ic, 'w-4 h-4')];
            }
        }

        if ($q) {
            foreach ($this->scopeTickets($this->db->table('tickets')->get()->getResultArray()) as $t) {
                if (str_contains(mb_strtolower($t['code'] . ' ' . $t['subject']), $q)) {
                    $hits[] = ['label' => $t['subject'], 'meta' => $t['code'] . ' · ' . $t['status'], 'url' => site_url('app/tickets/' . $t['code']), 'icon' => th_icon('inbox', 'w-4 h-4')];
                }
            }
            foreach ($this->db->table('articles')->get()->getResultArray() as $a) {
                if (str_contains(mb_strtolower($a['title']), $q)) {
                    $hits[] = ['label' => $a['title'], 'meta' => 'Article · ' . $a['category'], 'url' => site_url('app/kb/' . $a['id']), 'icon' => th_icon('book', 'w-4 h-4')];
                }
            }
            foreach ($this->db->table('assets')->get()->getResultArray() as $a) {
                if (str_contains(mb_strtolower($a['tag'] . $a['name'] . $a['serial']), $q)) {
                    $hits[] = ['label' => $a['name'], 'meta' => $a['tag'] . ' · ' . $a['model'], 'url' => site_url('app/assets/' . $a['id']), 'icon' => th_icon('server', 'w-4 h-4')];
                }
            }
            foreach ($this->db->table('users')->where('role', 'Requester')->get()->getResultArray() as $u) {
                if (str_contains(mb_strtolower($u['name']), $q)) {
                    $hits[] = ['label' => $u['name'], 'meta' => $u['dept'] . ' · ' . $u['site'], 'url' => site_url('app/tickets?q=' . urlencode($u['name'])), 'icon' => th_icon('user', 'w-4 h-4')];
                }
            }
        }

        return $this->response->setJSON(array_slice($hits, 0, 12));
    }

    /** Server-rendered modal: a requester's ticket history. */
    public function requesterHistory(int $id)
    {
        $u = $this->userById($id);
        if (! $u) {
            return $this->response->setStatusCode(404, 'Not found');
        }
        $list = $this->scopeTickets($this->db->table('tickets')->where('requester_id', $id)->orderBy('created_at', 'DESC')->get()->getResultArray());

        return view('agent/_history_modal', ['u' => $u, 'list' => $list]);
    }

    /** Server-rendered modal: notifications. */
    public function notifications()
    {
        $open = $this->scopeTickets($this->db->table('tickets')->whereIn('status', TH_OPEN_STATES)->get()->getResultArray());
        $overdue = array_values(array_filter($open, static fn ($t) => th_sla($t)['remaining'] <= 0));
        $mentions = array_values(array_filter($open, fn ($t) => (int) $t['agent_id'] === (int) $this->me['id'] && $t['status'] === 'Pending'));
        $approvals = $this->db->table('changes')->where('state', 'Awaiting approval')->get()->getResultArray();
        $announcement = $this->db->table('announcements')->orderBy('created_at', 'DESC')->limit(1)->get()->getRowArray();

        return view('agent/_notifications_modal', [
            'overdue' => $overdue, 'mentions' => $mentions, 'approvals' => $approvals, 'announcement' => $announcement,
        ]);
    }
}
