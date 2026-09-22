<?php

namespace App\Controllers;

class AnnouncementsController extends BaseController
{
    /** Announcements are org-wide (agents and portal), so posting is supervisory. */
    private function canPost(): bool
    {
        return in_array($this->me['role'] ?? '', ['Administrator', 'Supervisor'], true);
    }

    public function create()
    {
        if (! $this->canPost()) {
            $this->toast('Only supervisors and administrators can post announcements', 'warn');

            return redirect()->back();
        }
        $p = $this->request->getPost();
        if (empty($p['title']) || empty($p['body'])) {
            $this->toast('Headline and message are needed', 'warn');

            return redirect()->back();
        }
        $this->db->table('announcements')->insert([
            'title' => $p['title'], 'body' => $p['body'],
            'user_id' => $this->me['id'],
            'level' => in_array($p['level'], ['info', 'warn', 'alert'], true) ? $p['level'] : 'info',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->toast('Announcement posted');

        return redirect()->back();
    }

    public function delete(int $id)
    {
        if (! $this->canPost()) {
            $this->toast('Only supervisors and administrators can remove announcements', 'warn');

            return redirect()->back();
        }
        $a = $this->db->table('announcements')->where('id', $id)->get()->getRowArray();
        $this->db->table('announcements')->where('id', $id)->delete();
        \App\Libraries\Audit::log('announcement.deleted', $a['title'] ?? (string) $id);
        $this->toast('Announcement removed', 'bad');

        return redirect()->back();
    }
}
