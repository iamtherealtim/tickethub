<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Audit;
use App\Libraries\TicketIntake;

/** Admin → Ticket templates: templates and the recurring schedules built on them. */
class TemplatesController extends BaseController
{
    private function back()
    {
        return redirect()->to('/app/admin/templates');
    }

    /* ---------- templates ---------- */

    private function templatePayload(): ?array
    {
        $p = $this->request->getPost();
        $name = trim((string) ($p['name'] ?? ''));
        $subject = trim((string) ($p['subject'] ?? ''));
        $body = trim((string) ($p['body'] ?? ''));
        if ($name === '' || $subject === '' || $body === '') {
            $this->toast('A template needs a name, a subject and a description', 'warn');

            return null;
        }
        $groupId = (int) ($p['group_id'] ?? 0) ?: null;
        if ($groupId && ! $this->db->table('groups')->where('id', $groupId)->countAllResults()) {
            $groupId = null;
        }
        $agentId = (int) ($p['agent_id'] ?? 0) ?: null;
        if ($agentId && ! $this->db->table('users')->where('id', $agentId)->whereIn('role', ['Administrator', 'Supervisor', 'Agent'])->countAllResults()) {
            $agentId = null;
        }
        $tasks = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) ($p['tasks'] ?? '')) ?: []), static fn ($t) => $t !== ''));

        return [
            'name' => mb_substr($name, 0, 100), 'subject' => mb_substr($subject, 0, 250), 'body' => $body,
            'type' => in_array($p['type'] ?? '', ['Incident', 'Service request'], true) ? $p['type'] : 'Incident',
            'priority' => isset(TH_PRIORITY[$p['priority'] ?? '']) ? $p['priority'] : 'Medium',
            'category' => in_array($p['category'] ?? '', TH_CATEGORIES, true) ? $p['category'] : 'Software',
            'group_id' => $groupId, 'agent_id' => $agentId,
            'tasks' => json_encode(array_map(static fn ($t) => mb_substr($t, 0, 255), $tasks)),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    public function create()
    {
        if ($row = $this->templatePayload()) {
            $row['created_by'] = (int) $this->me['id'];
            $row['created_at'] = $row['updated_at'];
            $this->db->table('ticket_templates')->insert($row);
            Audit::log('template.created', $row['name']);
            $this->toast('Template "' . $row['name'] . '" added');
        }

        return $this->back();
    }

    public function update(int $id)
    {
        if (! $this->db->table('ticket_templates')->where('id', $id)->countAllResults()) {
            return $this->back();
        }
        if ($row = $this->templatePayload()) {
            $this->db->table('ticket_templates')->where('id', $id)->update($row);
            Audit::log('template.updated', $row['name']);
            $this->toast('Template saved');
        }

        return $this->back();
    }

    public function delete(int $id)
    {
        $t = $this->db->table('ticket_templates')->where('id', $id)->get()->getRowArray();
        if ($t) {
            $this->db->table('recurring_tickets')->where('template_id', $id)->delete();
            $this->db->table('ticket_templates')->where('id', $id)->delete();
            Audit::log('template.deleted', $t['name']);
            $this->toast('Template removed', 'warn');
        }

        return $this->back();
    }

    /* ---------- recurring ---------- */

    private function recurringPayload(): ?array
    {
        $p = $this->request->getPost();
        $templateId = (int) ($p['template_id'] ?? 0);
        $requesterId = (int) ($p['requester_id'] ?? 0);
        if (! $this->db->table('ticket_templates')->where('id', $templateId)->countAllResults()) {
            $this->toast('Pick a template', 'warn');

            return null;
        }
        if (! $this->db->table('users')->where('id', $requesterId)->where('active', 1)->countAllResults()) {
            $this->toast('Pick who the ticket is raised for', 'warn');

            return null;
        }
        $tz = trim((string) ($p['tz'] ?? '')) ?: 'UTC';
        try {
            new \DateTimeZone($tz);
        } catch (\Throwable $e) {
            $this->toast('"' . $tz . '" is not a timezone name (try America/Toronto)', 'warn');

            return null;
        }
        $at = (string) ($p['at_time'] ?? '09:00');
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $at)) {
            $at = '09:00';
        }
        $every = in_array($p['every'] ?? '', ['day', 'week', 'month'], true) ? $p['every'] : 'week';
        $weekday = ($p['weekday'] ?? '') !== '' ? max(0, min(6, (int) $p['weekday'])) : null;
        $dom = ($p['day_of_month'] ?? '') !== '' ? max(1, min(31, (int) $p['day_of_month'])) : null;

        $row = [
            'template_id' => $templateId, 'requester_id' => $requesterId,
            'every' => $every, 'interval' => max(1, min(52, (int) ($p['interval'] ?? 1))),
            'weekday' => $every === 'week' ? $weekday : null,
            'day_of_month' => $every === 'month' ? $dom : null,
            'at_time' => $at . ':00', 'tz' => mb_substr($tz, 0, 64),
        ];
        $row['next_run_at'] = TicketIntake::nextRun($row);

        return $row;
    }

    public function createRecurring()
    {
        if ($row = $this->recurringPayload()) {
            $row['active'] = 1;
            $row['created_at'] = date('Y-m-d H:i:s');
            $this->db->table('recurring_tickets')->insert($row);
            Audit::log('recurring.created', 'template #' . $row['template_id'] . ' every ' . $row['interval'] . ' ' . $row['every']);
            $this->toast('Schedule added — first run ' . th_date($row['next_run_at']));
        }

        return $this->back();
    }

    public function updateRecurring(int $id)
    {
        if (! $this->db->table('recurring_tickets')->where('id', $id)->countAllResults()) {
            return $this->back();
        }
        if ($row = $this->recurringPayload()) {
            $this->db->table('recurring_tickets')->where('id', $id)->update($row);
            Audit::log('recurring.updated', '#' . $id);
            $this->toast('Schedule saved — next run ' . th_date($row['next_run_at']));
        }

        return $this->back();
    }

    public function toggleRecurring(int $id)
    {
        $r = $this->db->table('recurring_tickets')->where('id', $id)->get()->getRowArray();
        if ($r) {
            $on = ! (int) $r['active'];
            $upd = ['active' => (int) $on];
            if ($on) {
                // Re-enabling after a gap must not fire every missed run at once.
                $upd['next_run_at'] = TicketIntake::nextRun($r);
            }
            $this->db->table('recurring_tickets')->where('id', $id)->update($upd);
            $this->toast($on ? 'Schedule enabled' : 'Schedule paused', $on ? 'ok' : 'warn');
        }

        return $this->back();
    }

    public function deleteRecurring(int $id)
    {
        $this->db->table('recurring_tickets')->where('id', $id)->delete();
        $this->toast('Schedule removed', 'warn');

        return $this->back();
    }
}
