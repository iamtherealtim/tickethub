<?php

namespace App\Controllers;

class AnnouncementsController extends BaseController
{
    /** Announcements are org-wide (agents and portal), so posting is supervisory. */
    private function canPost(): bool
    {
        return in_array($this->me['role'] ?? '', ['Administrator', 'Supervisor'], true);
    }

    /**
     * A date typed in the display timezone becomes the end of that day in UTC,
     * so "expires 30 Sep" stays visible through 30 Sep where the team sits.
     */
    private static function expiryFromInput(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        try {
            $d = new \DateTime($raw, th_tz());
        } catch (\Throwable) {
            return null;
        }
        if (! str_contains($raw, ':')) {
            $d->setTime(23, 59, 59); // a bare date means "through the end of that day"
        }

        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** Validated title/body/level/expires_at from the post, or null with a toast. */
    private function payload(): ?array
    {
        $p = $this->request->getPost();
        if (empty($p['title']) || empty($p['body'])) {
            $this->toast('Headline and message are needed', 'warn');

            return null;
        }
        $expires = self::expiryFromInput($p['expires_at'] ?? null);
        if ($expires !== null && strtotime($expires) < time()) {
            $this->toast('The expiry is already in the past', 'warn');

            return null;
        }

        return [
            'title' => mb_substr(trim($p['title']), 0, 255), 'body' => trim($p['body']),
            'level' => in_array($p['level'] ?? '', ['info', 'warn', 'alert'], true) ? $p['level'] : 'info',
            'expires_at' => $expires,
        ];
    }

    public function create()
    {
        if (! $this->canPost()) {
            $this->toast('Only supervisors and administrators can post announcements', 'warn');

            return redirect()->back();
        }
        $data = $this->payload();
        if (! $data) {
            return redirect()->back();
        }
        $this->db->table('announcements')->insert($data + [
            'user_id' => $this->me['id'],
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->toast('Announcement posted');

        return redirect()->back();
    }

    public function update(int $id)
    {
        if (! $this->canPost()) {
            $this->toast('Only supervisors and administrators can edit announcements', 'warn');

            return redirect()->back();
        }
        if (! $this->db->table('announcements')->where('id', $id)->countAllResults()) {
            $this->toast('That announcement no longer exists', 'warn');

            return redirect()->back();
        }
        $data = $this->payload();
        if (! $data) {
            return redirect()->back();
        }
        $this->db->table('announcements')->where('id', $id)->update($data);
        \App\Libraries\Audit::log('announcement.updated', $data['title']);
        $this->toast('Announcement updated');

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
