<?php

namespace App\Controllers;

class DashboardController extends BaseController
{
    public function index()
    {
        $tickets = $this->scopeTickets($this->db->table('tickets')->get()->getResultArray());
        $open    = array_values(array_filter($tickets, 'th_is_open'));

        $unassigned = array_values(array_filter($open, static fn ($t) => ! $t['agent_id']));
        $overdue    = array_values(array_filter($open, static fn ($t) => th_sla($t)['remaining'] <= 0));
        $soon4      = array_values(array_filter($open, static function ($t) {
            $r = th_sla($t)['remaining'];

            return $r > 0 && $r <= 4 * 3600;
        }));
        $resolvedToday = count(array_filter($tickets, static fn ($t) => $t['resolved_at'] && (time() - strtotime($t['resolved_at'])) < 24 * 3600));
        $newToday      = count(array_filter($tickets, static fn ($t) => $t['status'] === 'New'));

        // First-contact resolution: resolved tickets that took a single agent reply.
        $resolvedAll = array_values(array_filter($tickets, static fn ($t) => $t['resolved_at']));
        $fcr = null;
        if ($resolvedAll) {
            $ids = array_column($resolvedAll, 'id');
            $counts = [];
            foreach ($this->db->table('ticket_messages')->whereIn('ticket_id', $ids)->where('kind', 'reply')->get()->getResultArray() as $m) {
                if ($m['user_id'] && $this->isAgentRole($this->userById((int) $m['user_id']))) {
                    $counts[(int) $m['ticket_id']] = ($counts[(int) $m['ticket_id']] ?? 0) + 1;
                }
            }
            $single = count(array_filter($resolvedAll, static fn ($t) => ($counts[(int) $t['id']] ?? 0) === 1));
            $fcr = (int) round($single / count($resolvedAll) * 100);
        }

        // Breach horizon buckets
        $late = $overdue;
        usort($late, static fn ($a, $b) => strtotime($a['res_due']) <=> strtotime($b['res_due']));
        $soon12 = array_values(array_filter($open, static function ($t) {
            $r = th_sla($t)['remaining'];

            return $r > 0 && $r <= 12 * 3600;
        }));
        usort($soon12, static fn ($a, $b) => strtotime($a['res_due']) <=> strtotime($b['res_due']));

        // Volume chart: created / resolved per day, last 7 days
        $volume = [];
        for ($i = 6; $i >= 0; $i--) {
            $dayStart = strtotime(date('Y-m-d 00:00:00', time() - $i * 86400));
            $dayEnd   = $dayStart + 86400;
            $created  = count(array_filter($tickets, static fn ($t) => strtotime($t['created_at']) >= $dayStart && strtotime($t['created_at']) < $dayEnd));
            $resolved = count(array_filter($tickets, static fn ($t) => $t['resolved_at'] && strtotime($t['resolved_at']) >= $dayStart && strtotime($t['resolved_at']) < $dayEnd));
            $volume[] = ['day' => date('D', $dayStart), 'created' => $created, 'resolved' => $resolved];
        }

        // Workload per active agent (scoped to the viewer's group unless admin)
        $workload = [];
        foreach ($this->assignableAgents() as $a) {
            if (! (int) $a['active']) {
                continue;
            }
            $mine = array_values(array_filter($open, static fn ($t) => (int) $t['agent_id'] === (int) $a['id']));
            $lateN = count(array_filter($mine, static fn ($t) => th_sla($t)['remaining'] <= 0));
            $workload[] = ['agent' => $a, 'n' => count($mine), 'late' => $lateN];
        }
        usort($workload, static fn ($x, $y) => $y['n'] <=> $x['n']);

        // CSAT
        $rated = array_values(array_filter($tickets, static fn ($t) => $t['csat_score'] !== null));
        $avg   = $rated ? array_sum(array_column($rated, 'csat_score')) / count($rated) : 0;
        $dist  = [];
        foreach ([5, 4, 3, 2, 1] as $n) {
            $dist[$n] = count(array_filter($rated, static fn ($t) => (int) $t['csat_score'] === $n));
        }

        // Queues
        $mine = array_values(array_filter($open, fn ($t) => (int) $t['agent_id'] === (int) $this->me['id']));
        usort($mine, static fn ($a, $b) => strtotime($a['res_due']) <=> strtotime($b['res_due']));
        usort($unassigned, static fn ($a, $b) => strtotime($a['res_due']) <=> strtotime($b['res_due']));

        $announcements = $this->db->table('announcements')->orderBy('created_at', 'DESC')->get()->getResultArray();

        return view('agent/dashboard', $this->agentShared() + [
            'title' => 'Dashboard', 'nav' => 'dashboard',
            'users' => $this->users(),
            'openTickets' => $open, 'unassigned' => $unassigned, 'overdue' => $late,
            'soon4' => $soon4, 'soon12' => $soon12, 'resolvedToday' => $resolvedToday, 'newToday' => $newToday,
            'fcr' => $fcr,
            'volume' => $volume, 'workload' => $workload,
            'rated' => $rated, 'csatAvg' => $avg, 'csatDist' => $dist,
            // Low scores with a reason are the only ones worth reading back.
            'csatComments' => array_slice(array_values(array_filter(
                $rated,
                static fn ($t) => ! empty($t['csat_comment'])
            )), 0, 5),
            'myQueue' => $mine, 'announcements' => $announcements,
        ]);
    }
}
