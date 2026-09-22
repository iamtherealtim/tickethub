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
            $tickets = $this->scopeWhere($this->db->table('tickets'))
                ->groupStart()->like('code', $q)->orLike('subject', $q)->groupEnd()
                ->orderBy('updated_at', 'DESC')->limit(8)->get()->getResultArray();
            foreach ($tickets as $t) {
                $hits[] = ['label' => $t['subject'], 'meta' => $t['code'] . ' · ' . $t['status'], 'url' => site_url('app/tickets/' . $t['code']), 'icon' => th_icon('inbox', 'w-4 h-4')];
            }
            foreach ($this->db->table('articles')->like('title', $q)->limit(6)->get()->getResultArray() as $a) {
                $hits[] = ['label' => $a['title'], 'meta' => 'Article · ' . $a['category'], 'url' => site_url('app/kb/' . $a['id']), 'icon' => th_icon('book', 'w-4 h-4')];
            }
            foreach ($this->db->table('assets')->get()->getResultArray() as $a) {
                if (str_contains(mb_strtolower($a['tag'] . $a['name'] . $a['serial']), $q)) {
                    $hits[] = ['label' => $a['name'], 'meta' => $a['tag'] . ' · ' . $a['model'], 'url' => site_url('app/assets/' . $a['id']), 'icon' => th_icon('server', 'w-4 h-4')];
                }
            }
            foreach ($this->db->table('users')->where('role', 'Requester')->like('name', $q)->limit(6)->get()->getResultArray() as $u) {
                $hits[] = ['label' => $u['name'], 'meta' => $u['dept'] . ' · ' . $u['site'], 'url' => site_url('app/tickets?q=' . urlencode($u['name'])), 'icon' => th_icon('user', 'w-4 h-4')];
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
        $list = $this->scopeWhere($this->db->table('tickets')->where('requester_id', $id))->orderBy('created_at', 'DESC')->get()->getResultArray();

        return view('agent/_history_modal', ['u' => $u, 'list' => $list]);
    }

    /**
     * Server-rendered modal: notifications. Persisted events for this user
     * (unread first) sit above the computed "what needs you" list.
     */
    public function notifications()
    {
        $me = (int) $this->me['id'];
        $unread = [];
        $recent = [];
        try {
            $unread = $this->db->table('notifications')->where('user_id', $me)->where('read_at', null)
                ->orderBy('created_at', 'DESC')->limit(30)->get()->getResultArray();
            $recent = $this->db->table('notifications')->where('user_id', $me)->where('read_at IS NOT NULL', null, false)
                ->orderBy('read_at', 'DESC')->limit(5)->get()->getResultArray();
        } catch (\Throwable $e) {
            // table not migrated yet
        }

        $overdue = $this->scopeWhere($this->db->table('tickets')->whereIn('status', TH_OPEN_STATES)->where('res_due <', date('Y-m-d H:i:s')))
            ->orderBy('res_due')->limit(10)->get()->getResultArray();
        $mentions = $this->db->table('tickets')->where('agent_id', $me)->where('status', 'Pending')->orderBy('updated_at', 'DESC')->limit(10)->get()->getResultArray();
        $approvals = $this->db->table('changes')->where('state', 'Awaiting approval')->get()->getResultArray();
        $announcement = $this->db->table('announcements')->orderBy('created_at', 'DESC')->limit(1)->get()->getRowArray();

        return view('agent/_notifications_modal', [
            'unread' => $unread, 'recent' => $recent,
            'overdue' => $overdue, 'mentions' => $mentions, 'approvals' => $approvals, 'announcement' => $announcement,
        ]);
    }

    /** POST notifications/read — mark everything read. */
    public function readAllNotifications()
    {
        $this->db->table('notifications')->where('user_id', (int) $this->me['id'])->where('read_at', null)
            ->update(['read_at' => date('Y-m-d H:i:s')]);
        if ($this->request->isAJAX()) {
            return $this->response->setJSON(['ok' => true]);
        }
        $this->toast('All notifications marked read');

        return redirect()->back();
    }

    /** POST notifications/(:num)/read — mark one read, then go where it points. */
    public function readNotification(int $id)
    {
        $n = $this->db->table('notifications')->where('id', $id)->where('user_id', (int) $this->me['id'])->get()->getRowArray();
        if ($n && ! $n['read_at']) {
            $this->db->table('notifications')->where('id', $id)->update(['read_at' => date('Y-m-d H:i:s')]);
        }
        if ($this->request->isAJAX()) {
            return $this->response->setJSON(['ok' => (bool) $n, 'url' => $n['url'] ?? null]);
        }
        // Only follow our own links: the url column is written by this app, but a
        // redirect target still deserves the same-site check.
        $url = (string) ($n['url'] ?? '');
        if ($url !== '' && str_starts_with($url, site_url())) {
            return redirect()->to($url);
        }

        return redirect()->back();
    }
}
